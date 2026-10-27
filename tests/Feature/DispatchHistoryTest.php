<?php

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Planning\DispatchHistory;
use Carbon\CarbonImmutable;

function historyOf(Campaign $campaign, string $timezone = 'Europe/Paris'): DispatchHistory
{
    return DispatchHistory::load($campaign, $timezone);
}

it('compte ce qui a pu partir : envoyé, simulé, en cours, incertain', function () {
    $campaign = campaign();

    foreach ([RecipientStatus::Sent, RecipientStatus::DryRun, RecipientStatus::Sending, RecipientStatus::Uncertain] as $i => $status) {
        recipient($campaign, "a{$i}@x.example", $status)->forceFill([
            'sent_at' => in_array($status, [RecipientStatus::Sent, RecipientStatus::DryRun], true) ? '2026-10-21 10:00:00' : null,
            'sending_started_at' => in_array($status, [RecipientStatus::Sending, RecipientStatus::Uncertain], true) ? '2026-10-21 11:00:00' : null,
        ])->save();
    }

    expect(historyOf($campaign)->total())->toBe(4);
});

it('ne compte pas ce qui n\'est pas parti', function () {
    $campaign = campaign();

    foreach ([RecipientStatus::Pending, RecipientStatus::Planned, RecipientStatus::Failed, RecipientStatus::Skipped, RecipientStatus::Bounced, RecipientStatus::Suppressed] as $i => $status) {
        recipient($campaign, "a{$i}@x.example", $status);
    }

    expect(historyOf($campaign)->total())->toBe(0)->and(historyOf($campaign)->lastDispatchAt())->toBeNull();
});

it('ne compte que la campagne demandée', function () {
    $a = campaign('A');
    $b = campaign('B');
    markSent(recipient($a, 'a@x.example'), paris('2026-10-21 10:00'));
    markSent(recipient($b, 'b@x.example'), paris('2026-10-21 10:00'));

    expect(historyOf($a)->total())->toBe(1);
});

it('compte les envois par jour dans le fuseau de la campagne', function () {
    $campaign = campaign();
    // 22 h 30 UTC le 21, c'est 0 h 30 le 22 à Paris.
    markSent(recipient($campaign, 'a@x.example'), CarbonImmutable::parse('2026-10-21 22:30', 'UTC'));
    markSent(recipient($campaign, 'b@x.example'), CarbonImmutable::parse('2026-10-22 08:00', 'Europe/Paris'));
    markSent(recipient($campaign, 'c@x.example'), CarbonImmutable::parse('2026-10-21 09:00', 'Europe/Paris'));

    $paris = historyOf($campaign, 'Europe/Paris');
    $utc = historyOf($campaign, 'UTC');

    expect($paris->countOn(paris('2026-10-21')->startOfDay()))->toBe(1)
        ->and($paris->countOn(paris('2026-10-22')->startOfDay()))->toBe(2)
        ->and($utc->countOn(CarbonImmutable::parse('2026-10-21', 'UTC')))->toBe(2);
});

it('donne les heures d\'un jour', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'a@x.example'), paris('2026-10-21 10:12:30'));
    markSent(recipient($campaign, 'b@x.example'), paris('2026-10-22 10:00'));

    $moments = historyOf($campaign)->momentsOn(paris('2026-10-21')->startOfDay());

    expect($moments)->toHaveCount(1)
        ->and($moments[0]->setTimezone('Europe/Paris')->format('H:i:s'))->toBe('10:12:30');
});

it('compte les jours où des mails sont réellement partis, avant un jour donné', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'a@x.example'), paris('2026-10-19 10:00'));
    markSent(recipient($campaign, 'b@x.example'), paris('2026-10-19 11:00'));
    markSent(recipient($campaign, 'c@x.example'), paris('2026-10-20 10:00'));
    markSent(recipient($campaign, 'd@x.example'), paris('2026-10-26 10:00'));

    $history = historyOf($campaign);

    expect($history->activeDaysBefore(paris('2026-10-19')->startOfDay()))->toBe(0)
        ->and($history->activeDaysBefore(paris('2026-10-20')->startOfDay()))->toBe(1)
        ->and($history->activeDaysBefore(paris('2026-10-26')->startOfDay()))->toBe(2)
        ->and($history->activeDaysBefore(paris('2026-10-27')->startOfDay()))->toBe(3);
});

it('compte les envois simulés comme jours d\'envoi, mais pas les envois incertains', function () {
    $campaign = campaign();
    recipient($campaign, 'a@x.example', RecipientStatus::DryRun)->forceFill(['sent_at' => paris('2026-10-19 10:00')->utc()])->save();
    recipient($campaign, 'b@x.example', RecipientStatus::Uncertain)->forceFill(['sending_started_at' => paris('2026-10-20 10:00')->utc()])->save();

    $history = historyOf($campaign);

    expect($history->activeDaysBefore(paris('2026-10-30')->startOfDay()))->toBe(1)
        ->and($history->total())->toBe(2)
        ->and($history->countOn(paris('2026-10-20')->startOfDay()))->toBe(1);
});

it('retient le dernier envoi', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'a@x.example'), paris('2026-10-19 10:00'));
    markSent(recipient($campaign, 'b@x.example'), paris('2026-10-20 15:42:10'));
    markSent(recipient($campaign, 'c@x.example'), paris('2026-10-20 09:00'));

    expect(historyOf($campaign)->lastDispatchAt()->setTimezone('Europe/Paris')->format('Y-m-d H:i:s'))->toBe('2026-10-20 15:42:10');
});

it('retient le dernier jour d\'envoi par domaine', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'a@agence.example'), paris('2026-10-19 10:00'));
    markSent(recipient($campaign, 'b@agence.example'), paris('2026-10-21 10:00'));
    markSent(recipient($campaign, 'c@autre.example'), paris('2026-10-20 10:00'));

    $history = historyOf($campaign);

    expect($history->lastDayFor('agence.example'))->toBe(DispatchHistory::dayNumber(paris('2026-10-21')))
        ->and($history->lastDayFor('autre.example'))->toBe(DispatchHistory::dayNumber(paris('2026-10-20')))
        ->and($history->lastDayFor('inconnu.example'))->toBeNull();
});

it('se rabat sur la date de mise à jour quand l\'heure d\'envoi manque', function () {
    $campaign = campaign();
    $row = recipient($campaign, 'a@x.example', RecipientStatus::Sent);
    $row->forceFill(['updated_at' => paris('2026-10-19 12:00')->utc()])->saveQuietly();

    expect(historyOf($campaign)->countOn(paris('2026-10-19')->startOfDay()))->toBe(1);
});

it('numérote les jours du calendrier sans tenir compte des changements d\'heure', function () {
    // 25 octobre 2026 : un jour de 25 heures.
    expect(DispatchHistory::dayNumber(paris('2026-10-26 00:30')) - DispatchHistory::dayNumber(paris('2026-10-25 12:00')))->toBe(1)
        ->and(DispatchHistory::dayNumber(paris('2026-10-26')) - DispatchHistory::dayNumber(paris('2026-10-19')))->toBe(7);
});

it('accepte des envois projetés', function () {
    $history = DispatchHistory::empty('Europe/Paris');
    $history->add(paris('2026-10-21 10:00')->utc(), 'x.example');
    $history->add(paris('2026-10-22 10:00')->utc(), 'x.example');

    expect($history->total())->toBe(2)
        ->and($history->activeDaysBefore(paris('2026-10-23')->startOfDay()))->toBe(2)
        ->and($history->lastDayFor('x.example'))->toBe(DispatchHistory::dayNumber(paris('2026-10-22')));
});
