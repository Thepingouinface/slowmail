<?php

use App\Support\LocalTime;
use Carbon\CarbonImmutable;

it('affiche les dates en français, à l\'heure demandée', function () {
    $at = CarbonImmutable::parse('2026-10-22 08:12:05', 'UTC');

    expect(LocalTime::dateTime($at, 'Europe/Paris'))->toBe('jeu. 22 oct. à 10:12')
        ->and(LocalTime::time($at, 'Europe/Paris'))->toBe('10:12:05')
        ->and(LocalTime::dateTimeSeconds($at, 'Europe/Paris'))->toBe('jeu. 22 oct. à 10:12:05')
        ->and(LocalTime::clock($at, 'Europe/Paris'))->toBe('10:12')
        ->and(LocalTime::day($at, 'Europe/Paris'))->toBe('jeu. 22 oct.')
        ->and(LocalTime::compact($at, 'Europe/Paris'))->toBe('22/10/2026 10:12')
        ->and(LocalTime::dateTime($at, 'America/New_York'))->toBe('jeu. 22 oct. à 04:12');
});

it('passe au jour suivant quand le fuseau le demande', function () {
    $at = CarbonImmutable::parse('2026-10-22 23:30:00', 'UTC');

    expect(LocalTime::dateTime($at, 'Europe/Paris'))->toBe('ven. 23 oct. à 01:30');
});

it('rend un texte de remplacement pour une date absente', function () {
    expect(LocalTime::dateTime(null))->toBe('—')->and(LocalTime::compact(null, null, ''))->toBe('');
});

it('ne modifie pas la date qu\'on lui donne', function () {
    $at = CarbonImmutable::parse('2026-10-22 08:12:05', 'UTC');
    $mutable = $at->toMutable();

    LocalTime::dateTime($mutable, 'America/New_York');

    expect($mutable->getTimezone()->getName())->toBe('UTC');
});

it('prépare la valeur d\'un champ datetime-local à l\'heure de la campagne', function () {
    expect(LocalTime::input(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'), 'Europe/Paris'))->toBe('2026-11-02T09:00')
        ->and(LocalTime::input(null, 'Europe/Paris'))->toBe('');
});

it('retombe sur Europe/Paris quand le fuseau configuré est inconnu', function () {
    config(['slowmail.timezone' => 'Nimporte/Quoi']);

    expect(LocalTime::defaultTimezone())->toBe('Europe/Paris');

    config(['slowmail.timezone' => 'America/New_York']);

    expect(LocalTime::defaultTimezone())->toBe('America/New_York');
});
