<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * Tire des heures d'envoi dans une plage, séparées d'au moins l'écart minimum.
 *
 * Avec jitter : heures aléatoires à la seconde près, jamais pile sur l'heure ronde.
 * Sans jitter : heures régulières, sans hasard (utile pour comprendre un plan ou tester).
 * Les instants reçus sont dans le fuseau de la campagne : « l'heure ronde » est celle de l'horloge locale.
 */
final class SlotDrawer
{
    /** Garde-fou de la boucle qui évite l'heure ronde. */
    private const MAX_ATTEMPTS = 200;

    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    /** Combien de mails tiennent dans une plage de cette durée, avec cet écart. */
    public static function capacity(int $spanSeconds, int $gapSeconds): int
    {
        if ($spanSeconds < 0) {
            return 0;
        }

        return $gapSeconds > 0 ? intdiv($spanSeconds, $gapSeconds) + 1 : PHP_INT_MAX;
    }

    /**
     * Jusqu'à $count heures triées dans [$from, $to], uniformes sur la plage entière. Moins s'il n'y a pas la place.
     *
     * @return list<CarbonImmutable>
     */
    public function draw(CarbonImmutable $from, CarbonImmutable $to, int $count, int $gapSeconds, bool $jitter): array
    {
        $span = $to->getTimestamp() - $from->getTimestamp();
        $n = min($count, self::capacity($span, $gapSeconds));

        if ($n <= 0) {
            return [];
        }

        if (! $jitter) {
            return $this->evenly($from, $span, $n);
        }

        // $n points dans la place qui reste une fois les écarts réservés, triés, puis décalés de i × écart.
        $room = $span - ($n - 1) * $gapSeconds;
        $times = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $offsets = [];

            for ($i = 0; $i < $n; $i++) {
                $offsets[] = $this->random->getInt(0, $room);
            }

            sort($offsets);

            $times = array_map(
                fn (int $offset, int $i) => $from->addSeconds($offset + $i * $gapSeconds),
                $offsets,
                array_keys($offsets),
            );

            if (! $this->anyRoundHour($times)) {
                break;
            }
        }

        return $times;
    }

    /**
     * Comme draw(), en évitant des heures déjà prises (mails déjà partis ou planifiés).
     * Les heures sont tirées une à une : si elles ne tiennent pas toutes du premier coup, plusieurs essais.
     *
     * @param  list<CarbonImmutable>  $occupied
     * @return list<CarbonImmutable>
     */
    public function drawAround(CarbonImmutable $from, CarbonImmutable $to, int $count, int $gapSeconds, bool $jitter, array $occupied): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($occupied === []) {
            return $this->draw($from, $to, $count, $gapSeconds, $jitter);
        }

        $best = [];

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $placed = [];
            $taken = $occupied;

            for ($i = 0; $i < $count; $i++) {
                $slot = $this->pickFree($from, $to, $taken, $gapSeconds, $jitter);

                if ($slot === null) {
                    break;
                }

                $placed[] = $slot;
                $taken[] = $slot;
            }

            if (count($placed) > count($best)) {
                $best = $placed;
            }

            // Sans hasard, un second essai donnerait le même résultat.
            if (count($best) === $count || ! $jitter) {
                break;
            }
        }

        usort($best, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $best;
    }

    /**
     * Une heure libre dans [$from, $to] : à au moins $gapSeconds de chaque heure déjà prise.
     * Uniforme sur l'espace libre avec jitter ; la plus proche (à la minute) sans. Null : plus de place.
     *
     * @param  list<CarbonImmutable>  $occupied
     */
    public function pickFree(CarbonImmutable $from, CarbonImmutable $to, array $occupied, int $gapSeconds, bool $jitter): ?CarbonImmutable
    {
        $segments = [[$from->getTimestamp(), $to->getTimestamp()]];

        if ($gapSeconds > 0) {
            foreach ($occupied as $taken) {
                $ts = $taken->getTimestamp();
                $next = [];

                // Interdit : l'intervalle ouvert ]ts - écart, ts + écart[.
                foreach ($segments as [$lo, $hi]) {
                    $left = min($hi, $ts - $gapSeconds);
                    $right = max($lo, $ts + $gapSeconds);

                    if ($lo <= $left) {
                        $next[] = [$lo, $left];
                    }

                    if ($right <= $hi) {
                        $next[] = [$right, $hi];
                    }
                }

                $segments = $next;
            }
        }

        if ($segments === []) {
            return null;
        }

        if (! $jitter) {
            [$lo, $hi] = $segments[0];
            $aligned = intdiv($lo + 59, 60) * 60;

            return $from->setTimestamp($aligned <= $hi ? $aligned : $lo);
        }

        $total = array_sum(array_map(fn (array $segment) => $segment[1] - $segment[0] + 1, $segments));
        $candidate = $from;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $r = $this->random->getInt(0, $total - 1);

            foreach ($segments as [$lo, $hi]) {
                $length = $hi - $lo + 1;

                if ($r < $length) {
                    $candidate = $from->setTimestamp($lo + $r);

                    break;
                }

                $r -= $length;
            }

            if (! $this->isRoundHour($candidate)) {
                break;
            }
        }

        return $candidate;
    }

    /** @return list<CarbonImmutable> */
    private function evenly(CarbonImmutable $from, int $span, int $n): array
    {
        if ($n === 1) {
            return [$from->addSeconds(intdiv($span, 2))];
        }

        $times = [];

        for ($i = 0; $i < $n; $i++) {
            $times[] = $from->addSeconds(intdiv($i * $span, $n - 1));
        }

        return $times;
    }

    /** @param  list<CarbonImmutable>  $times */
    private function anyRoundHour(array $times): bool
    {
        foreach ($times as $time) {
            if ($this->isRoundHour($time)) {
                return true;
            }
        }

        return false;
    }

    private function isRoundHour(CarbonImmutable $time): bool
    {
        return $time->minute === 0 && $time->second === 0;
    }
}
