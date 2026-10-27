<?php

namespace App\Services\Sending;

use App\Models\Suppression;

final class SuppressionResult
{
    /**
     * @param  bool  $created  false : la valeur était déjà dans la liste
     * @param  int  $affected  lignes en attente ou planifiées, de toutes les campagnes, retirées de l'envoi
     */
    public function __construct(
        public readonly Suppression $suppression,
        public readonly bool $created,
        public readonly int $affected,
    ) {}
}
