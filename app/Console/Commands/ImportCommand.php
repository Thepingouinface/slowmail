<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCampaign;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportAnalysis;
use App\Services\Import\ImportException;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    use FindsCampaign;

    protected $signature = 'slowmail:import
        {campagne : Identifiant ou nom exact de la campagne}
        {fichier : Chemin du fichier CSV}
        {--email-column=EMAIL : Nom de la colonne des adresses}
        {--include-contacted : Importer aussi les adresses déjà contactées dans une autre campagne}
        {--check : Analyser et afficher le rapport, sans rien importer}';

    protected $description = 'Importe un CSV de destinataires dans une campagne';

    public function handle(CsvImporter $importer): int
    {
        $campaign = $this->findCampaign((string) $this->argument('campagne'));

        if ($campaign === null) {
            return self::FAILURE;
        }

        $file = (string) $this->argument('fichier');

        if (! is_file($file) || ! is_readable($file)) {
            $this->components->error("Fichier introuvable ou illisible : {$file}");

            return self::FAILURE;
        }

        try {
            $analysis = $importer->analyze($campaign, $file, (string) $this->option('email-column'));
        } catch (ImportException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($analysis);

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        $include = (bool) $this->option('include-contacted');

        if ($analysis->contacted !== [] && ! $include && $this->input->isInteractive()) {
            $include = $this->confirm(sprintf('Importer aussi les %d adresses déjà contactées ?', count($analysis->contacted)), false);
        }

        $imported = $importer->commit($campaign, $analysis, $include);

        $this->components->info("{$imported} ligne(s) importée(s) dans « {$campaign->name} ».");

        if ($analysis->contacted !== [] && ! $include) {
            $this->components->warn(count($analysis->contacted).' adresse(s) déjà contactée(s) ignorée(s) (--include-contacted pour les importer).');
        }

        return self::SUCCESS;
    }

    private function report(ImportAnalysis $analysis): void
    {
        $this->line(sprintf(
            'Séparateur « %s », %d ligne(s) lue(s), variables : %s',
            $analysis->delimiter,
            $analysis->totalRows,
            $analysis->columns === [] ? 'aucune' : implode(', ', $analysis->columns),
        ));

        foreach ($analysis->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->table(['', 'Lignes'], [
            ['À importer', count($analysis->importable)],
            ['Déjà contactées (confirmation)', count($analysis->contacted)],
            ['Rejetées', count($analysis->rejected)],
        ]);

        if ($analysis->rejected !== []) {
            $this->table(
                ['Ligne', 'Adresse', 'Raison'],
                array_map(fn (array $row) => [$row['line'], $row['email'], $row['reason']], $analysis->rejected),
            );
        }

        if ($analysis->contacted !== []) {
            $this->table(
                ['Ligne', 'Adresse', 'Déjà contactée dans'],
                array_map(fn (array $row) => [$row['line'], $row['email'], $row['campaign']], $analysis->contacted),
            );
        }
    }
}
