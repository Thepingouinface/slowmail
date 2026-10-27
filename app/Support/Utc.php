<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Eloquent et le query builder écrivent un Carbon avec son propre fuseau, sans le convertir :
 * toute date envoyée à la base passe par ici.
 */
final class Utc
{
    public static function sql(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i:s');
    }
}
