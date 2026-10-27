<?php

namespace App\Console\Commands\Concerns;

use App\Models\Campaign;

/** Désigne une campagne par son identifiant ou son nom exact. */
trait FindsCampaign
{
    private function findCampaign(string $reference): ?Campaign
    {
        $matches = ctype_digit($reference)
            ? Campaign::query()->whereKey((int) $reference)->get()
            : Campaign::query()->where('name', $reference)->get();

        if ($matches->count() > 1) {
            $this->components->error("Plusieurs campagnes s'appellent « {$reference} » : utilisez leur identifiant.");

            return null;
        }

        if ($matches->isEmpty()) {
            $this->components->error("Campagne introuvable : {$reference}");

            return null;
        }

        return $matches->first();
    }
}
