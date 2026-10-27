<?php

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;

it('crée une campagne en brouillon', function () {
    $this->withSession(authenticated())->post('/campaigns', ['name' => '  Agences Lyon  '])->assertRedirect();

    $campaign = Campaign::sole();

    expect($campaign->name)->toBe('Agences Lyon')->and($campaign->status)->toBe(CampaignStatus::Draft);
});

it('exige un nom', function () {
    $this->withSession(authenticated())->post('/campaigns', ['name' => ''])->assertSessionHasErrors('name');

    expect(Campaign::count())->toBe(0);
});

it('liste les campagnes avec leur avancement', function () {
    $campaign = campaign('Agences Lyon');
    recipient($campaign, 'a@x.example', RecipientStatus::Sent);
    recipient($campaign, 'b@x.example');

    $this->withSession(authenticated())->get('/campaigns')
        ->assertOk()
        ->assertSee('Agences Lyon')
        ->assertSee('1 / 2');
});

it('affiche les destinataires et filtre par statut', function () {
    $campaign = campaign();
    recipient($campaign, 'envoye@x.example', RecipientStatus::Sent);
    recipient($campaign, 'attente@x.example');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}")
        ->assertSee('envoye@x.example')->assertSee('attente@x.example');

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}?status=sent")
        ->assertSee('envoye@x.example')->assertDontSee('attente@x.example');
});

it('prévient quand les envois sont désactivés', function () {
    $this->withSession(authenticated())->get('/campaigns')->assertSee('Envois désactivés');

    config(['slowmail.enabled' => true]);

    $this->withSession(authenticated())->get('/campaigns')->assertDontSee('Envois désactivés');
});
