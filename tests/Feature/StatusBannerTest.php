<?php

use App\Enums\RecipientStatus;
use App\Services\Sending\SchedulerState;

beforeEach(function () {
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

function lastTick(string $at): void
{
    app(SchedulerState::class)->recordTick(paris($at)->utc());
}

it('affiche l\'âge et l\'heure exacte du dernier passage du scheduler', function () {
    lastTick('2026-10-21 09:57:05');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertOk()
        ->assertSee('Dernier passage du scheduler : il y a 2 min (mer. 21 oct. à 09:57:05)')
        ->assertDontSee('en retard');
});

it('passe le dernier passage en rouge au-delà de 10 minutes', function () {
    lastTick('2026-10-21 09:50:00');

    // 10 minutes pile : pas encore en retard.
    $this->withSession(authenticated())->get('/campaigns')
        ->assertDontSee('en retard')
        ->assertDontSeeHtml('text-red-700" data-banner="scheduler"');

    lastTick('2026-10-21 09:49:00');

    $this->get('/campaigns')
        ->assertSee('il y a 11 min')
        ->assertSee('en retard')
        ->assertSeeHtml('text-red-700" data-banner="scheduler"');
});

it('dit combien de temps quand le scheduler n\'a pas tourné depuis des heures', function () {
    lastTick('2026-10-21 03:45:00');

    $this->withSession(authenticated())->get('/campaigns')->assertSee('il y a 6 h 15 min');
});

it('signale un scheduler jamais exécuté', function () {
    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('jamais exécuté')
        ->assertSeeHtml('text-red-700" data-banner="scheduler"');
});

it('annonce le prochain envoi prévu', function () {
    lastTick('2026-10-21 09:59:00');
    app(SchedulerState::class)->setNextSendAt(paris('2026-10-21 14:12:00')->utc());

    $this->withSession(authenticated())->get('/campaigns')->assertSee('Prochain envoi prévu : mer. 21 oct. à 14:12');
});

it('dit qu\'aucun envoi n\'est prévu', function () {
    $this->withSession(authenticated())->get('/campaigns')->assertSee('Prochain envoi prévu : aucun');
});

it('compte les lignes incertaines et mène à la campagne concernée', function () {
    $campaign = campaign('Agences');
    uncertainSend($campaign, 'a@exemple.test');
    uncertainSend($campaign, 'b@exemple.test');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('2 envoi(s) à vérifier dans vos envoyés')
        ->assertSeeHtml('href="'.route('campaigns.show', ['campaign' => $campaign->id, 'status' => 'uncertain']).'"');
});

it('mène à la liste quand plusieurs campagnes ont des lignes incertaines', function () {
    uncertainSend(campaign('Une'), 'a@exemple.test');
    uncertainSend(campaign('Deux'), 'b@exemple.test');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('2 envoi(s) à vérifier')
        ->assertSeeHtml('href="'.route('campaigns.index').'" class="font-medium text-amber-800 underline" data-banner="uncertain"');
});

it('n\'affiche rien d\'incertain quand tout est clair', function () {
    recipient(campaign(), 'a@exemple.test', RecipientStatus::Sent);

    $this->withSession(authenticated())->get('/campaigns')->assertDontSeeHtml('data-banner="uncertain"');
});

it('compte les campagnes en pause', function () {
    campaign('Une')->update(['status' => 'paused']);
    campaign('Deux')->update(['status' => 'paused']);
    campaign('Trois')->update(['status' => 'active']);

    $this->withSession(authenticated())->get('/campaigns')->assertSee('2 campagne(s) en pause');
});

it('prévient quand le kill switch est coupé, et pas quand il est ouvert', function () {
    $this->withSession(authenticated())->get('/campaigns')->assertSee('Envois désactivés')->assertSee('SLOWMAIL_ENABLED=false');

    config(['slowmail.enabled' => true]);

    $this->get('/campaigns')->assertDontSee('Envois désactivés');
});

it('montre le bandeau sur chaque écran d\'une campagne', function () {
    lastTick('2026-10-21 09:59:00');
    $campaign = campaign();

    foreach (['', '/template', '/settings', '/journal'] as $suffix) {
        $this->withSession(authenticated())->get("/campaigns/{$campaign->id}{$suffix}")
            ->assertOk()
            ->assertSeeHtml('data-banner="scheduler"')
            ->assertSee('Prochain envoi prévu');
    }

    $this->get('/suppressions')->assertSeeHtml('data-banner="scheduler"');
});

it('ne montre pas l\'état du scheduler sur la page de connexion', function () {
    lastTick('2026-10-21 09:59:00');

    $this->get('/login')->assertOk()->assertDontSee('Dernier passage')->assertDontSeeHtml('data-banner="scheduler"');
});

it('affiche l\'heure du bandeau dans le fuseau d\'affichage configuré', function () {
    config(['slowmail.timezone' => 'America/New_York']);
    lastTick('2026-10-21 09:57:05');

    // 09:57 à Paris = 07:57 UTC = 03:57 à New York.
    $this->withSession(authenticated())->get('/campaigns')->assertSee('03:57:05');

    config(['slowmail.timezone' => 'Nimporte/Quoi']);

    $this->get('/campaigns')->assertSee('09:57:05');
});
