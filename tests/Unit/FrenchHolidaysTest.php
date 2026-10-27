<?php

use App\Services\Planning\FrenchHolidays;
use Carbon\CarbonImmutable;

function frenchHolidayNames(int $year): array
{
    return (new FrenchHolidays)->forYear($year);
}

it('compte les 11 jours fériés de la France métropolitaine chaque année', function () {
    foreach ([2024, 2025, 2026, 2027, 2028, 2030] as $year) {
        expect(frenchHolidayNames($year))->toHaveCount(11);
    }
});

it('calcule les jours fériés fixes', function () {
    $holidays = frenchHolidayNames(2026);

    expect($holidays)->toMatchArray([
        '2026-01-01' => 'Jour de l\'an',
        '2026-05-01' => 'Fête du Travail',
        '2026-05-08' => 'Victoire 1945',
        '2026-07-14' => 'Fête nationale',
        '2026-08-15' => 'Assomption',
        '2026-11-01' => 'Toussaint',
        '2026-11-11' => 'Armistice 1918',
        '2026-12-25' => 'Noël',
    ]);
});

it('calcule les jours fériés qui dépendent de Pâques', function () {
    // Lundi de Pâques, Ascension (Pâques + 39 jours), lundi de Pentecôte (Pâques + 50 jours).
    $attendus = [
        2024 => ['2024-04-01', '2024-05-09', '2024-05-20'],
        2025 => ['2025-04-21', '2025-05-29', '2025-06-09'],
        2026 => ['2026-04-06', '2026-05-14', '2026-05-25'],
        2027 => ['2027-03-29', '2027-05-06', '2027-05-17'],
        2028 => ['2028-04-17', '2028-05-25', '2028-06-05'],
        2030 => ['2030-04-22', '2030-05-30', '2030-06-10'],
    ];

    foreach ($attendus as $year => [$paques, $ascension, $pentecote]) {
        $holidays = frenchHolidayNames($year);

        expect($holidays)->toHaveKey($paques, 'Lundi de Pâques')
            ->toHaveKey($ascension, 'Ascension')
            ->toHaveKey($pentecote, 'Lundi de Pentecôte');
    }
});

it('reconnaît un jour férié et donne son nom', function () {
    $holidays = new FrenchHolidays;

    expect($holidays->isHoliday(CarbonImmutable::parse('2026-12-25')))->toBeTrue()
        ->and($holidays->name(CarbonImmutable::parse('2026-05-14')))->toBe('Ascension')
        ->and($holidays->isHoliday(CarbonImmutable::parse('2026-10-21')))->toBeFalse()
        ->and($holidays->name(CarbonImmutable::parse('2026-10-21')))->toBeNull();
});

it('lit la date dans le fuseau reçu, pas en UTC', function () {
    $holidays = new FrenchHolidays;

    // Le 1er janvier à 00 h 30 à Paris, c'est encore le 31 décembre en UTC.
    $paris = CarbonImmutable::parse('2027-01-01 00:30', 'Europe/Paris');

    expect($holidays->isHoliday($paris))->toBeTrue()
        ->and($holidays->isHoliday($paris->utc()))->toBeFalse();
});

it('n\'inclut pas les dimanches de Pâques et de Pentecôte, ni les jours propres à l\'Alsace-Moselle', function () {
    $holidays = new FrenchHolidays;

    expect($holidays->isHoliday(CarbonImmutable::parse('2026-04-05')))->toBeFalse()
        ->and($holidays->isHoliday(CarbonImmutable::parse('2026-04-03')))->toBeFalse()
        ->and($holidays->isHoliday(CarbonImmutable::parse('2026-12-26')))->toBeFalse();
});
