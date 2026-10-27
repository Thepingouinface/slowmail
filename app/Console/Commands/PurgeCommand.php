<?php

namespace App\Console\Commands;

use App\Services\Sending\Purger;
use Illuminate\Console\Command;

class PurgeCommand extends Command
{
    protected $signature = 'slowmail:purge {--days= : Âge minimum des campagnes terminées, en jours (défaut : 90)}';

    protected $description = 'Efface les rendus et les données des campagnes terminées depuis plus de N jours';

    public function handle(Purger $purger): int
    {
        $option = $this->option('days');
        $days = $option === null || $option === '' ? (int) config('slowmail.purge_days') : $option;

        if (! is_numeric($days) || (int) $days < 0) {
            $this->components->error('--days doit être un nombre de jours, 0 ou plus.');

            return self::FAILURE;
        }

        $result = $purger->purge((int) $days);

        $this->components->info(sprintf(
            '%d ligne(s) purgée(s) dans %d campagne(s) terminée(s) depuis plus de %d jour(s).',
            $result['recipients'],
            $result['campaigns'],
            (int) $days,
        ));

        return self::SUCCESS;
    }
}
