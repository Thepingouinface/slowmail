<?php

namespace App\Services\Sending;

use Carbon\CarbonImmutable;

/** Ce que montre le bandeau d'état (addendum §6) : de quoi savoir d'un coup d'œil si tout tourne. */
final class SchedulerSnapshot
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?CarbonImmutable $lastTickAt,
        public readonly ?int $minutesSinceLastTick,
        public readonly bool $stale,
        public readonly int $uncertainCount,
        public readonly ?CarbonImmutable $nextSendAt,
        public readonly int $activeCampaigns,
        public readonly int $pausedCampaigns,
    ) {}
}
