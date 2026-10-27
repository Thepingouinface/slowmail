<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCampaign;
use App\Services\Sending\CampaignLifecycle;
use Illuminate\Console\Command;

class CampaignCommand extends Command
{
    use FindsCampaign;

    protected $signature = 'slowmail:campaign
        {campagne : Identifiant ou nom exact de la campagne}
        {action : activate, pause ou resume}';

    protected $description = 'Active, met en pause ou reprend une campagne';

    public function handle(CampaignLifecycle $lifecycle): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['activate', 'pause', 'resume'], true)) {
            $this->components->error('Action inconnue : utilisez activate, pause ou resume.');

            return self::FAILURE;
        }

        $campaign = $this->findCampaign((string) $this->argument('campagne'));

        if ($campaign === null) {
            return self::FAILURE;
        }

        if ($action === 'pause') {
            if (! $lifecycle->pause($campaign, 'Mise en pause à la main.')) {
                $this->components->error('Seule une campagne active peut être mise en pause.');

                return self::FAILURE;
            }

            $this->components->info("Campagne « {$campaign->name} » mise en pause.");

            return self::SUCCESS;
        }

        $problems = $action === 'activate' ? $lifecycle->activate($campaign) : $lifecycle->resume($campaign);

        if ($problems !== []) {
            $this->components->error('La campagne ne peut pas démarrer :');

            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->components->info("Campagne « {$campaign->name} » ".($action === 'activate' ? 'activée' : 'reprise').'.');

        if (! config('slowmail.enabled')) {
            $this->components->warn('SLOWMAIL_ENABLED n\'est pas « true » : aucun mail ne partira tant que le kill switch est coupé.');
        }

        return self::SUCCESS;
    }
}
