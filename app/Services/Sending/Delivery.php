<?php

namespace App\Services\Sending;

use App\Enums\DeliveryOutcome;

/** Le résultat de l'envoi d'une ligne. */
final class Delivery
{
    /** @param  bool  $pausedCampaign  le disjoncteur vient de mettre la campagne en pause */
    private function __construct(
        public readonly DeliveryOutcome $outcome,
        public readonly ?string $reason = null,
        public readonly bool $pausedCampaign = false,
    ) {}

    public static function of(DeliveryOutcome $outcome, ?string $reason = null, bool $pausedCampaign = false): self
    {
        return new self($outcome, $reason, $pausedCampaign);
    }
}
