<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'Active',
            self::Paused => 'En pause',
            self::Finished => 'Terminée',
        };
    }

    /** La couleur de la pastille : `neutral`, `ok`, `warn`, `error` ou `info`. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Active => 'ok',
            self::Paused => 'warn',
            self::Finished => 'info',
        };
    }
}
