<?php

use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Services\Sending\SchedulerSnapshot;
use App\Services\Sending\SchedulerState;
use App\Services\Sending\SchedulerStatus;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => sendingEnvironment());

function snapshotAt(string $time): SchedulerSnapshot
{
    return app(SchedulerStatus::class)->snapshot(paris($time));
}

// --- Le battement -------------------------------------------------------------------------------

it('retient le dernier tick et rend le précédent', function () {
    $state = app(SchedulerState::class);

    expect($state->lastTickAt())->toBeNull()
        ->and($state->recordTick(paris('2026-10-19 10:00:00')))->toBeNull()
        ->and($state->recordTick(paris('2026-10-19 10:01:00'))->equalTo(paris('2026-10-19 10:00:00')))->toBeTrue()
        ->and($state->lastTickAt()->equalTo(paris('2026-10-19 10:01:00')))->toBeTrue();
});

it('stocke le battement en UTC', function () {
    app(SchedulerState::class)->recordTick(paris('2026-10-19 10:00:00'));

    expect(DB::table('scheduler_state')->where('key', 'last_tick_at')->value('value'))->toBe('2026-10-19 08:00:00');
});

it('retient et efface le prochain envoi prévu', function () {
    $state = app(SchedulerState::class);

    expect($state->nextSendAt())->toBeNull();

    $state->setNextSendAt(paris('2026-10-19 15:00:13'));
    expect($state->nextSendAt()->equalTo(paris('2026-10-19 15:00:13')))->toBeTrue();

    $state->setNextSendAt(null);
    expect($state->nextSendAt())->toBeNull();
});

// --- Le bandeau ---------------------------------------------------------------------------------

it('dit qu\'un scheduler jamais passé est en retard', function () {
    $snapshot = snapshotAt('2026-10-19 10:00:00');

    expect($snapshot->lastTickAt)->toBeNull()->and($snapshot->minutesSinceLastTick)->toBeNull()->and($snapshot->stale)->toBeTrue();
});

it('est rouge au-delà de 10 minutes sans tick, pas avant', function () {
    app(SchedulerState::class)->recordTick(paris('2026-10-19 10:00:00'));

    expect(snapshotAt('2026-10-19 10:00:30')->stale)->toBeFalse()
        ->and(snapshotAt('2026-10-19 10:10:59')->minutesSinceLastTick)->toBe(10)
        ->and(snapshotAt('2026-10-19 10:10:59')->stale)->toBeFalse()
        ->and(snapshotAt('2026-10-19 10:11:00')->stale)->toBeTrue()
        ->and(snapshotAt('2026-10-19 10:11:00')->minutesSinceLastTick)->toBe(11);
});

it('compte les lignes à vérifier et les campagnes actives ou en pause', function () {
    sendingCampaign();
    sendingCampaign(attributes: ['status' => 'paused']);
    sendingCampaign(attributes: ['status' => 'draft']);

    $campaign = sendingCampaign();
    Recipient::where('campaign_id', $campaign->id)->limit(2)->get()->each->update(['status' => RecipientStatus::Uncertain]);

    $snapshot = snapshotAt('2026-10-19 10:00:00');

    expect($snapshot->uncertainCount)->toBe(2)->and($snapshot->activeCampaigns)->toBe(2)->and($snapshot->pausedCampaigns)->toBe(1);
});

it('reflète le kill switch', function () {
    expect(snapshotAt('2026-10-19 10:00:00')->enabled)->toBeTrue();

    config(['slowmail.enabled' => false]);

    expect(snapshotAt('2026-10-19 10:00:00')->enabled)->toBeFalse();
});

it('donne le prochain envoi prévu', function () {
    app(SchedulerState::class)->setNextSendAt(paris('2026-10-19 15:00:13'));

    expect(snapshotAt('2026-10-19 10:00:00')->nextSendAt->equalTo(paris('2026-10-19 15:00:13')))->toBeTrue();
});

// --- Dans slowmail:status -----------------------------------------------------------------------

function statusOutput(string $campaign, string $now): string
{
    test()->travelTo(paris($now));
    Artisan::call('slowmail:status', ['campagne' => $campaign]);

    return Artisan::output();
}

it('slowmail:status affiche le dernier passage du scheduler', function () {
    $campaign = sendingCampaign();
    $campaign->update(['name' => 'Agences']);
    app(SchedulerState::class)->recordTick(paris('2026-10-19 10:00:00'));

    $output = statusOutput('Agences', '2026-10-19 10:03:00');

    expect($output)->toContain('Scheduler : dernier passage il y a 3 min (19/10 10:00:00)')->not->toContain('en retard');
});

it('slowmail:status signale un scheduler en retard ou jamais passé', function () {
    $campaign = sendingCampaign();
    $campaign->update(['name' => 'Agences']);

    expect(statusOutput('Agences', '2026-10-19 10:00:00'))->toContain('Scheduler : jamais exécuté');

    app(SchedulerState::class)->recordTick(paris('2026-10-19 10:00:00'));

    expect(statusOutput('Agences', '2026-10-19 10:30:00'))->toContain('il y a 30 min')->toContain('en retard');
});

it('slowmail:status signale les envois désactivés, les lignes à vérifier et le prochain envoi', function () {
    config(['slowmail.enabled' => false]);
    $campaign = sendingCampaign();
    $campaign->update(['name' => 'Agences']);
    Recipient::where('campaign_id', $campaign->id)->first()->update(['status' => RecipientStatus::Uncertain]);
    app(SchedulerState::class)->setNextSendAt(paris('2026-10-19 15:00:13'));

    $output = statusOutput('Agences', '2026-10-19 10:00:00');

    expect($output)->toContain('Envois désactivés (SLOWMAIL_ENABLED=false)')
        ->toContain('1 ligne(s) incertaine(s) : à vérifier dans vos envoyés')
        ->toContain('Prochain envoi prévu : lun. 19 oct. à 15:00:13')
        ->toContain('À vérifier : 1');
});

it('slowmail:status montre la pause et les échecs consécutifs', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'consecutive_failures' => 3, 'pause_reason' => 'Disjoncteur : 3 échecs d\'envoi consécutifs.']);
    $campaign->update(['name' => 'Agences']);

    $output = statusOutput('Agences', '2026-10-19 10:00:00');

    expect($output)->toContain('En pause : Disjoncteur : 3 échecs d\'envoi consécutifs.')->toContain('Échecs d\'envoi consécutifs : 3.');
});

it('slowmail:status ne dit rien de la pause pour une campagne qui tourne', function () {
    $campaign = sendingCampaign();
    $campaign->update(['name' => 'Agences']);

    $output = statusOutput('Agences', '2026-10-19 10:00:00');

    expect($output)->not->toContain('En pause')->not->toContain('Échecs d\'envoi consécutifs');
});
