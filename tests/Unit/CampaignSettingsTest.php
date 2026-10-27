<?php

use App\Services\Planning\CampaignSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

it('prend les défauts de la spec', function () {
    $settings = new CampaignSettings;

    expect($settings)
        ->dailyMax->toBe(4)
        ->windowStart->toBe('09:30')
        ->windowEnd->toBe('17:30')
        ->timezone->toBe('Europe/Paris')
        ->days->toBe([1, 2, 3, 4, 5])
        ->skipHolidays->toBeTrue()
        ->minGapMinutes->toBe(25)
        ->jitter->toBeTrue()
        ->startAt->toBeNull()
        ->endAt->toBeNull()
        ->maxTotal->toBeNull()
        ->rampUp->toBeFalse()
        ->rampStart->toBe(2)
        ->rampStep->toBe(1)
        ->rampEveryDays->toBe(3)
        ->domainCooldownDays->toBe(7)
        ->dryRun->toBeFalse()
        ->and($settings->violations())->toBe([]);
});

it('lit des réglages vides avec les défauts', function () {
    expect(CampaignSettings::fromArray([]))->toEqual(new CampaignSettings);
});

it('lit les clés en snake_case de campaigns.settings', function () {
    $settings = CampaignSettings::fromArray([
        'daily_max' => 6,
        'window_start' => '08:00',
        'window_end' => '12:00',
        'timezone' => 'America/New_York',
        'days' => [1, 3, 5],
        'skip_holidays' => false,
        'min_gap_minutes' => 10,
        'jitter' => false,
        'max_total' => 50,
        'ramp_up' => true,
        'ramp_start' => 1,
        'ramp_step' => 2,
        'ramp_every_days' => 5,
        'domain_cooldown_days' => 14,
        'dry_run' => true,
    ]);

    expect($settings)
        ->dailyMax->toBe(6)
        ->windowStart->toBe('08:00')
        ->windowEnd->toBe('12:00')
        ->timezone->toBe('America/New_York')
        ->days->toBe([1, 3, 5])
        ->skipHolidays->toBeFalse()
        ->minGapMinutes->toBe(10)
        ->jitter->toBeFalse()
        ->maxTotal->toBe(50)
        ->rampUp->toBeTrue()
        ->rampStart->toBe(1)
        ->rampStep->toBe(2)
        ->rampEveryDays->toBe(5)
        ->domainCooldownDays->toBe(14)
        ->dryRun->toBeTrue();
});

it('convertit ce que les formulaires envoient : textes, cases, listes', function () {
    $settings = CampaignSettings::fromArray([
        'daily_max' => '5',
        'skip_holidays' => 'false',
        'jitter' => '1',
        'dry_run' => 'on',
        'days' => ['1', '2', '9', '0', 'x', '2'],
        'max_total' => '',
        'window_start' => '  08:15 ',
    ]);

    expect($settings)
        ->dailyMax->toBe(5)
        ->skipHolidays->toBeFalse()
        ->jitter->toBeTrue()
        ->dryRun->toBeTrue()
        ->days->toBe([1, 2])
        ->maxTotal->toBeNull()
        ->windowStart->toBe('08:15');
});

it('ignore les clés inconnues et les valeurs inutilisables', function () {
    $settings = CampaignSettings::fromArray(['inconnue' => 1, 'daily_max' => 'beaucoup', 'window_end' => '', 'days' => 'lundi']);

    expect($settings)->dailyMax->toBe(4)->windowEnd->toBe('17:30')->days->toBe([1, 2, 3, 4, 5]);
});

it('range les jours dans l\'ordre', function () {
    expect(CampaignSettings::fromArray(['days' => [5, 1, 3]])->days)->toBe([1, 3, 5]);
});

it('relit ce qu\'elle a écrit', function () {
    $settings = new CampaignSettings(dailyMax: 3, days: [2, 4], maxTotal: 10, rampUp: true, domainCooldownDays: 0);

    expect(CampaignSettings::fromArray($settings->toArray()))->toEqual($settings);
});

it('stocke start_at et end_at à part, en UTC', function () {
    $settings = CampaignSettings::fromArray([], Carbon::parse('2026-10-21 09:00', 'Europe/Paris'), Carbon::parse('2026-11-21 09:00', 'Europe/Paris'));

    expect($settings->startAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($settings->startAt->format('Y-m-d H:i T'))->toBe('2026-10-21 07:00 UTC')
        ->and($settings->endAt->format('Y-m-d H:i'))->toBe('2026-11-21 08:00')
        ->and($settings->toArray())->not->toHaveKeys(['start_at', 'end_at']);
});

it('reconnaît les fuseaux horaires valides', function () {
    expect(CampaignSettings::isValidTimezone('Europe/Paris'))->toBeTrue()
        ->and(CampaignSettings::isValidTimezone('Mars/Olympus'))->toBeFalse()
        ->and(CampaignSettings::isValidTimezone(''))->toBeFalse();
});

it('lit une heure HH:MM', function () {
    expect(CampaignSettings::minutes('09:30'))->toBe(570)
        ->and(CampaignSettings::minutes('00:00'))->toBe(0)
        ->and(CampaignSettings::minutes('23:59'))->toBe(1439)
        ->and(CampaignSettings::minutes('9:30'))->toBeNull()
        ->and(CampaignSettings::minutes('24:00'))->toBeNull()
        ->and(CampaignSettings::minutes('09:60'))->toBeNull()
        ->and(CampaignSettings::minutes('midi'))->toBeNull();
});

it('calcule la durée de la plage', function () {
    expect((new CampaignSettings)->windowSeconds())->toBe(8 * 3600)
        ->and((new CampaignSettings(windowStart: '17:30', windowEnd: '09:30'))->windowSeconds())->toBe(0)
        ->and((new CampaignSettings(windowStart: 'x'))->windowSeconds())->toBe(0);
});

// --- Validation -------------------------------------------------------------------------------

dataset('réglages invalides', [
    'maximum par jour nul' => [['daily_max' => 0], 'entre 1 et 100'],
    'maximum par jour énorme' => [['daily_max' => 101], 'entre 1 et 100'],
    'heure mal écrite' => [['window_start' => '9h30'], 'HH:MM'],
    'plage à l\'envers' => [['window_start' => '18:00', 'window_end' => '09:00'], 'après son début'],
    'plage vide' => [['window_start' => '10:00', 'window_end' => '10:00'], 'après son début'],
    'fuseau inconnu' => [['timezone' => 'Mars/Olympus'], 'inconnu'],
    'aucun jour' => [['days' => []], 'au moins un jour'],
    'écart négatif' => [['min_gap_minutes' => -5], 'entre 0 et 720'],
    'écart énorme' => [['min_gap_minutes' => 721], 'entre 0 et 720'],
    'total nul' => [['max_total' => 0], 'au moins 1'],
    'montée sans départ' => [['ramp_up' => true, 'ramp_start' => 0], 'montée progressive'],
    'montée sans intervalle' => [['ramp_up' => true, 'ramp_every_days' => 0], 'montée progressive'],
    'montée à paliers négatifs' => [['ramp_up' => true, 'ramp_step' => -1], 'montée progressive'],
    'cooldown négatif' => [['domain_cooldown_days' => -1], 'entre 0 et 365'],
    'cooldown énorme' => [['domain_cooldown_days' => 366], 'entre 0 et 365'],
]);

it('refuse des réglages invalides', function (array $input, string $message) {
    $violations = CampaignSettings::fromArray($input)->violations();

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain($message);
})->with('réglages invalides');

it('ne se plaint pas d\'une montée progressive mal réglée quand elle est coupée', function () {
    expect(CampaignSettings::fromArray(['ramp_up' => false, 'ramp_start' => 0, 'ramp_every_days' => 0])->violations())->toBe([]);
});

it('refuse une date de fin avant la date de début', function () {
    $settings = CampaignSettings::fromArray([], Carbon::parse('2026-10-21'), Carbon::parse('2026-10-20'));

    expect(implode(' ', $settings->violations()))->toContain('date de fin');
});

it('refuse une plage trop courte pour le maximum par jour et l\'écart demandés', function () {
    $settings = CampaignSettings::fromArray(['window_start' => '09:30', 'window_end' => '10:30', 'daily_max' => 4, 'min_gap_minutes' => 25]);

    expect(implode(' ', $settings->violations()))->toContain('1 h')->toContain('3 mail(s)')->toContain('25 minutes');
});

it('accepte une plage juste assez longue', function () {
    $settings = CampaignSettings::fromArray(['window_start' => '09:30', 'window_end' => '11:10', 'daily_max' => 5, 'min_gap_minutes' => 25]);

    expect($settings->violations())->toBe([]);
});

it('accepte un écart nul sur une plage courte', function () {
    expect(CampaignSettings::fromArray(['window_start' => '09:30', 'window_end' => '09:40', 'daily_max' => 20, 'min_gap_minutes' => 0])->violations())->toBe([]);
});

it('signale tous les problèmes à la fois', function () {
    $violations = CampaignSettings::fromArray(['daily_max' => 0, 'timezone' => 'x', 'days' => []])->violations();

    expect($violations)->toHaveCount(3);
});

// --- Récapitulatif en clair --------------------------------------------------------------------

it('résume les réglages par défaut en clair', function () {
    expect((new CampaignSettings)->summary())->toBe(
        '4 mails par jour, du lundi au vendredi, entre 9 h 30 et 17 h 30 (Europe/Paris), jours fériés exclus, '
        .'au moins 25 min entre deux mails, jamais deux mails au même domaine à moins de 7 jours d\'écart.'
    );
});

it('résume des réglages personnalisés', function () {
    $settings = new CampaignSettings(
        dailyMax: 1, windowStart: '09:00', windowEnd: '12:00', days: [1, 3, 5], skipHolidays: false,
        minGapMinutes: 0, domainCooldownDays: 0, maxTotal: 30,
    );

    expect($settings->summary())->toBe('1 mail par jour, les lundi, mercredi et vendredi, entre 9 h et 12 h (Europe/Paris), arrêt après 30 mails.');
});

it('résume les jours en clair', function () {
    $resume = fn (array $days) => (new CampaignSettings(days: $days))->summary();

    expect($resume([1, 2, 3, 4, 5, 6, 7]))->toContain('tous les jours')
        ->and($resume([2]))->toContain('le mardi')
        ->and($resume([6, 7]))->toContain('du samedi au dimanche')
        ->and($resume([1, 2, 3]))->toContain('du lundi au mercredi')
        ->and($resume([2, 4]))->toContain('les mardi et jeudi');
});

it('résume la montée progressive', function () {
    $settings = new CampaignSettings(rampUp: true);

    expect($settings->summary())->toContain('montée progressive de 2 mail(s) par jour, +1 tous les 3 jours d\'envoi, jusqu\'à 4');
});
