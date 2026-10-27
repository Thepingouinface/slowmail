<?php

namespace App\Console\Commands;

use App\Enums\SuppressionReason;
use App\Services\Sending\Suppressions;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SuppressCommand extends Command
{
    protected $signature = 'slowmail:suppress
        {valeur : Une adresse, ou @domaine pour un domaine entier}
        {--reason=stop : Motif : stop, bounce ou manuel}';

    protected $description = 'Ajoute une adresse ou un domaine à la liste de suppression';

    public function handle(Suppressions $suppressions): int
    {
        $reason = SuppressionReason::tryFrom((string) $this->option('reason'));

        if ($reason === null) {
            $this->components->error('Motif inconnu : utilisez stop, bounce ou manuel.');

            return self::FAILURE;
        }

        try {
            $result = $suppressions->add((string) $this->argument('valeur'), $reason);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $value = ($result->suppression->type->value === 'domain' ? '@' : '').$result->suppression->value;

        if (! $result->created) {
            $this->components->info("{$value} est déjà dans la liste de suppression ({$result->suppression->reason->label()}).");
        } else {
            $this->components->info("{$value} ajouté à la liste de suppression ({$reason->label()}).");
        }

        if ($result->affected > 0) {
            $this->line("{$result->affected} ligne(s) en attente ou planifiée(s) retirée(s) de l'envoi.");
        }

        return self::SUCCESS;
    }
}
