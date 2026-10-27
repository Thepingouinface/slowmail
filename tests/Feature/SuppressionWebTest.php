<?php

use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Suppression;
use App\Services\Sending\Suppressions;

beforeEach(function () {
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

it('liste la liste de suppression avec motif et date', function () {
    app(Suppressions::class)->add('jean@exemple.fr', SuppressionReason::Stop);
    app(Suppressions::class)->add('@agence.example', SuppressionReason::Manual);
    app(Suppressions::class)->add('rebond@exemple.fr', SuppressionReason::Bounce);

    $this->withSession(authenticated())->get('/suppressions')
        ->assertOk()
        ->assertSee('jean@exemple.fr')
        ->assertSee('@agence.example')
        ->assertSee('Domaine')
        ->assertSee('Adresse')
        ->assertSee('rebond')
        ->assertSee('21/10/2026 10:00')
        ->assertSee('(3)');
});

it('ajoute une adresse et retire aussitôt ses lignes en attente', function () {
    $campaign = campaign();
    $waiting = recipient($campaign, 'jean@exemple.fr');
    $sent = recipient($campaign, 'deja@exemple.fr', RecipientStatus::Sent);

    $this->withSession(authenticated())->post('/suppressions', ['value' => '  Jean@Exemple.fr ', 'reason' => 'manuel'])->assertRedirect('/suppressions');

    $suppression = Suppression::sole();
    expect($suppression->value)->toBe('jean@exemple.fr')
        ->and($suppression->type)->toBe(SuppressionType::Email)
        ->and($suppression->reason)->toBe(SuppressionReason::Manual)
        ->and($waiting->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($sent->fresh()->status)->toBe(RecipientStatus::Sent);

    $this->get('/suppressions')->assertShows('jean@exemple.fr ajouté à la liste de suppression. 1 ligne(s) en attente ou planifiée(s) retirée(s) de l\'envoi.');
});

it('ajoute un domaine entier', function () {
    $campaign = campaign();
    $a = recipient($campaign, 'a@reseau.example');
    $b = recipient($campaign, 'b@reseau.example');
    $c = recipient($campaign, 'c@autre.example');

    $this->withSession(authenticated())->post('/suppressions', ['value' => '@reseau.example', 'reason' => 'stop']);

    $suppression = Suppression::sole();
    expect($suppression->type)->toBe(SuppressionType::Domain)
        ->and($suppression->value)->toBe('reseau.example')
        ->and($a->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($b->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($c->fresh()->status)->toBe(RecipientStatus::Pending);

    $this->get('/suppressions')->assertSee('@reseau.example');
});

it('signale une adresse déjà dans la liste, sans doublon', function () {
    app(Suppressions::class)->add('jean@exemple.fr', SuppressionReason::Bounce);

    $this->withSession(authenticated())->post('/suppressions', ['value' => 'jean@exemple.fr', 'reason' => 'stop']);

    expect(Suppression::count())->toBe(1);
    $this->get('/suppressions')->assertShows('jean@exemple.fr était déjà dans la liste de suppression (rebond).');
});

it('refuse une valeur qui n\'est ni une adresse ni un domaine', function () {
    $this->withSession(authenticated())->post('/suppressions', ['value' => 'pas une adresse', 'reason' => 'stop'])->assertRedirect('/suppressions');

    expect(Suppression::count())->toBe(0);

    $this->get('/suppressions')->assertShows('« pas une adresse » n\'est ni une adresse valide ni un domaine')->assertSeeHtml('value="pas une adresse"');
});

it('refuse une adresse avec des accents, comme à l\'import', function () {
    $this->withSession(authenticated())->post('/suppressions', ['value' => 'élodie@exemple.fr', 'reason' => 'stop']);

    expect(Suppression::count())->toBe(0);
});

it('valide le formulaire, en français', function () {
    $this->withSession(authenticated())->post('/suppressions', ['value' => '', 'reason' => 'inconnu'])
        ->assertSessionHasErrors([
            'value' => 'Le champ adresse ou domaine est obligatoire.',
            'reason' => 'La valeur choisie pour le champ motif n\'est pas valable.',
        ]);

    $this->post('/suppressions', ['value' => str_repeat('a', 256), 'reason' => 'stop'])
        ->assertSessionHasErrors(['value' => 'Le champ adresse ou domaine ne doit pas dépasser 255 caractères.']);

    expect(Suppression::count())->toBe(0);
});

it('cherche et filtre par motif', function () {
    app(Suppressions::class)->add('jean@exemple.fr', SuppressionReason::Stop);
    app(Suppressions::class)->add('marie@exemple.fr', SuppressionReason::Bounce);
    app(Suppressions::class)->add('@agence.example', SuppressionReason::Stop);

    $this->withSession(authenticated())->get('/suppressions?q=marie')->assertSee('marie@exemple.fr')->assertDontSee('jean@exemple.fr');
    $this->get('/suppressions?reason=stop')->assertSee('jean@exemple.fr')->assertSee('@agence.example')->assertDontSee('marie@exemple.fr');
    $this->get('/suppressions?q=@agence')->assertSee('@agence.example')->assertDontSee('jean@exemple.fr');
    $this->get('/suppressions?q=introuvable')->assertSee('Rien ne correspond.');
});

it('pagine par 50, la plus récente en premier', function () {
    foreach (range(1, 55) as $i) {
        Suppression::create(['value' => "p{$i}@exemple.fr", 'type' => 'email', 'reason' => 'stop']);
    }

    $this->withSession(authenticated())->get('/suppressions')->assertSee('p55@exemple.fr')->assertDontSee('p5@exemple.fr')->assertSeeHtml('page=2');
});

it('n\'offre aucun moyen de retirer une adresse depuis l\'interface', function () {
    app(Suppressions::class)->add('jean@exemple.fr');

    $this->withSession(authenticated())->get('/suppressions')->assertDontSee('Retirer')->assertDontSee('Supprimer de la liste')->assertSee('volontairement pas un bouton');

    $this->delete('/suppressions')->assertStatus(405);
    expect(Suppression::count())->toBe(1);
});

it('lie bien la liste au menu principal', function () {
    $this->withSession(authenticated())->get('/campaigns')->assertSee('Liste de suppression')->assertSeeHtml('href="'.route('suppressions.index').'"');
});
