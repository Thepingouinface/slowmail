<?php

namespace App\Enums;

enum SuppressionType: string
{
    case Email = 'email';
    case Domain = 'domain';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Adresse',
            self::Domain => 'Domaine',
        };
    }
}
