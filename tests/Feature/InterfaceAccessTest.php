<?php

use App\Models\Recipient;
use App\Models\Suppression;
use Illuminate\Support\Facades\Route;

/**
 * Les routes de l'interface : toutes celles des contrôleurs de l'application, hors connexion.
 *
 * @return list<array{0: string, 1: string}> méthode, adresse avec des paramètres bidon
 */
function interfaceRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();

        if (! str_starts_with($action, 'App\\Http\\Controllers\\') && ! str_contains($action, 'RedirectController')) {
            continue;
        }

        if (str_contains($action, 'LoginController@show') || str_contains($action, 'LoginController@store')) {
            continue;
        }

        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'token' ? str_repeat('a', 26) : '999', '/'.$route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = [$method, $uri];
        }
    }

    return $routes;
}

it('protège chaque écran et chaque action par le mot de passe', function () {
    $routes = interfaceRoutes();

    // Garde-fou : le test ne doit pas passer en ne vérifiant rien.
    expect(count($routes))->toBeGreaterThan(25);

    foreach ($routes as [$method, $uri]) {
        $this->call($method, $uri)->assertRedirect('/login', "{$method} {$uri} est accessible sans mot de passe");
    }
});

it('ne distingue pas une campagne inconnue d\'une redirection quand on n\'est pas connecté', function () {
    $this->get('/campaigns/999/settings')->assertRedirect('/login');
    $this->get('/campaigns/999/journal')->assertRedirect('/login');
    $this->get('/campaigns/999/export')->assertRedirect('/login');
});

it('renvoie une 404 pour une campagne inconnue quand on est connecté', function () {
    foreach (['settings', 'journal', 'export', 'template'] as $page) {
        $this->withSession(authenticated())->get("/campaigns/999/{$page}")->assertNotFound();
    }
});

it('refuse d\'agir sur une ligne qui n\'appartient pas à la campagne de l\'adresse', function () {
    $mine = campaign('Ma campagne');
    $other = campaign('Une autre');
    $row = recipient($other, 'autre@exemple.test');

    foreach (['reply', 'confirm-sent', 'not-sent'] as $action) {
        $this->withSession(authenticated())
            ->post("/campaigns/{$mine->id}/recipients/{$row->id}/{$action}", ['status' => 'stop'])
            ->assertNotFound();
    }

    expect(Recipient::find($row->id)->reply_status)->toBeNull()
        ->and(Suppression::count())->toBe(0);
});

it('ne laisse entrer personne quand SLOWMAIL_PASSWORD est vide, sur aucun écran', function () {
    config(['slowmail.password' => '']);
    $campaign = campaign();

    foreach (['', '/settings', '/journal', '/export', '/template'] as $suffix) {
        $this->withSession(authenticated())->get("/campaigns/{$campaign->id}{$suffix}")->assertRedirect('/login');
    }

    $this->withSession(authenticated())->get('/suppressions')->assertRedirect('/login');
});
