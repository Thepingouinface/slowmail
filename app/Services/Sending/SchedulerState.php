<?php

namespace App\Services\Sending;

use App\Support\Utc;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Le battement du scheduler et le prochain envoi prévu, partagés avec l'interface (table `scheduler_state`). */
final class SchedulerState
{
    private const LAST_TICK = 'last_tick_at';

    private const NEXT_SEND = 'next_send_at';

    public function lastTickAt(): ?CarbonImmutable
    {
        return $this->time(self::LAST_TICK);
    }

    /**
     * Note ce tick et rend le précédent, pour mesurer l'interruption entre les deux.
     */
    public function recordTick(CarbonInterface $at): ?CarbonImmutable
    {
        $previous = $this->lastTickAt();

        $this->put(self::LAST_TICK, Utc::sql($at));

        return $previous;
    }

    public function nextSendAt(): ?CarbonImmutable
    {
        return $this->time(self::NEXT_SEND);
    }

    public function setNextSendAt(?CarbonInterface $at): void
    {
        $this->put(self::NEXT_SEND, $at === null ? null : Utc::sql($at));
    }

    private function time(string $key): ?CarbonImmutable
    {
        $value = DB::table('scheduler_state')->where('key', $key)->value('value');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    private function put(string $key, ?string $value): void
    {
        // Atomique (INSERT … ON CONFLICT) : deux premiers passages simultanés ne se disputent pas la même clé.
        DB::table('scheduler_state')->upsert(
            [['key' => $key, 'value' => $value, 'updated_at' => Utc::sql(now())]],
            ['key'],
            ['value', 'updated_at'],
        );
    }
}
