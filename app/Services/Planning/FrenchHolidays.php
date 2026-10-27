<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours fériés de la France métropolitaine, calculés (aucun réseau, aucune extension).
 * Les jours propres à l'Alsace-Moselle (Vendredi saint, 26 décembre) n'y figurent pas.
 */
final class FrenchHolidays
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    /** @return array<string, string> date (Y-m-d) => nom */
    public function forYear(int $year): array
    {
        return $this->cache[$year] ??= $this->compute($year);
    }

    /** @param  CarbonInterface  $date  un jour, déjà exprimé dans le fuseau voulu */
    public function isHoliday(CarbonInterface $date): bool
    {
        return $this->name($date) !== null;
    }

    public function name(CarbonInterface $date): ?string
    {
        return $this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')] ?? null;
    }

    /** @return array<string, string> */
    private function compute(int $year): array
    {
        $easter = $this->easter($year);

        return [
            "{$year}-01-01" => 'Jour de l\'an',
            $easter->addDays(1)->format('Y-m-d') => 'Lundi de Pâques',
            "{$year}-05-01" => 'Fête du Travail',
            "{$year}-05-08" => 'Victoire 1945',
            $easter->addDays(39)->format('Y-m-d') => 'Ascension',
            $easter->addDays(50)->format('Y-m-d') => 'Lundi de Pentecôte',
            "{$year}-07-14" => 'Fête nationale',
            "{$year}-08-15" => 'Assomption',
            "{$year}-11-01" => 'Toussaint',
            "{$year}-11-11" => 'Armistice 1918',
            "{$year}-12-25" => 'Noël',
        ];
    }

    /** Dimanche de Pâques, calendrier grégorien (Meeus/Jones/Butcher). */
    private function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
