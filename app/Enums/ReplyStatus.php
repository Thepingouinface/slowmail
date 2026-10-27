<?php

namespace App\Enums;

enum ReplyStatus: string
{
    case Replied = 'replied';
    case Stop = 'stop';
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::Replied => 'Répondu',
            self::Stop => 'Stop',
            self::Meeting => 'Rendez-vous',
        };
    }
}
