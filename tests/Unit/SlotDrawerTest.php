<?php

use App\Services\Planning\SlotDrawer;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

function seededDrawer(int $seed): SlotDrawer
{
    return new SlotDrawer(new Randomizer(new Mt19937($seed)));
}

function parisTime(string $time, string $day = '2026-10-21'): CarbonImmutable
{
    return CarbonImmutable::parse("{$day} {$time}", 'Europe/Paris');
}

function hours(array $times): array
{
    return array_map(fn (CarbonImmutable $time) => $time->setTimezone('Europe/Paris')->format('H:i:s'), $times);
}

const FULL_DAY_GAP = 25 * 60;

it('calcule combien de mails tiennent dans une plage', function () {
    expect(SlotDrawer::capacity(8 * 3600, FULL_DAY_GAP))->toBe(20)
        ->and(SlotDrawer::capacity(60 * 60, FULL_DAY_GAP))->toBe(3)
        ->and(SlotDrawer::capacity(0, FULL_DAY_GAP))->toBe(1)
        ->and(SlotDrawer::capacity(-1, FULL_DAY_GAP))->toBe(0)
        ->and(SlotDrawer::capacity(3600, 0))->toBe(PHP_INT_MAX);
});

it('tire le bon nombre d\'heures, triées, dans la plage, séparées d\'au moins l\'écart', function () {
    $from = parisTime('09:30');
    $to = parisTime('17:30');

    foreach (range(1, 300) as $seed) {
        $times = seededDrawer($seed)->draw($from, $to, 4, FULL_DAY_GAP, true);

        expect($times)->toHaveCount(4);

        foreach ($times as $i => $time) {
            expect($time >= $from && $time <= $to)->toBeTrue("graine {$seed} : hors plage");

            if ($i > 0) {
                expect($time->getTimestamp() - $times[$i - 1]->getTimestamp())->toBeGreaterThanOrEqual(FULL_DAY_GAP, "graine {$seed} : écart trop court");
            }
        }
    }
});

it('ne tire jamais pile sur l\'heure ronde', function () {
    // Une plage qui commence à 10:00 et ne laisse que 10 minutes : le risque est maximal.
    foreach (range(1, 400) as $seed) {
        [$only] = seededDrawer($seed)->draw(parisTime('10:00'), parisTime('10:10'), 1, FULL_DAY_GAP, true);

        expect($only->format('H:i:s'))->not->toBe('10:00:00');
    }

    foreach (range(1, 100) as $seed) {
        foreach (seededDrawer($seed)->draw(parisTime('09:30'), parisTime('17:30'), 6, FULL_DAY_GAP, true) as $time) {
            expect($time->minute === 0 && $time->second === 0)->toBeFalse();
        }
    }
});

it('tire à la seconde près, pas à la minute ronde', function () {
    $seconds = [];

    foreach (range(1, 60) as $seed) {
        foreach (seededDrawer($seed)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, true) as $time) {
            $seconds[$time->second] = true;
        }
    }

    expect(count($seconds))->toBeGreaterThan(30);
});

it('répartit les heures sur toute la plage', function () {
    $earliest = parisTime('17:30');
    $latest = parisTime('09:30');

    foreach (range(1, 200) as $seed) {
        $times = seededDrawer($seed)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, true);
        $earliest = min($earliest, $times[0]);
        $latest = max($latest, $times[3]);
    }

    expect($earliest < parisTime('09:45'))->toBeTrue()
        ->and($latest > parisTime('17:10'))->toBeTrue();
});

it('est reproductible avec la même graine et varie avec une autre', function () {
    $a = hours(seededDrawer(7)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, true));
    $b = hours(seededDrawer(7)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, true));
    $c = hours(seededDrawer(8)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, true));

    expect($a)->toBe($b)->and($a)->not->toBe($c);
});

it('en tire moins quand tout ne tient pas avec l\'écart demandé', function () {
    foreach (range(1, 50) as $seed) {
        $times = seededDrawer($seed)->draw(parisTime('10:00'), parisTime('11:00'), 5, FULL_DAY_GAP, true);

        expect($times)->toHaveCount(3);

        foreach ($times as $i => $time) {
            if ($i > 0) {
                expect($time->getTimestamp() - $times[$i - 1]->getTimestamp())->toBeGreaterThanOrEqual(FULL_DAY_GAP);
            }
        }
    }
});

it('ne tire rien pour zéro mail ou une plage à l\'envers', function () {
    $drawer = seededDrawer(1);

    expect($drawer->draw(parisTime('09:30'), parisTime('17:30'), 0, FULL_DAY_GAP, true))->toBe([])
        ->and($drawer->draw(parisTime('17:30'), parisTime('09:30'), 3, FULL_DAY_GAP, true))->toBe([]);
});

it('accepte un écart nul', function () {
    $times = seededDrawer(3)->draw(parisTime('09:30'), parisTime('09:40'), 4, 0, true);

    expect($times)->toHaveCount(4);
});

it('tire des heures régulières, sans hasard, quand le jitter est coupé', function () {
    foreach ([1, 99] as $seed) {
        $times = seededDrawer($seed)->draw(parisTime('09:30'), parisTime('17:30'), 4, FULL_DAY_GAP, false);

        expect(hours($times))->toBe(['09:30:00', '12:10:00', '14:50:00', '17:30:00']);
    }

    expect(hours(seededDrawer(1)->draw(parisTime('09:30'), parisTime('17:30'), 1, FULL_DAY_GAP, false)))->toBe(['13:30:00']);
});

it('garde l\'écart minimum même sans jitter, plage serrée', function () {
    $times = seededDrawer(1)->draw(parisTime('10:00'), parisTime('11:00'), 9, FULL_DAY_GAP, false);

    expect($times)->toHaveCount(3);

    foreach ($times as $i => $time) {
        if ($i > 0) {
            expect($time->getTimestamp() - $times[$i - 1]->getTimestamp())->toBeGreaterThanOrEqual(FULL_DAY_GAP);
        }
    }
});

// --- drawAround / pickFree ---------------------------------------------------------------------

it('évite les heures déjà prises, avec l\'écart minimum', function () {
    $occupied = [parisTime('12:00:11'), parisTime('14:00:37')];

    foreach (range(1, 100) as $seed) {
        $times = seededDrawer($seed)->drawAround(parisTime('09:30'), parisTime('17:30'), 3, FULL_DAY_GAP, true, $occupied);

        expect($times)->toHaveCount(3);

        foreach ([...$times, ...$occupied] as $a) {
            foreach ([...$times, ...$occupied] as $b) {
                if ($a !== $b) {
                    expect(abs($a->getTimestamp() - $b->getTimestamp()))->toBeGreaterThanOrEqual(FULL_DAY_GAP, "graine {$seed}");
                }
            }
        }
    }
});

it('rend ce qui tient quand les heures prises ne laissent pas la place', function () {
    $occupied = [parisTime('10:30:00')];

    $times = seededDrawer(5)->drawAround(parisTime('10:00'), parisTime('11:00'), 3, FULL_DAY_GAP, true, $occupied);

    // De 10:00 à 10:05 et de 10:55 à 11:00 : deux zones de 5 minutes, une heure possible dans chacune au mieux.
    expect(count($times))->toBeLessThanOrEqual(2);
});

it('tire une heure libre des deux côtés d\'une heure prise, proportionnellement à la place', function () {
    $left = 0;
    $right = 0;

    foreach (range(1, 300) as $seed) {
        $slot = seededDrawer($seed)->pickFree(parisTime('10:00:30'), parisTime('11:00:30'), [parisTime('10:30:30')], FULL_DAY_GAP, true);

        expect($slot)->not->toBeNull();

        if ($slot <= parisTime('10:05:30')) {
            $left++;
        } else {
            expect($slot >= parisTime('10:55:30'))->toBeTrue();
            $right++;
        }
    }

    expect($left)->toBeGreaterThan(80)->and($right)->toBeGreaterThan(80);
});

it('ne trouve rien quand toute la plage est trop proche d\'une heure prise', function () {
    $slot = seededDrawer(1)->pickFree(parisTime('10:20'), parisTime('10:40'), [parisTime('10:30')], FULL_DAY_GAP, true);

    expect($slot)->toBeNull();
});

it('tient compte de plusieurs heures prises', function () {
    $occupied = [parisTime('10:15'), parisTime('10:40'), parisTime('11:05')];

    foreach (range(1, 100) as $seed) {
        $slot = seededDrawer($seed)->pickFree(parisTime('10:00'), parisTime('11:30'), $occupied, FULL_DAY_GAP, true);

        foreach ($occupied as $taken) {
            expect(abs($slot->getTimestamp() - $taken->getTimestamp()))->toBeGreaterThanOrEqual(FULL_DAY_GAP);
        }
    }
});

it('prend la première heure libre, à la minute, sans jitter', function () {
    expect(seededDrawer(1)->pickFree(parisTime('10:00:30'), parisTime('11:00'), [], FULL_DAY_GAP, false)->format('H:i:s'))->toBe('10:01:00')
        ->and(seededDrawer(1)->pickFree(parisTime('10:00:00'), parisTime('12:00'), [parisTime('10:00:00')], FULL_DAY_GAP, false)->format('H:i:s'))->toBe('10:25:00');
});

it('ne tombe jamais pile sur l\'heure ronde en cherchant une heure libre', function () {
    foreach (range(1, 300) as $seed) {
        $slot = seededDrawer($seed)->pickFree(parisTime('09:59:00'), parisTime('10:01:00'), [], FULL_DAY_GAP, true);

        expect($slot->format('H:i:s'))->not->toBe('10:00:00');
    }
});

it('respecte les heures locales d\'un fuseau à demi-heure de décalage', function () {
    $from = CarbonImmutable::parse('2026-10-21 09:30', 'Asia/Kolkata');
    $to = CarbonImmutable::parse('2026-10-21 09:40', 'Asia/Kolkata');

    foreach (range(1, 200) as $seed) {
        [$only] = seededDrawer($seed)->draw($from->setTime(10, 0), $from->setTime(10, 5), 1, FULL_DAY_GAP, true);

        // 10:00 locale à Calcutta, c'est 04:30 UTC : l'heure ronde est celle de l'horloge locale.
        expect($only->setTimezone('Asia/Kolkata')->format('H:i:s'))->not->toBe('10:00:00');
    }
});
