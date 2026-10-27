<?php

namespace App\Console\Commands;

use App\Services\Sending\Journal;
use App\Services\Sending\TickRunner;
use Illuminate\Console\Command;

class TickCommand extends Command
{
    protected $signature = 'slowmail:tick';

    protected $description = 'Un passage du scheduler : planifie, puis envoie au plus un mail dû';

    public function handle(TickRunner $runner): int
    {
        $report = $runner->run();

        if ($report->busy) {
            $this->line('Un autre passage est déjà en cours : rien à faire.');

            return self::SUCCESS;
        }

        if ($report->interruptionMinutes !== null) {
            $this->components->warn('Interruption détectée ('.Journal::duration($report->interruptionMinutes).') : aucun passage du scheduler pendant ce temps.');
        }

        if ($report->uncertain > 0) {
            $this->components->warn("{$report->uncertain} envoi(s) interrompu(s) en plein vol : à vérifier dans vos envoyés (jamais renvoyés automatiquement).");
        }

        if ($report->disabled) {
            $this->line('Envois désactivés (SLOWMAIL_ENABLED=false) : rien n\'est envoyé.');
        }

        // Pas d'adresses ici : la sortie finit dans les journaux de Docker.
        foreach ($report->deliveries as $delivery) {
            $this->line("Campagne {$delivery['campaign']}, ligne {$delivery['recipient']} : {$delivery['outcome']->label()}.");
        }

        foreach ($report->finished as $finished) {
            $this->line("Campagne {$finished['campaign']} terminée : {$finished['reason']}");
        }

        foreach ($report->paused as $paused) {
            $this->components->warn("Campagne {$paused['campaign']} mise en pause : {$paused['reason']}");
        }

        return self::SUCCESS;
    }
}
