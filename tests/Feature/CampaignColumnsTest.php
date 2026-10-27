<?php

use App\Services\Import\CsvImporter;

function importCsv($campaign, string $csv): int
{
    $importer = app(CsvImporter::class);

    return $importer->commit($campaign, $importer->analyze($campaign, csvFile($csv)));
}

it('mémorise les colonnes du CSV comme variables de la campagne, sans la colonne des adresses', function () {
    $campaign = campaign();

    importCsv($campaign, "EMAIL;PRENOM;STRUCTURE\na@x.example;Marie;Lumière\n");

    expect($campaign->fresh()->columns)->toBe(['PRENOM', 'STRUCTURE']);
});

it('fusionne les colonnes d\'un second import, sans doublon de casse', function () {
    $campaign = campaign();

    importCsv($campaign, "EMAIL;PRENOM;STRUCTURE\na@x.example;Marie;Lumière\n");
    importCsv($campaign, "EMAIL;prenom;Ville\nb@x.example;Paul;Lille\n");

    expect($campaign->fresh()->columns)->toBe(['PRENOM', 'STRUCTURE', 'Ville']);
});

it('ne mémorise aucune colonne quand rien n\'est importé', function () {
    $campaign = campaign();

    importCsv($campaign, "EMAIL;PRENOM\nnope;Marie\n");

    expect($campaign->fresh()->columns)->toBeNull();
});
