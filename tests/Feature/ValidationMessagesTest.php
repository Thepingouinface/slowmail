<?php

use Illuminate\Support\ViewErrorBag;

it('traduit en français les règles de validation utilisées', function () {
    foreach (['required', 'string', 'integer', 'file', 'extensions', 'array', 'date_format', 'enum', 'max.string', 'max.file'] as $rule) {
        expect(Lang::has("validation.{$rule}", 'fr', fallback: false))->toBeTrue("règle sans traduction : {$rule}");
    }
});

it('ne laisse jamais une clé de traduction brute, même pour une règle non traduite', function () {
    expect(trans('validation.email', ['attribute' => 'adresse']))->not->toStartWith('validation.')->toContain('adresse');
});

it('affiche les erreurs de validation en français', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->post(route('campaigns.template.test', $campaign), ['recipient_id' => 'abc'])
        ->assertSessionHasErrors(['recipient_id' => 'Le champ ligne doit être un nombre entier.']);

    $this->withSession(authenticated())
        ->post(route('campaigns.import.analyze', $campaign), ['email_column' => str_repeat('x', 101)])
        ->assertSessionHasErrors(['email_column' => 'Le champ colonne des adresses ne doit pas dépasser 100 caractères.']);
});

it('n\'affiche aucune erreur de validation en anglais ni en clé brute sur les formulaires de l\'interface', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'a@exemple.test');
    $nonsense = ['x' => ['y']];

    $forms = [
        ['post', '/campaigns', ['name' => ['tableau']]],
        ['put', "/campaigns/{$campaign->id}/template", ['subject' => ['a'], 'body' => ['b']]],
        ['put', "/campaigns/{$campaign->id}/settings", ['daily_max' => 'x', 'window_start' => ['a'], 'window_end' => ['a'], 'timezone' => ['a'], 'days' => 'x', 'days.0' => 'y', 'min_gap_minutes' => 'x', 'start_at' => 'x', 'end_at' => 'x', 'max_total' => 'x', 'ramp_start' => 'x', 'ramp_step' => 'x', 'ramp_every_days' => 'x', 'domain_cooldown_days' => 'x']],
        ['post', "/campaigns/{$campaign->id}/recipients/{$row->id}/reply", ['status' => 'x', 'notes' => ['a']]],
        ['post', '/suppressions', ['value' => ['a'], 'reason' => 'x']],
    ];

    foreach ($forms as [$method, $url, $data]) {
        $response = $this->withSession(authenticated())->{$method}($url, $data);
        $bag = $response->baseResponse->getSession()->get('errors');
        $errors = $bag === null ? [] : collect($bag instanceof ViewErrorBag ? $bag->getBag('default')->getMessages() : $bag)->flatten()->all();

        expect($errors)->not->toBeEmpty("{$method} {$url} n'a produit aucune erreur");

        foreach ($errors as $message) {
            expect($message)->not->toStartWith('validation.')->not->toMatch('/\b(must be|is required|field|may not)\b/i');
        }
    }
});
