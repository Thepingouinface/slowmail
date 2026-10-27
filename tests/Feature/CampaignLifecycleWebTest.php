<?php

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Sending\CampaignLifecycle;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    sendingEnvironment();
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

function showCampaign(Campaign $campaign, string $page = ''): TestResponse
{
    return test()->get("/campaigns/{$campaign->id}{$page}");
}

it('active une campagne prête depuis l\'écran', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate")->assertRedirect("/campaigns/{$campaign->id}");

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and(journalOf($campaign))->toContain('activated');

    showCampaign($campaign)->assertSee('Campagne activée.')->assertSee('Mettre en pause');
});

it('affiche tels quels les motifs du refus, mention de désinscription comprise', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft', 'body' => 'Bonjour, voici notre offre.']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate")->assertRedirect();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

    $problems = app(CampaignLifecycle::class)->problems($campaign);
    expect($problems)->not->toBeEmpty();

    $response = showCampaign($campaign)->assertSee('La campagne ne peut pas démarrer')->assertSeeHtml('data-lifecycle-problems');

    foreach ($problems as $problem) {
        $response->assertSee($problem);
    }

    $response->assertSee('stop', false);
});

it('refuse d\'activer une campagne sans destinataire, et le dit', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft'], rows: 0);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
    showCampaign($campaign)->assertSee('Aucun destinataire à contacter');
});

it('refuse d\'activer quand les réglages sont invalides, et le dit', function () {
    $campaign = sendingCampaign(['daily_max' => 500], attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
    showCampaign($campaign)->assertSee('Le nombre maximum de mails par jour doit être compris entre 1 et');
});

it('refuse d\'activer sans adresse d\'expédition', function () {
    config(['mail.from.address' => '']);
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");

    showCampaign($campaign)->assertSee('MAIL_FROM_ADDRESS');
});

it('prévient à l\'activation quand le kill switch est coupé', function () {
    config(['slowmail.enabled' => false]);
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active);
    showCampaign($campaign)->assertSee('aucun mail ne partira tant que le kill switch est coupé');
});

it('met une campagne active en pause, et la reprend', function () {
    $campaign = sendingCampaign();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/pause")->assertRedirect();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->fresh()->pause_reason)->toBe('Mise en pause à la main.');

    showCampaign($campaign)->assertSee('Campagne mise en pause.')->assertSee('Reprendre');

    $this->post("/campaigns/{$campaign->id}/resume")->assertRedirect();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($campaign->fresh()->pause_reason)->toBeNull()
        ->and(journalOf($campaign))->toContain('paused', 'resumed');
});

it('revient sur l\'onglet d\'où vient le clic', function () {
    $campaign = sendingCampaign();

    $this->withSession(authenticated())
        ->from("/campaigns/{$campaign->id}/journal")
        ->post("/campaigns/{$campaign->id}/pause")
        ->assertRedirect("/campaigns/{$campaign->id}/journal");
});

it('refuse proprement les actions qui ne vont pas avec l\'état de la campagne', function () {
    $draft = sendingCampaign(attributes: ['status' => 'draft']);
    $active = sendingCampaign(attributes: ['name' => 'Active']);

    $this->withSession(authenticated())->post("/campaigns/{$draft->id}/pause");
    showCampaign($draft)->assertSee('Seule une campagne active peut être mise en pause.');

    $this->post("/campaigns/{$active->id}/resume");
    showCampaign($active)->assertSee('Seule une campagne en pause peut être reprise.');

    $this->post("/campaigns/{$active->id}/activate");
    showCampaign($active)->assertSee('La campagne est déjà active.');

    expect($draft->fresh()->status)->toBe(CampaignStatus::Draft)->and($active->fresh()->status)->toBe(CampaignStatus::Active);
});

it('ne propose que le bouton qui convient à l\'état', function () {
    $draft = sendingCampaign(attributes: ['status' => 'draft']);
    $active = sendingCampaign(attributes: ['name' => 'A']);
    $paused = sendingCampaign(attributes: ['name' => 'B', 'status' => 'paused']);
    $finished = sendingCampaign(attributes: ['name' => 'C', 'status' => 'finished']);

    $this->withSession(authenticated());
    showCampaign($draft)->assertSee('>Activer<', false)->assertDontSee('Mettre en pause')->assertDontSee('>Reprendre<', false);
    showCampaign($active)->assertSee('Mettre en pause')->assertDontSee('>Activer<', false)->assertDontSee('>Reprendre<', false);
    showCampaign($paused)->assertSee('>Reprendre<', false)->assertDontSee('>Activer<', false);
    showCampaign($finished)->assertDontSee('>Activer<', false)->assertDontSee('Mettre en pause')->assertDontSee('>Reprendre<', false);
});

it('demande confirmation avant d\'activer, en disant si de vrais mails vont partir', function () {
    $real = sendingCampaign(attributes: ['status' => 'draft']);
    $simulated = sendingCampaign(['dry_run' => true], attributes: ['name' => 'Simulée', 'status' => 'draft']);

    $this->withSession(authenticated());
    showCampaign($real)->assertSeeHtml('data-confirm="Activer la campagne ? De vrais mails partiront')->assertDontSee('Simulation');
    showCampaign($simulated)->assertSeeHtml('data-confirm="Activer la campagne en simulation ? Aucun mail ne partira')->assertSee('Simulation');
});

it('affiche la raison de la pause et les échecs consécutifs après le disjoncteur', function () {
    $campaign = sendingCampaign(attributes: [
        'status' => 'paused',
        'consecutive_failures' => 3,
        'pause_reason' => 'Disjoncteur : 3 échecs d\'envoi consécutifs. Dernière erreur : Authentication failed.',
    ]);

    $this->withSession(authenticated());
    showCampaign($campaign)
        ->assertSeeHtml('data-pause-reason')
        ->assertSee('Disjoncteur : la campagne s\'est mise en pause toute seule.')
        ->assertSee('3 échecs d\'envoi consécutifs')
        ->assertSee('Authentication failed.')
        ->assertSee('Échecs d\'envoi consécutifs : 3 (la pause se déclenche à 3)', false);

    // Même bandeau sur les autres onglets.
    showCampaign($campaign, '/journal')->assertSeeHtml('data-pause-reason');
});

it('présente une pause voulue sans l\'alarme du disjoncteur', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'pause_reason' => 'Mise en pause à la main.']);

    $this->withSession(authenticated());
    showCampaign($campaign)->assertSee('Mise en pause à la main.')->assertDontSee('Disjoncteur');
});

it('annonce les échecs en cours sur une campagne encore active', function () {
    $campaign = sendingCampaign(attributes: ['consecutive_failures' => 2]);

    $this->withSession(authenticated());
    showCampaign($campaign)->assertSeeHtml('data-failures')->assertSee('2 sur 3 avant la pause automatique');
});

it('affiche le récapitulatif des réglages sous le titre', function () {
    $campaign = sendingCampaign(['daily_max' => 2]);

    $this->withSession(authenticated());
    showCampaign($campaign)->assertSee('2 mails par jour, du lundi au vendredi, entre 9 h 30 et 17 h 30 (Europe/Paris)');
});

it('masque l\'identifiant SMTP dans une raison de pause', function () {
    config(['mail.mailers.smtp.password' => 'mot-de-passe-smtp-secret']);
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'pause_reason' => 'Dernière erreur : mot-de-passe-smtp-secret refusé']);

    $this->withSession(authenticated());
    showCampaign($campaign)->assertDontSee('mot-de-passe-smtp-secret')->assertSee('Dernière erreur : *** refusé');
});

it('ne démarre pas d\'envoi par le simple fait d\'activer : c\'est le tick qui envoie', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");

    expect(sentMail())->toHaveCount(0)->and(Recipient::where('status', 'sent')->count())->toBe(0);
});
