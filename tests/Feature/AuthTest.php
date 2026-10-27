<?php

use App\Http\Middleware\RequireSlowmailPassword;

it('renvoie vers la connexion quand on n\'est pas connecté', function () {
    $this->get('/campaigns')->assertRedirect('/login');
    $this->get('/')->assertRedirect('/login');
    $this->post('/campaigns', ['name' => 'X'])->assertRedirect('/login');
});

it('affiche la page de connexion', function () {
    $this->get('/login')->assertOk()->assertSee('Mot de passe');
});

it('laisse entrer avec le bon mot de passe', function () {
    $this->post('/login', ['password' => 'mot-de-passe-de-test'])->assertRedirect('/campaigns');

    $this->get('/campaigns')->assertOk();
});

it('revient sur la page demandée après la connexion', function () {
    $this->get('/campaigns/1')->assertRedirect('/login');

    $this->post('/login', ['password' => 'mot-de-passe-de-test'])->assertRedirect('/campaigns/1');
});

it('refuse un mauvais mot de passe', function () {
    $this->from('/login')
        ->post('/login', ['password' => 'mauvais'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('password');

    $this->get('/campaigns')->assertRedirect('/login');
});

it('refuse un mot de passe vide', function () {
    $this->post('/login', ['password' => ''])->assertSessionHasErrors('password');
});

it('interdit toute connexion quand SLOWMAIL_PASSWORD est vide', function () {
    config(['slowmail.password' => '']);

    $this->post('/login', ['password' => ''])->assertSessionHasErrors('password');
    $this->post('/login', ['password' => 'nimporte'])->assertSessionHasErrors('password');

    $this->get('/login')->assertSee('SLOWMAIL_PASSWORD');
    $this->get('/campaigns')->assertRedirect('/login');
});

it('coupe aussi une session existante quand le mot de passe est retiré', function () {
    config(['slowmail.password' => '']);

    $this->withSession(authenticated())->get('/campaigns')->assertRedirect('/login');
});

it('limite les tentatives de connexion', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['password' => 'mauvais'])->assertStatus(302);
    }

    $this->post('/login', ['password' => 'mot-de-passe-de-test'])->assertStatus(429);
});

it('déconnecte', function () {
    $this->withSession(authenticated())->post('/logout')->assertRedirect('/login');

    $this->get('/campaigns')->assertRedirect('/login');
});

it('garde le point de santé accessible sans mot de passe', function () {
    $this->get('/up')->assertOk();
});

it('ne pose la session qu\'après un mot de passe correct', function () {
    $this->post('/login', ['password' => 'mauvais']);

    expect(session(RequireSlowmailPassword::SESSION_KEY))->toBeNull();
});
