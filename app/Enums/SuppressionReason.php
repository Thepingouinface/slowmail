<?php

namespace App\Enums;

enum SuppressionReason: string
{
    case Stop = 'stop';
    case Bounce = 'bounce';
    case Manual = 'manuel';

    public function label(): string
    {
        return match ($this) {
            self::Stop => 'stop',
            self::Bounce => 'rebond',
            self::Manual => 'manuel',
        };
    }
}
