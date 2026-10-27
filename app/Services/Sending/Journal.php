<?php

namespace App\Services\Sending;

use App\Models\Campaign;
use App\Models\SendLog;

/**
 * Le journal d'une campagne (`send_logs`). Jamais le corps d'un mail, jamais d'identifiants :
 * le détail est une phrase, et une ligne se désigne par son identifiant.
 */
final class Journal
{
    public static function record(Campaign|int $campaign, string $event, string $detail, ?int $recipientId = null): void
    {
        SendLog::create([
            'campaign_id' => $campaign instanceof Campaign ? $campaign->id : $campaign,
            'recipient_id' => $recipientId,
            'event' => $event,
            'detail' => $detail,
        ]);
    }

    /** « 23 min », « 6 h », « 6 h 12 min », « 2 j 3 h ». */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        if ($minutes >= 48 * 60) {
            $days = intdiv($minutes, 1440);
            $hours = intdiv($minutes % 1440, 60);

            return $hours === 0 ? "{$days} j" : "{$days} j {$hours} h";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} h" : "{$hours} h {$rest} min";
    }
}
