<?php

use App\Services\Planning\CampaignSettings;
use App\Services\Planning\FrenchHolidays;
use App\Services\Planning\SendCalendar;
use Carbon\CarbonImmutable;

function sendCalendar(): SendCalendar
{
    return new SendCalendar(new FrenchHolidays);
}

function localDay(string $date, string $timezone = 'Europe/Paris'): CarbonImmutable
{
    return CarbonImmutable::parse($date, $timezone)->startOfDay();
}

it('autorise du lundi au vendredi par défaut', function () {
    $calendar = sendCalendar();
    $settings = new CampaignSettings;

    // Du lundi 19 au dimanche 25 octobre 2026.
    $autorises = array_map(fn (int $i) => $calendar->isSendingDay($settings, localDay('2026-10-19')->addDays($i)), range(0, 6));

    expect($autorises)->toBe([true, true, true, true, true, false, false]);
});

it('autorise les jours choisis', function () {
    $settings = new CampaignSettings(days: [2, 6]);

    expect(sendCalendar()->isSendingDay($settings, localDay('2026-10-20')))->toBeTrue()
        ->and(sendCalendar()->isSendingDay($settings, localDay('2026-10-24')))->toBeTrue()
        ->and(sendCalendar()->isSendingDay($settings, localDay('2026-10-21')))->toBeFalse();
});

it('exclut les jours fériés, sauf si on le refuse', function () {
    // Le mercredi 11 novembre 2026 (Armistice) et le jeudi 14 mai 2026 (Ascension).
    foreach (['2026-11-11', '2026-05-14', '2026-05-01'] as $ferie) {
        expect(sendCalendar()->isSendingDay(new CampaignSettings, localDay($ferie)))->toBeFalse($ferie)
            ->and(sendCalendar()->isSendingDay(new CampaignSettings(skipHolidays: false), localDay($ferie)))->toBeTrue($ferie);
    }
});

it('donne la plage du jour dans le fuseau de la campagne', function () {
    [$start, $end] = sendCalendar()->window(new CampaignSettings, localDay('2026-10-21'));

    expect($start->format('Y-m-d H:i T'))->toBe('2026-10-21 09:30 CEST')
        ->and($end->format('H:i'))->toBe('17:30')
        ->and($start->utc()->format('H:i'))->toBe('07:30');
});

it('suit le changement d\'heure : la plage reste 9 h 30 à 17 h 30 à l\'heure locale', function () {
    $calendar = sendCalendar();

    // Le dimanche 25 octobre 2026, la France passe à l'heure d'hiver.
    [$avant] = $calendar->window(new CampaignSettings, localDay('2026-10-23'));
    [$apres] = $calendar->window(new CampaignSettings, localDay('2026-10-26'));

    expect($avant->utc()->format('H:i'))->toBe('07:30')
        ->and($apres->utc()->format('H:i'))->toBe('08:30')
        ->and($apres->format('H:i'))->toBe('09:30');
});

it('suit le fuseau choisi', function () {
    $settings = new CampaignSettings(timezone: 'America/New_York');

    [$start, $end] = sendCalendar()->window($settings, localDay('2026-10-21', 'America/New_York'));

    expect($start->utc()->format('H:i'))->toBe('13:30')
        ->and($end->utc()->format('H:i'))->toBe('21:30');
});

it('n\'a pas de plage un jour sans envoi', function () {
    expect(sendCalendar()->window(new CampaignSettings, localDay('2026-10-24')))->toBeNull()
        ->and(sendCalendar()->window(new CampaignSettings, localDay('2026-11-11')))->toBeNull();
});

it('resserre la plage avec la date de début et la date de fin', function () {
    $settings = new CampaignSettings(
        startAt: CarbonImmutable::parse('2026-10-21 14:00', 'Europe/Paris')->utc(),
        endAt: CarbonImmutable::parse('2026-10-21 16:15', 'Europe/Paris')->utc(),
    );

    [$start, $end] = sendCalendar()->window($settings, localDay('2026-10-21'));

    expect($start->format('H:i'))->toBe('14:00')->and($end->format('H:i'))->toBe('16:15');
});

it('n\'a pas de plage avant la date de début ni après la date de fin', function () {
    $settings = new CampaignSettings(
        startAt: CarbonImmutable::parse('2026-10-21 18:00', 'Europe/Paris')->utc(),
    );

    expect(sendCalendar()->window($settings, localDay('2026-10-21')))->toBeNull();

    $fini = new CampaignSettings(endAt: CarbonImmutable::parse('2026-10-21 09:00', 'Europe/Paris')->utc());

    expect(sendCalendar()->window($fini, localDay('2026-10-21')))->toBeNull();
});

it('n\'a pas de plage si les heures sont invalides', function () {
    expect(sendCalendar()->window(new CampaignSettings(windowStart: '9h'), localDay('2026-10-21')))->toBeNull();
});

it('trouve le prochain début de plage', function () {
    $calendar = sendCalendar();
    $settings = new CampaignSettings;
    $paris = fn (string $date) => CarbonImmutable::parse($date, 'Europe/Paris');

    // Avant la plage : le jour même. Pendant ou après : le jour d'envoi suivant.
    expect($calendar->nextWindowStart($settings, $paris('2026-10-21 07:00'))->format('Y-m-d H:i'))->toBe('2026-10-21 09:30')
        ->and($calendar->nextWindowStart($settings, $paris('2026-10-21 12:00'))->format('Y-m-d H:i'))->toBe('2026-10-22 09:30')
        ->and($calendar->nextWindowStart($settings, $paris('2026-10-23 18:00'))->format('Y-m-d H:i'))->toBe('2026-10-26 09:30')
        ->and($calendar->nextWindowStart($settings, $paris('2026-10-24 10:00'))->format('Y-m-d H:i'))->toBe('2026-10-26 09:30');
});

it('saute les jours fériés pour trouver le prochain début de plage', function () {
    $suivant = sendCalendar()->nextWindowStart(new CampaignSettings, CarbonImmutable::parse('2026-05-13 18:00', 'Europe/Paris'));

    // Le jeudi 14 mai est l'Ascension.
    expect($suivant->format('Y-m-d H:i'))->toBe('2026-05-15 09:30');
});

it('ne trouve plus de début de plage après la date de fin', function () {
    $settings = new CampaignSettings(endAt: CarbonImmutable::parse('2026-10-21 17:00', 'Europe/Paris')->utc());

    expect(sendCalendar()->nextWindowStart($settings, CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Paris')))->toBeNull();
});

it('attend la date de début quand elle tombe dans une plage', function () {
    $settings = new CampaignSettings(startAt: CarbonImmutable::parse('2026-10-22 11:00', 'Europe/Paris')->utc());

    // Avant la date de début : rien le 21, la plage du 22 ne commence qu'à 11 h.
    $avant = sendCalendar()->nextWindowStart($settings, CarbonImmutable::parse('2026-10-21 08:00', 'Europe/Paris'));
    $apres = sendCalendar()->nextWindowStart($settings, CarbonImmutable::parse('2026-10-21 18:00', 'Europe/Paris'));

    expect($avant->format('Y-m-d H:i'))->toBe('2026-10-22 11:00')
        ->and($apres->format('Y-m-d H:i'))->toBe('2026-10-22 11:00');

    // Date de début le jour même, en milieu de plage.
    $memeJour = new CampaignSettings(startAt: CarbonImmutable::parse('2026-10-21 14:00', 'Europe/Paris')->utc());

    expect(sendCalendar()->nextWindowStart($memeJour, CarbonImmutable::parse('2026-10-21 08:00', 'Europe/Paris'))->format('H:i'))->toBe('14:00');
});
