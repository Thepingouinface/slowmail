<?php

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Planning\CampaignSettings;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    sendingEnvironment();
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

function putSettings(Campaign $campaign, array $overrides = [], bool $keep = false): TestResponse
{
    $request = $keep ? test() : test()->withSession(authenticated());

    return $request->put("/campaigns/{$campaign->id}/settings", settingsForm($overrides));
}

it('affiche le formulaire avec les réglages enregistrés', function () {
    $campaign = sendingCampaign(['daily_max' => 3, 'window_start' => '08:15', 'min_gap_minutes' => 40, 'domain_cooldown_days' => 10, 'max_total' => 50, 'days' => [1, 3]], attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings")
        ->assertOk()
        ->assertSee('Réglages')
        ->assertSeeHtml('name="daily_max" type="number" min="1" max="100" required value="3"')
        ->assertSeeHtml('value="08:15"')
        ->assertSeeHtml('name="min_gap_minutes" type="number" min="0" max="720" required value="40"')
        ->assertSeeHtml('name="domain_cooldown_days" type="number" min="0" max="365" required value="10"')
        ->assertSeeHtml('name="max_total" type="number" min="1" value="50"')
        ->assertSeeHtml('name="days[]" value="1" checked')
        ->assertSeeHtml('name="days[]" value="3" checked')
        ->assertDontSeeHtml('name="days[]" value="2" checked');
});

it('propose chaque réglage de la spec', function () {
    $campaign = sendingCampaign();

    $page = $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings");

    foreach (['daily_max', 'window_start', 'window_end', 'timezone', 'days[]', 'skip_holidays', 'min_gap_minutes', 'jitter', 'start_at', 'end_at', 'max_total', 'ramp_up', 'ramp_start', 'ramp_step', 'ramp_every_days', 'domain_cooldown_days', 'dry_run'] as $name) {
        $page->assertSeeHtml('name="'.$name.'"');
    }
});

it('affiche le récapitulatif en clair avec la fin estimée', function () {
    $campaign = sendingCampaign(['daily_max' => 2], rows: 4);

    // 4 lignes à 2 par jour : mercredi 21 (le plan du jour reste à tirer) et jeudi 22.
    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings")
        ->assertSeeHtml('data-recap-summary')
        ->assertSee('2 mails par jour, du lundi au vendredi, entre 9 h 30 et 17 h 30 (Europe/Paris), jours fériés exclus')
        ->assertSee('Fin estimée')
        ->assertSee('jeudi 22 octobre 2026, au rythme actuel')
        ->assertShows('Prochains jours d\'envoi');
});

it('enregistre les réglages dans campaigns.settings et dans les colonnes de dates', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, [
        'daily_max' => 6,
        'window_start' => '08:00',
        'window_end' => '18:00',
        'days' => [1, 2, 3],
        'skip_holidays' => null,
        'min_gap_minutes' => 30,
        'jitter' => null,
        'max_total' => 40,
        'ramp_start' => 1,
        'ramp_step' => 2,
        'ramp_every_days' => 4,
        'domain_cooldown_days' => 14,
        'start_at' => '2026-11-02T09:00',
        'end_at' => '2026-12-18T17:30',
    ])->assertRedirect("/campaigns/{$campaign->id}/settings");

    $campaign = $campaign->fresh();
    $settings = CampaignSettings::for($campaign);

    expect($campaign->settings)->toMatchArray([
        'daily_max' => 6,
        'window_start' => '08:00',
        'window_end' => '18:00',
        'timezone' => 'Europe/Paris',
        'days' => [1, 2, 3],
        'skip_holidays' => false,
        'min_gap_minutes' => 30,
        'jitter' => false,
        'max_total' => 40,
        'ramp_up' => false,
        'ramp_start' => 1,
        'ramp_step' => 2,
        'ramp_every_days' => 4,
        'domain_cooldown_days' => 14,
        'dry_run' => false,
    ])->and($settings->violations())->toBe([])
        // Saisies à l'heure de Paris, stockées en UTC (CET : une heure de moins).
        ->and($campaign->start_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 08:00')
        ->and($campaign->end_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-18 16:30')
        ->and(journalOf($campaign))->toContain('settings');

    $this->get("/campaigns/{$campaign->id}/settings")
        ->assertSee('Réglages enregistrés.')
        ->assertSee('6 mails par jour, du lundi au mercredi, entre 8 h et 18 h (Europe/Paris)')
        ->assertSeeHtml('value="2026-11-02T09:00"')
        ->assertSeeHtml('value="2026-12-18T17:30"');
});

it('lit les dates dans le fuseau saisi dans le formulaire', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['timezone' => 'America/New_York', 'start_at' => '2026-11-02T09:00']);

    // Novembre 2026, New York : UTC-5.
    expect($campaign->fresh()->start_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 14:00')
        ->and($campaign->fresh()->timezone())->toBe('America/New_York');
});

it('efface une date quand le champ est vidé', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft', 'start_at' => paris('2026-11-02 09:00')->utc(), 'end_at' => paris('2026-12-01 09:00')->utc()]);

    putSettings($campaign, ['start_at' => '', 'end_at' => '']);

    expect($campaign->fresh()->start_at)->toBeNull()->and($campaign->fresh()->end_at)->toBeNull();
});

it('active la montée progressive', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['ramp_up' => '1']);

    expect(CampaignSettings::for($campaign->fresh())->rampUp)->toBeTrue();
});

it('refuse des réglages inutilisables, affiche les raisons et n\'enregistre rien', function () {
    $campaign = sendingCampaign(['daily_max' => 2], attributes: ['status' => 'draft']);

    putSettings($campaign, ['window_start' => '17:00', 'window_end' => '09:00', 'days' => [], 'min_gap_minutes' => 9999])->assertRedirect("/campaigns/{$campaign->id}/settings");

    $this->get("/campaigns/{$campaign->id}/settings")
        ->assertSeeHtml('data-settings-errors')
        ->assertShows('Les réglages n\'ont pas été enregistrés')
        ->assertShows('La fin de la plage horaire doit être après son début.')
        ->assertShows('Choisissez au moins un jour d\'envoi.')
        ->assertShows('L\'écart minimum entre deux mails doit être compris entre 0 et 720 minutes.')
        // Ce que l'utilisateur a saisi n'est pas perdu.
        ->assertSeeHtml('name="min_gap_minutes" type="number" min="0" max="720" required value="9999"')
        ->assertSeeHtml('name="window_start" type="time" required value="17:00"');

    expect(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(2)
        ->and(journalOf($campaign))->not->toContain('settings');
});

it('refuse une plage trop courte pour le nombre de mails demandé', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['window_start' => '10:00', 'window_end' => '10:30', 'daily_max' => 4, 'min_gap_minutes' => 25]);

    $this->get("/campaigns/{$campaign->id}/settings")->assertShows('La plage de 0 h 30 ne permet que');
});

it('refuse un fuseau horaire inconnu et une fin avant le début', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['timezone' => 'Mars/Olympus']);
    $this->get("/campaigns/{$campaign->id}/settings")->assertSee('Le fuseau horaire « Mars/Olympus » est inconnu.');

    putSettings($campaign, ['start_at' => '2026-12-01T09:00', 'end_at' => '2026-11-01T09:00'], keep: true);
    $this->get("/campaigns/{$campaign->id}/settings")->assertSee('La date de fin doit être après la date de début.');
});

it('vérifie la forme des champs, en français', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['daily_max' => 'beaucoup', 'min_gap_minutes' => '2.5', 'start_at' => 'demain', 'days' => 'lundi'])
        ->assertSessionHasErrors([
            'daily_max' => 'Le champ nombre de mails par jour doit être un nombre entier.',
            'min_gap_minutes' => 'Le champ écart minimum doit être un nombre entier.',
            'start_at' => 'Le champ date de début doit être une date au format Y-m-d\TH:i.',
            'days' => 'Le champ jours autorisés doit être une liste.',
        ]);

    putSettings($campaign, ['daily_max' => ''], keep: true)->assertSessionHasErrors(['daily_max' => 'Le champ nombre de mails par jour est obligatoire.']);
});

it('ne touche à rien quand les réglages sont inchangés', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused']);
    $row = dueRow($campaign);

    putSettings($campaign);

    $this->get("/campaigns/{$campaign->id}/settings")->assertShows('Aucun changement');
    expect(journalOf($campaign))->not->toContain('settings')
        ->and($row->fresh()->status)->toBe(RecipientStatus::Planned);
});

it('remet en attente le plan du jour d\'une campagne active dont les réglages changent', function () {
    $campaign = sendingCampaign(['daily_max' => 4]);
    $row = dueRow($campaign, '2026-10-21 15:00:13');
    expect($campaign->fresh()->planned_for)->not->toBeNull();

    putSettings($campaign, ['daily_max' => 2]);

    expect($row->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($row->fresh()->planned_at)->toBeNull()
        ->and($campaign->fresh()->planned_for)->toBeNull()
        ->and(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(2)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);

    $this->get("/campaigns/{$campaign->id}/settings")->assertSee('1 créneau(x) du jour remis en attente');
});

it('remet aussi en attente le plan d\'une campagne en pause', function () {
    $campaign = sendingCampaign(['daily_max' => 4], attributes: ['status' => 'paused']);
    $row = dueRow($campaign, '2026-10-21 15:00:13');

    putSettings($campaign, ['daily_max' => 3]);

    expect($row->fresh()->status)->toBe(RecipientStatus::Pending);
});

it('laisse intacts les créneaux d\'un brouillon et les lignes déjà parties', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);
    $sent = recipient($campaign, 'parti@exemple.test', RecipientStatus::Sent);

    putSettings($campaign, ['daily_max' => 3]);

    expect($sent->fresh()->status)->toBe(RecipientStatus::Sent);
});

it('verrouille les réglages d\'une campagne terminée', function () {
    $campaign = sendingCampaign(['daily_max' => 2], attributes: ['status' => 'finished']);

    putSettings($campaign, ['daily_max' => 9])->assertRedirect();

    expect(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(2);

    $this->get("/campaigns/{$campaign->id}/settings")
        ->assertSee('La campagne est terminée : ses réglages ne changent plus.')
        ->assertDontSee('Enregistrer les réglages');
});

// --- Mode simulation (dry_run) : la décision documentée -------------------------------------------

it('refuse de changer le mode simulation d\'une campagne active', function () {
    $campaign = sendingCampaign(['dry_run' => true]);

    // Décocher la simulation sur une campagne active lancerait de vrais envois : refusé.
    putSettings($campaign, ['dry_run' => null, 'daily_max' => 9])->assertRedirect();

    expect(CampaignSettings::for($campaign->fresh())->dryRun)->toBeTrue()
        ->and(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(4);

    $this->get("/campaigns/{$campaign->id}/settings")->assertShows('Pour changer le mode simulation, mettez d\'abord la campagne en pause');

    // Dans l'autre sens aussi.
    $real = sendingCampaign(attributes: ['name' => 'Réelle']);
    putSettings($real, ['dry_run' => '1'], keep: true);

    expect(CampaignSettings::for($real->fresh())->dryRun)->toBeFalse();
});

it('accepte un autre réglage sur une campagne active en simulation tant que le mode ne change pas', function () {
    $campaign = sendingCampaign(['dry_run' => true]);

    // Le formulaire d'une campagne active renvoie le mode actuel dans un champ caché : « 1 », ici.
    putSettings($campaign, ['dry_run' => '1', 'daily_max' => 3]);

    expect(CampaignSettings::for($campaign->fresh())->dryRun)->toBeTrue()
        ->and(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(3);

    // Un « 0 » demanderait de couper la simulation : refusé, et rien d'autre n'est enregistré avec.
    putSettings($campaign, ['dry_run' => '0', 'daily_max' => 2], keep: true);

    expect(CampaignSettings::for($campaign->fresh())->dryRun)->toBeTrue()
        ->and(CampaignSettings::for($campaign->fresh())->dailyMax)->toBe(3);
});

it('envoie la valeur actuelle de la simulation dans un champ caché pour une campagne active', function () {
    $campaign = sendingCampaign(['dry_run' => true]);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings")
        ->assertSeeHtml('<input type="hidden" name="dry_run" value="1">')
        ->assertShows('Pour changer de mode, mettez d\'abord la campagne en pause');
});

it('laisse changer le mode simulation d\'une campagne en brouillon ou en pause, et le journalise', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    putSettings($campaign, ['dry_run' => '1']);
    expect(CampaignSettings::for($campaign->fresh())->dryRun)->toBeTrue();

    $campaign->update(['status' => 'paused']);
    putSettings($campaign, ['dry_run' => null], keep: true);
    expect(CampaignSettings::for($campaign->fresh())->dryRun)->toBeFalse();

    $details = SendLog::where('campaign_id', $campaign->id)->where('event', 'settings')->orderBy('id')->pluck('detail');
    expect($details[0])->toContain('Mode simulation activé : aucun mail ne partira.')
        ->and($details[1])->toContain('Mode simulation coupé : les mails partiront pour de vrai.');
});

it('laisse les lignes simulées simulées quand on coupe la simulation', function () {
    $campaign = sendingCampaign(['dry_run' => true], attributes: ['status' => 'paused']);
    $simulated = recipient($campaign, 'simule@exemple.test', RecipientStatus::DryRun);
    $simulated->forceFill(['sent_at' => now(), 'rendered_subject' => 'Objet', 'rendered_body' => 'Corps'])->save();

    putSettings($campaign, ['dry_run' => null]);

    expect($simulated->fresh()->status)->toBe(RecipientStatus::DryRun);

    $this->get("/campaigns/{$campaign->id}/settings")
        ->assertSeeHtml('data-simulated')
        ->assertSee('1 ligne(s) au statut « Simulé »')
        ->assertSee('Remettre en attente');
});

it('remet les lignes simulées en attente à la demande, sans rendu ni date', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused']);
    $simulated = recipient($campaign, 'simule@exemple.test', RecipientStatus::DryRun);
    $simulated->forceFill(['sent_at' => now(), 'planned_at' => now(), 'rendered_subject' => 'Objet', 'rendered_body' => 'Corps', 'attempts' => 1])->save();
    $sent = recipient($campaign, 'reel@exemple.test', RecipientStatus::Sent);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/settings/release-simulated")->assertRedirect("/campaigns/{$campaign->id}/settings");

    $simulated = $simulated->fresh();
    expect($simulated->status)->toBe(RecipientStatus::Pending)
        ->and($simulated->sent_at)->toBeNull()
        ->and($simulated->planned_at)->toBeNull()
        ->and($simulated->rendered_subject)->toBeNull()
        ->and($simulated->rendered_body)->toBeNull()
        ->and($simulated->attempts)->toBe(0)
        ->and($sent->fresh()->status)->toBe(RecipientStatus::Sent)
        ->and(journalOf($campaign))->toContain('simulation_reset');

    $this->get("/campaigns/{$campaign->id}/settings")->assertSee('1 ligne(s) simulée(s) remise(s) en attente.');
});

it('refuse de remettre les lignes simulées en attente sur une campagne active ou terminée', function () {
    $active = sendingCampaign(['dry_run' => true]);
    $simulated = recipient($active, 'simule@exemple.test', RecipientStatus::DryRun);

    $this->withSession(authenticated())->post("/campaigns/{$active->id}/settings/release-simulated");
    $this->get("/campaigns/{$active->id}/settings")->assertShows('Mettez d\'abord la campagne en pause');
    expect($simulated->fresh()->status)->toBe(RecipientStatus::DryRun);

    $finished = sendingCampaign(attributes: ['name' => 'Finie', 'status' => 'finished']);
    $other = recipient($finished, 'autre@exemple.test', RecipientStatus::DryRun);

    $this->post("/campaigns/{$finished->id}/settings/release-simulated");
    $this->get("/campaigns/{$finished->id}/settings")->assertSee('La campagne est terminée');
    expect($other->fresh()->status)->toBe(RecipientStatus::DryRun);
});

it('n\'offre pas le bouton de remise en attente sur une campagne active', function () {
    $campaign = sendingCampaign(['dry_run' => true]);
    recipient($campaign, 'simule@exemple.test', RecipientStatus::DryRun);

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings")
        ->assertShows('Mettez la campagne en pause pour pouvoir les remettre en attente')
        ->assertDontSeeHtml('release-simulated');
});

it('ne remet que les lignes simulées de la campagne', function () {
    $mine = sendingCampaign(attributes: ['status' => 'paused']);
    $other = sendingCampaign(attributes: ['name' => 'Autre', 'status' => 'paused']);
    recipient($mine, 'a@exemple.test', RecipientStatus::DryRun);
    $foreign = recipient($other, 'b@exemple.test', RecipientStatus::DryRun);

    $this->withSession(authenticated())->post("/campaigns/{$mine->id}/settings/release-simulated");

    expect($foreign->fresh()->status)->toBe(RecipientStatus::DryRun);
    expect(Recipient::where('campaign_id', $mine->id)->where('status', 'pending')->count())->toBeGreaterThanOrEqual(1);
});
