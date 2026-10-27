<?php

namespace App\Services\Sending;

use App\Enums\DeliveryOutcome;

/** Ce qu'un tick a fait, pour l'affichage de `slowmail:tick` et pour les tests. */
final class TickReport
{
    public bool $busy = false;

    public bool $disabled = false;

    /** Lignes passées en `uncertain` parce qu'elles étaient en `sending` depuis trop longtemps. */
    public int $uncertain = 0;

    /** Minutes écoulées depuis le tick précédent, quand l'écart a dépassé le seuil d'interruption. */
    public ?int $interruptionMinutes = null;

    /** @var list<array{campaign: int, recipient: int, outcome: DeliveryOutcome, reason: ?string}> */
    public array $deliveries = [];

    /** @var list<array{campaign: int, reason: string}> */
    public array $finished = [];

    /** @var list<array{campaign: int, reason: string}> */
    public array $paused = [];

    public static function busy(): self
    {
        $report = new self;
        $report->busy = true;

        return $report;
    }

    /** Le nombre de mails réellement partis pendant ce tick. */
    public function sent(): int
    {
        return count(array_filter($this->deliveries, fn (array $d) => $d['outcome'] === DeliveryOutcome::Sent));
    }
}
