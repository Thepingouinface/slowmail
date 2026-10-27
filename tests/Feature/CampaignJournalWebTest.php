<?php

use App\Enums\JournalEvent;
use App\Models\SendLog;

beforeEach(function () {
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

it('affiche les événements de la campagne, les plus récents d\'abord', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'ligne@x.example');
    logEntry($campaign, 'import', '3 importées, 0 rejetées.', at: '2026-10-19 09:00:00');
    logEntry($campaign, 'plan', '2 envoi(s) planifié(s) pour le 20 octobre : 10:12, 14:40.', at: '2026-10-20 09:31:00');
    logEntry($campaign, 'sent', 'Mail envoyé.', $row, at: '2026-10-20 10:12:30');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")
        ->assertOk()
        ->assertSeeInOrder(['Mail envoyé.', '2 envoi(s) planifié(s)', '3 importées'])
        ->assertSee('ligne@x.example')
        ->assertSee('20/10/2026 10:12');
});

it('départage deux événements de la même seconde par ordre d\'écriture', function () {
    $campaign = campaign();

    foreach (['premier', 'deuxième', 'troisième'] as $detail) {
        logEntry($campaign, 'plan', "événement {$detail}", at: '2026-10-20 10:00:00');
    }

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")
        ->assertSeeInOrder(['événement troisième', 'événement deuxième', 'événement premier']);
});

it('nomme chaque événement en français et le colore', function () {
    $campaign = campaign();
    logEntry($campaign, 'circuit_breaker', 'Disjoncteur : 3 échecs.');
    logEntry($campaign, 'sent', 'Mail envoyé.');
    logEntry($campaign, 'retry', 'Plus tard.');
    logEntry($campaign, 'inconnu_un_jour', 'Autre chose.');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")
        ->assertSee('Disjoncteur')
        ->assertSee('Nouvelle tentative')
        ->assertSeeHtml('bg-red-100 text-red-800')
        ->assertSeeHtml('bg-emerald-100 text-emerald-800')
        ->assertSeeHtml('bg-amber-100 text-amber-900')
        // Un événement inconnu s'affiche tel quel.
        ->assertSee('inconnu_un_jour');
});

it('connaît tous les événements du journal listés dans CLAUDE.md', function () {
    $documented = ['plan', 'replan', 'suppressed', 'skipped', 'dry_run', 'sent', 'retry', 'failed', 'bounced', 'uncertain', 'network', 'interruption', 'activated', 'paused', 'circuit_breaker', 'resumed', 'finished', 'resolved', 'reply', 'purge', 'test', 'test_failed', 'import'];

    foreach ($documented as $event) {
        expect(JournalEvent::tryFrom($event))->not->toBeNull("événement sans libellé : {$event}");
    }

    foreach (JournalEvent::cases() as $case) {
        expect($case->label())->not->toBe('')->and(['ok', 'warn', 'error', 'neutral'])->toContain($case->tone());
    }
});

it('pagine par 50, le plus récent en premier', function () {
    $campaign = campaign();

    foreach (range(1, 60) as $i) {
        logEntry($campaign, 'plan', "entrée numéro {$i}.");
    }

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")
        ->assertSee('(60)')
        ->assertSee('entrée numéro 60.')
        ->assertSee('entrée numéro 11.')
        ->assertDontSee('entrée numéro 10.')
        ->assertSeeHtml('page=2');

    $this->get("/campaigns/{$campaign->id}/journal?page=2")->assertSee('entrée numéro 10.')->assertSee('entrée numéro 1.')->assertDontSee('entrée numéro 11.');
});

it('filtre par type d\'événement', function () {
    $campaign = campaign();
    logEntry($campaign, 'sent', 'Un envoi réussi.');
    logEntry($campaign, 'failed', 'Un envoi raté.');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal?event=failed")
        ->assertSee('Un envoi raté.')
        ->assertDontSee('Un envoi réussi.')
        ->assertSee('(1)');
});

it('filtre sur une ligne et propose de revenir au journal entier', function () {
    $campaign = campaign();
    $a = recipient($campaign, 'a@x.example');
    $b = recipient($campaign, 'b@x.example');
    logEntry($campaign, 'sent', 'Envoi à A.', $a);
    logEntry($campaign, 'sent', 'Envoi à B.', $b);
    logEntry($campaign, 'import', 'Import général.');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal?recipient={$a->id}")
        ->assertSee('Envoi à A.')
        ->assertDontSee('Envoi à B.')
        ->assertDontSee('Import général.')
        ->assertSee('tout le journal');
});

it('ne montre que le journal de la campagne demandée', function () {
    $mine = campaign('Ma campagne');
    $other = campaign('Une autre');
    logEntry($mine, 'plan', 'Chez moi.');
    logEntry($other, 'plan', 'Chez les autres.');

    $this->withSession(authenticated())->get("/campaigns/{$mine->id}/journal")->assertSee('Chez moi.')->assertDontSee('Chez les autres.');
});

it('affiche les heures dans le fuseau de la campagne', function () {
    $campaign = campaign();
    $campaign->update(['settings' => ['timezone' => 'America/New_York']]);
    logEntry($campaign, 'plan', 'Une entrée.', at: '2026-10-20 10:12:00');

    // 10:12 à Paris (CEST) = 08:12 UTC = 04:12 à New York (EDT).
    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")->assertSee('20/10/2026 04:12');
});

it('annonce un journal vide', function () {
    $campaign = campaign();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")->assertShows('Rien dans le journal pour l\'instant.');
});

it('masque l\'identifiant SMTP même si une ancienne entrée l\'a gardé', function () {
    config(['mail.mailers.smtp.password' => 'mot-de-passe-smtp-secret']);
    $campaign = campaign();
    logEntry($campaign, 'failed', 'Échec d\'envoi : authentification refusée avec mot-de-passe-smtp-secret');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")
        ->assertDontSee('mot-de-passe-smtp-secret')
        ->assertShows('authentification refusée avec ***');
});

it('échappe le détail d\'une entrée', function () {
    $campaign = campaign();
    logEntry($campaign, 'failed', '<b>gras</b>');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/journal")->assertDontSee('<b>gras</b>', false)->assertSee('&lt;b&gt;gras&lt;/b&gt;', false);
});

it('montre dans le journal ce que les écrans viennent de faire', function () {
    sendingEnvironment();
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");
    $this->post("/campaigns/{$campaign->id}/pause");
    $this->put("/campaigns/{$campaign->id}/settings", settingsForm(['daily_max' => 2]));

    $this->get("/campaigns/{$campaign->id}/journal")
        ->assertSee('Activation')
        ->assertSee('Pause')
        ->assertSee('Réglages')
        ->assertShows('Réglages modifiés : 2 mails par jour');

    expect(SendLog::where('campaign_id', $campaign->id)->count())->toBe(3);
});
