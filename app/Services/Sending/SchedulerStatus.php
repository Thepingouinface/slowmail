<?php

namespace App\Services\Sending;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SchedulerStatus
{
    public function __construct(private readonly SchedulerState $state) {}

    public function snapshot(?CarbonInterface $now = null): SchedulerSnapshot
    {
        $now = CarbonImmutable::instance($now ?? now());
        $last = $this->state->lastTickAt();
        $minutes = $last === null ? null : max(0, intdiv($now->getTimestamp() - $last->getTimestamp(), 60));

        return new SchedulerSnapshot(
            enabled: (bool) config('slowmail.enabled'),
            lastTickAt: $last,
            minutesSinceLastTick: $minutes,
            // Jamais exécuté, ou en retard : rouge.
            stale: $minutes === null || $minutes > (int) config('slowmail.sending.stale_tick_minutes'),
            uncertainCount: Recipient::query()->where('status', RecipientStatus::Uncertain->value)->count(),
            nextSendAt: $this->state->nextSendAt(),
            activeCampaigns: Campaign::query()->where('status', CampaignStatus::Active->value)->count(),
            pausedCampaigns: Campaign::query()->where('status', CampaignStatus::Paused->value)->count(),
        );
    }
}
