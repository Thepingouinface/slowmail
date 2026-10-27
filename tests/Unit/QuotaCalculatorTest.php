<?php

use App\Services\Planning\CampaignSettings;
use App\Services\Planning\QuotaCalculator;

it('donne daily_max chaque jour sans montée progressive, quel que soit le nombre de jours d\'envoi passés', function () {
    $settings = new CampaignSettings(dailyMax: 4);

    foreach ([0, 1, 5, 40] as $activeDays) {
        expect((new QuotaCalculator)->quotaFor($settings, $activeDays))->toBe(4);
    }
});

it('monte par paliers : départ, +ramp_step tous les ramp_every_days jours d\'envoi, plafonné à daily_max', function () {
    $settings = new CampaignSettings(dailyMax: 4, rampUp: true, rampStart: 2, rampStep: 1, rampEveryDays: 3);
    $quota = new QuotaCalculator;

    $parJour = array_map(fn (int $active) => $quota->quotaFor($settings, $active), range(0, 10));

    expect($parJour)->toBe([2, 2, 2, 3, 3, 3, 4, 4, 4, 4, 4])
        ->and($quota->quotaFor($settings, 500))->toBe(4);
});

it('plafonne le départ à daily_max', function () {
    $settings = new CampaignSettings(dailyMax: 3, rampUp: true, rampStart: 10);

    expect((new QuotaCalculator)->quotaFor($settings, 0))->toBe(3);
});

it('reste constant avec un palier nul', function () {
    $settings = new CampaignSettings(dailyMax: 6, rampUp: true, rampStart: 2, rampStep: 0, rampEveryDays: 3);

    expect((new QuotaCalculator)->quotaFor($settings, 30))->toBe(2);
});

it('monte à chaque jour d\'envoi avec ramp_every_days à 1, et ne divise jamais par zéro', function () {
    $settings = new CampaignSettings(dailyMax: 5, rampUp: true, rampStart: 1, rampStep: 1, rampEveryDays: 1);
    $quota = new QuotaCalculator;

    expect(array_map(fn (int $active) => $quota->quotaFor($settings, $active), range(0, 5)))->toBe([1, 2, 3, 4, 5, 5]);

    $zero = new CampaignSettings(dailyMax: 5, rampUp: true, rampStart: 1, rampStep: 1, rampEveryDays: 0);

    expect($quota->quotaFor($zero, 2))->toBe(3);
});
