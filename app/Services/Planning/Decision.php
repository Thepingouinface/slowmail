<?php

namespace App\Services\Planning;

use App\Enums\DecisionKind;
use App\Models\Recipient;
use Carbon\CarbonImmutable;

/** Ce que le planificateur décide pour une campagne à un instant donné. */
final class Decision
{
    private function __construct(
        public readonly DecisionKind $kind,
        public readonly ?Recipient $recipient = null,
        public readonly ?string $reason = null,
        public readonly ?CarbonImmutable $nextAt = null,
    ) {}

    public static function send(Recipient $recipient): self
    {
        return new self(DecisionKind::Send, $recipient);
    }

    public static function wait(string $reason, ?CarbonImmutable $nextAt = null): self
    {
        return new self(DecisionKind::Wait, null, $reason, $nextAt);
    }

    public static function finished(string $reason): self
    {
        return new self(DecisionKind::Finished, null, $reason);
    }

    public static function blocked(string $reason): self
    {
        return new self(DecisionKind::Blocked, null, $reason);
    }

    public function shouldSend(): bool
    {
        return $this->kind === DecisionKind::Send;
    }
}
