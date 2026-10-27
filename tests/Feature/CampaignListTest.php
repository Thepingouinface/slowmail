<?php

use App\Enums\RecipientStatus;

beforeEach(function () {
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

it('liste les campagnes avec leur statut et leur avancement', function () {
    $campaign = campaign('Agences Lyon');
    recipient($campaign, 'a@x.example', RecipientStatus::Sent);
    recipient($campaign, 'b@x.example', RecipientStatus::Sent);
    recipient($campaign, 'c@x.example');
    recipient($campaign, 'd@x.example');
    campaign('Brouillon vide');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertOk()
        ->assertSee('Agences Lyon')
        ->assertSee('2 / 4')
        ->assertSeeHtml('aria-valuenow="50"')
        ->assertSee('Brouillon')
        ->assertSee('0 / 0');
});

it('distingue les lignes simulées des envois réels dans l\'avancement', function () {
    $campaign = campaign('Essai');
    recipient($campaign, 'a@x.example', RecipientStatus::DryRun);
    recipient($campaign, 'b@x.example', RecipientStatus::DryRun);
    recipient($campaign, 'c@x.example', RecipientStatus::Sent);
    recipient($campaign, 'd@x.example');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('1 / 4')
        ->assertSeeHtml('data-simulated')
        ->assertShows('+ 2 simulé(s)')
        ->assertSeeHtml('aria-valuenow="25"');
});

it('n\'ajoute rien à l\'avancement d\'une campagne sans ligne simulée', function () {
    recipient(campaign('Réelle'), 'a@x.example', RecipientStatus::Sent);

    $this->withSession(authenticated())->get('/campaigns')->assertDontSeeHtml('data-simulated');
});

it('classe les campagnes de la plus récente à la plus ancienne', function () {
    campaign('Première');
    campaign('Seconde');

    $this->withSession(authenticated())->get('/campaigns')->assertSeeInOrder(['Seconde', 'Première']);
});

it('annonce l\'heure exacte du prochain envoi quand le plan du jour est tiré', function () {
    $campaign = sendingCampaign();
    dueRow($campaign, '2026-10-21 14:00:13');

    $this->withSession(authenticated())->get('/campaigns')->assertSee('mer. 21 oct. à 14:00');
});

it('annonce le jour du prochain envoi quand les heures ne sont pas encore tirées', function () {
    sendingCampaign();

    $this->withSession(authenticated())->get('/campaigns')->assertSee('mer. 21 oct. (heure tirée le jour même)');
});

it('ne prévoit rien pour une campagne en brouillon, en pause ou terminée', function () {
    sendingCampaign(attributes: ['name' => 'Brouillon', 'status' => 'draft']);
    sendingCampaign(attributes: ['name' => 'Pausée', 'status' => 'paused', 'pause_reason' => 'Mise en pause à la main.']);
    sendingCampaign(attributes: ['name' => 'Finie', 'status' => 'finished']);

    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('pas encore activée')
        ->assertSee('en pause')
        ->assertSee('Mise en pause à la main.')
        ->assertDontSee('heure tirée le jour même');
});

it('dit quand les réglages d\'une campagne active la bloquent', function () {
    sendingCampaign(['daily_max' => 0]);

    $this->withSession(authenticated())->get('/campaigns')->assertSee('Réglages invalides : rien ne partira');
});

it('signale les lignes à vérifier et le mode simulation', function () {
    $campaign = sendingCampaign(['dry_run' => true], rows: 1);
    uncertainSend($campaign);

    $this->withSession(authenticated())->get('/campaigns')
        ->assertSee('1 à vérifier')
        ->assertSee('Simulation');
});

it('n\'affiche pas l\'identifiant SMTP si une raison de pause l\'a gardé', function () {
    config(['mail.mailers.smtp.password' => 'mot-de-passe-smtp-secret']);
    sendingCampaign(attributes: ['status' => 'paused', 'pause_reason' => 'Échec avec mot-de-passe-smtp-secret en clair']);

    $this->withSession(authenticated())->get('/campaigns')
        ->assertDontSee('mot-de-passe-smtp-secret')
        ->assertSee('***');
});
