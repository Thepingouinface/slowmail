<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;

final class Projection
{
    /**
     * @param  list<ProjectedDay>  $days  jours d'envoi prévus, dans l'ordre
     * @param  ?CarbonImmutable  $estimatedEnd  dernier jour d'envoi prévu, quand la campagne s'épuise ou atteint son maximum
     * @param  ?string  $stoppedBy  `end_at` : la date de fin arrête la campagne avant la dernière ligne ; `max_total` : le maximum est atteint
     * @param  int  $leftOver  lignes qui resteront sans contact quand la date de fin arrête la campagne
     * @param  ?string  $blocked  réglages inutilisables : pas de prévision
     */
    public function __construct(
        public readonly array $days,
        public readonly ?CarbonImmutable $estimatedEnd,
        public readonly ?string $stoppedBy = null,
        public readonly int $leftOver = 0,
        public readonly ?string $blocked = null,
    ) {}

    public function totalPlanned(): int
    {
        return array_sum(array_map(fn (ProjectedDay $day) => count($day->entries), $this->days));
    }
}
