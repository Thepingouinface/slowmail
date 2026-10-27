<?php

use App\Enums\RecipientStatus;

it('importe un CSV dans la campagne désignée par son nom', function () {
    $campaign = campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => csvFile("EMAIL;NOM\na@x.example;A\nb@x.example;B\n")])
        ->expectsOutputToContain('2 ligne(s) importée(s)')
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(2);
});

it('désigne la campagne par son identifiant', function () {
    $campaign = campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => (string) $campaign->id, 'fichier' => csvFile("EMAIL\na@x.example\n")])
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(1);
});

it('affiche le rapport avec les doublons et les rejets', function () {
    campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => csvFile("EMAIL\na@x.example\na@x.example\nnope\n")])
        ->expectsOutputToContain('doublon dans le fichier')
        ->expectsOutputToContain('adresse invalide')
        ->assertExitCode(0);
});

it('n\'importe rien avec --check', function () {
    $campaign = campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => csvFile("EMAIL\na@x.example\n"), '--check' => true])
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(0);
});

it('ignore les adresses déjà contactées sans --include-contacted en mode non interactif', function () {
    recipient(campaign('Ancienne'), 'vu@x.example', RecipientStatus::Sent);
    $campaign = campaign('Nouvelle');

    $this->artisan('slowmail:import', ['campagne' => 'Nouvelle', 'fichier' => csvFile("EMAIL\nvu@x.example\nneuf@x.example\n"), '--no-interaction' => true])
        ->expectsOutputToContain('déjà contactée(s) ignorée(s)')
        ->assertExitCode(0);

    expect($campaign->recipients()->pluck('email')->all())->toBe(['neuf@x.example']);
});

it('demande confirmation pour les adresses déjà contactées en mode interactif', function () {
    recipient(campaign('Ancienne'), 'vu@x.example', RecipientStatus::Sent);
    $campaign = campaign('Nouvelle');

    $this->artisan('slowmail:import', ['campagne' => 'Nouvelle', 'fichier' => csvFile("EMAIL\nvu@x.example\nneuf@x.example\n")])
        ->expectsConfirmation('Importer aussi les 1 adresses déjà contactées ?', 'yes')
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(2);
});

it('importe les adresses déjà contactées avec --include-contacted', function () {
    recipient(campaign('Ancienne'), 'vu@x.example', RecipientStatus::Sent);
    $campaign = campaign('Nouvelle');

    $this->artisan('slowmail:import', ['campagne' => 'Nouvelle', 'fichier' => csvFile("EMAIL\nvu@x.example\n"), '--include-contacted' => true])
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(1);
});

it('accepte --email-column', function () {
    $campaign = campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => csvFile("Courriel\na@x.example\n"), '--email-column' => 'Courriel'])
        ->assertExitCode(0);

    expect($campaign->recipients()->count())->toBe(1);
});

it('échoue sur une campagne inconnue', function () {
    $this->artisan('slowmail:import', ['campagne' => 'Fantôme', 'fichier' => csvFile("EMAIL\na@x.example\n")])
        ->expectsOutputToContain('Campagne introuvable')
        ->assertExitCode(1);
});

it('échoue quand deux campagnes portent le même nom', function () {
    campaign('Doublon');
    campaign('Doublon');

    $this->artisan('slowmail:import', ['campagne' => 'Doublon', 'fichier' => csvFile("EMAIL\na@x.example\n")])
        ->expectsOutputToContain('Plusieurs campagnes')
        ->assertExitCode(1);
});

it('échoue sur un fichier introuvable', function () {
    campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => '/chemin/inexistant.csv'])
        ->expectsOutputToContain('Fichier introuvable')
        ->assertExitCode(1);
});

it('échoue avec un message clair quand la colonne des adresses manque', function () {
    $campaign = campaign('Ma campagne');

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => csvFile("MAIL;NOM\na@x.example;A\n")])
        ->expectsOutputToContain('introuvable')
        ->assertExitCode(1);

    expect($campaign->recipients()->count())->toBe(0);
});

it('ne supprime pas le fichier de l\'utilisateur', function () {
    campaign('Ma campagne');
    $path = csvFile("EMAIL\na@x.example\n");

    $this->artisan('slowmail:import', ['campagne' => 'Ma campagne', 'fichier' => $path])->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();
});
