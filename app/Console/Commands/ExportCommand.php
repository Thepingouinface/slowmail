<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCampaign;
use App\Services\Export\CsvExporter;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    use FindsCampaign;

    protected $signature = 'slowmail:export
        {campagne : Identifiant ou nom exact de la campagne}
        {fichier : Chemin du CSV à écrire}
        {--force : Écrase le fichier s\'il existe déjà}';

    protected $description = 'Exporte une campagne en CSV, avec les colonnes STATUT, DATE_ENVOI, REPONSE et NOTES';

    public function handle(CsvExporter $exporter): int
    {
        $campaign = $this->findCampaign((string) $this->argument('campagne'));

        if ($campaign === null) {
            return self::FAILURE;
        }

        $path = (string) $this->argument('fichier');

        if (file_exists($path) && ! $this->option('force')) {
            $this->components->error("{$path} existe déjà : choisissez un autre nom, ou ajoutez --force pour l'écraser.");

            return self::FAILURE;
        }

        $stream = @fopen($path, 'w');

        if ($stream === false) {
            $this->components->error("Impossible d'écrire {$path} : vérifiez le dossier et ses droits.");

            return self::FAILURE;
        }

        // Des adresses et des notes : lisible par l'utilisateur seul.
        @chmod($path, 0600);

        $count = $exporter->write($stream, $campaign);
        fclose($stream);

        $this->components->info("{$count} ligne(s) de « {$campaign->name} » exportée(s) dans {$path}.");

        return self::SUCCESS;
    }
}
