<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;

final class ProjectedDay
{
    /**
     * @param  CarbonImmutable  $date  début du jour, dans le fuseau de la campagne
     * @param  bool  $exact  true : plan déjà tiré, les heures sont celles de la base ; false : prévision, heures tirées le jour même
     * @param  list<array{time: ?CarbonImmutable, id: int, position: int, email: string}>  $entries
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly bool $exact,
        public readonly array $entries,
    ) {}
}
