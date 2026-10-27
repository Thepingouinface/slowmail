<?php

use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Models\Suppression;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportAnalysis;
use App\Services\Import\ImportException;

function analyze(string $csv, ?Campaign $campaign = null, string $emailColumn = 'EMAIL'): ImportAnalysis
{
    return app(CsvImporter::class)->analyze($campaign ?? campaign(), csvFile($csv), $emailColumn);
}

function emails(array $rows): array
{
    return array_column($rows, 'email');
}

it('lit un CSV à point-virgule avec BOM et accents', function () {
    $analysis = analyze("\xEF\xBB\xBFEMAIL;PRENOM;STRUCTURE\nmarie@lumiere.example;Élodie;Agence Lumière\n");

    expect($analysis->delimiter)->toBe(';')
        ->and($analysis->columns)->toBe(['PRENOM', 'STRUCTURE'])
        ->and($analysis->importable)->toHaveCount(1)
        ->and($analysis->importable[0]['data'])->toBe(['PRENOM' => 'Élodie', 'STRUCTURE' => 'Agence Lumière']);
});

it('lit un CSV à virgule, avec des guillemets et un saut de ligne dans une cellule', function () {
    $analysis = analyze("EMAIL,NOM,NOTE\na@x.example,\"Dupont, Jean\",\"ligne 1\nligne 2\"\nb@x.example,Martin,ok\n");

    expect($analysis->delimiter)->toBe(',')
        ->and($analysis->importable)->toHaveCount(2)
        ->and($analysis->importable[0]['data']['NOM'])->toBe('Dupont, Jean')
        ->and($analysis->importable[0]['data']['NOTE'])->toBe("ligne 1\nligne 2")
        ->and($analysis->importable[1]['line'])->toBe(3);
});

it('ne prend pas une virgule dans un champ entre guillemets pour le séparateur', function () {
    $analysis = analyze("EMAIL;NOM\na@x.example;\"Dupont, Jean, Paul\"\n");

    expect($analysis->delimiter)->toBe(';')
        ->and($analysis->importable[0]['data']['NOM'])->toBe('Dupont, Jean, Paul');
});

it('normalise l\'adresse : espaces, casse, domaine', function () {
    $analysis = analyze("EMAIL\n  Jean.Petit@Mairie.Example  \n");

    expect($analysis->importable[0])->toMatchArray(['email' => 'jean.petit@mairie.example', 'domain' => 'mairie.example']);
});

it('rejette les adresses vides, invalides et non ASCII avec la raison', function () {
    $analysis = analyze("EMAIL;NOM\n;Anne\ncontact@@x.example;Luc\npas-une-adresse;Zoé\nélodie@x.example;Élodie\nok@x.example;Ok\n");

    expect(emails($analysis->importable))->toBe(['ok@x.example'])
        ->and(array_column($analysis->rejected, 'reason'))->toBe([
            'adresse vide',
            'adresse invalide',
            'adresse invalide',
            'caractères accentués ou non ASCII dans l\'adresse (non pris en charge)',
        ])
        ->and(array_column($analysis->rejected, 'line'))->toBe([2, 3, 4, 5]);
});

it('signale les doublons du fichier, sans tenir compte de la casse', function () {
    $analysis = analyze("EMAIL\na@x.example\nb@x.example\nA@X.example\n");

    expect(emails($analysis->importable))->toBe(['a@x.example', 'b@x.example'])
        ->and($analysis->rejected)->toHaveCount(1)
        ->and($analysis->rejected[0])->toMatchArray([
            'line' => 4,
            'email' => 'a@x.example',
            'reason' => 'doublon dans le fichier (déjà vue ligne 2)',
        ]);
});

it('rejette les adresses déjà présentes dans la campagne', function () {
    $campaign = campaign();
    recipient($campaign, 'deja@x.example');

    $analysis = analyze("EMAIL\ndeja@x.example\nnouvelle@x.example\n", $campaign);

    expect(emails($analysis->importable))->toBe(['nouvelle@x.example'])
        ->and($analysis->rejected[0]['reason'])->toBe('déjà présente dans cette campagne');
});

it('rejette les adresses et les domaines de la liste de suppression', function () {
    Suppression::create(['value' => 'stop@x.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);
    Suppression::create(['value' => 'reseau.example', 'type' => SuppressionType::Domain, 'reason' => SuppressionReason::Manual]);

    $analysis = analyze("EMAIL\nSTOP@x.example\nquelquun@reseau.example\nautre@x.example\n");

    expect(emails($analysis->importable))->toBe(['autre@x.example'])
        ->and(array_column($analysis->rejected, 'reason'))->toBe([
            'dans la liste de suppression (stop)',
            'dans la liste de suppression (manuel)',
        ]);
});

it('met à part les adresses déjà contactées dans une autre campagne', function () {
    $other = campaign('Ancienne campagne');
    recipient($other, 'envoye@x.example', RecipientStatus::Sent);
    recipient($other, 'incertain@x.example', RecipientStatus::Uncertain);
    recipient($other, 'en-attente@x.example', RecipientStatus::Pending);
    recipient($other, 'echec@x.example', RecipientStatus::Failed);

    $analysis = analyze("EMAIL\nenvoye@x.example\nincertain@x.example\nen-attente@x.example\nechec@x.example\njamais@x.example\n");

    expect(emails($analysis->contacted))->toBe(['envoye@x.example', 'incertain@x.example'])
        ->and($analysis->contacted[0]['campaign'])->toBe('Ancienne campagne')
        ->and(emails($analysis->importable))->toBe(['en-attente@x.example', 'echec@x.example', 'jamais@x.example']);
});

it('n\'importe les adresses déjà contactées que sur confirmation', function () {
    $other = campaign('Ancienne campagne');
    recipient($other, 'envoye@x.example', RecipientStatus::Sent);
    $target = campaign('Nouvelle campagne');
    $importer = app(CsvImporter::class);
    $path = csvFile("EMAIL\nenvoye@x.example\nneuf@x.example\n");

    $imported = $importer->commit($target, $importer->analyze($target, $path));
    expect($imported)->toBe(1)
        ->and($target->recipients()->orderBy('position')->pluck('email')->all())->toBe(['neuf@x.example']);

    $imported = $importer->commit($target, $importer->analyze($target, $path), includeContacted: true);
    expect($imported)->toBe(1)
        ->and($target->recipients()->orderBy('position')->pluck('email')->all())->toBe(['neuf@x.example', 'envoye@x.example']);
});

it('range les lignes par PRIORITE puis par ordre du fichier, les sans priorité à la fin', function () {
    $campaign = campaign();
    $csv = "EMAIL;PRIORITE\nc@x.example;\nb@x.example;2\na@x.example;1\nd@x.example;2\ne@x.example;1,5\n";

    $analysis = analyze($csv, $campaign);
    app(CsvImporter::class)->commit($campaign, $analysis);

    expect($campaign->recipients()->orderBy('position')->pluck('email')->all())
        ->toBe(['a@x.example', 'e@x.example', 'b@x.example', 'd@x.example', 'c@x.example'])
        ->and($campaign->recipients()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3, 4, 5]);
});

it('garde l\'ordre du fichier sans colonne PRIORITE', function () {
    $campaign = campaign();

    app(CsvImporter::class)->commit($campaign, analyze("EMAIL\nz@x.example\na@x.example\nm@x.example\n", $campaign));

    expect($campaign->recipients()->orderBy('position')->pluck('email')->all())
        ->toBe(['z@x.example', 'a@x.example', 'm@x.example']);
});

it('signale une PRIORITE qui n\'est pas un nombre et place la ligne en fin de liste', function () {
    $analysis = analyze("EMAIL;PRIORITE\na@x.example;urgent\nb@x.example;1\n");

    expect($analysis->warnings)->toHaveCount(1)
        ->and($analysis->warnings[0])->toContain('Ligne 2')
        ->and($analysis->importable[0]['priority'])->toBeNull();
});

it('ajoute les nouvelles lignes après celles déjà présentes', function () {
    $campaign = campaign();
    $importer = app(CsvImporter::class);

    $importer->commit($campaign, $importer->analyze($campaign, csvFile("EMAIL\na@x.example\nb@x.example\n")));
    $importer->commit($campaign, $importer->analyze($campaign, csvFile("EMAIL\nc@x.example\n")));

    expect($campaign->recipients()->orderBy('position')->pluck('position', 'email')->all())
        ->toBe(['a@x.example' => 1, 'b@x.example' => 2, 'c@x.example' => 3]);
});

it('accepte un autre nom de colonne pour les adresses', function () {
    $analysis = analyze("Courriel;Nom\na@x.example;Dupont\n", emailColumn: 'courriel');

    expect($analysis->importable)->toHaveCount(1)
        ->and($analysis->columns)->toBe(['Nom']);
});

it('échoue clairement quand la colonne des adresses est introuvable', function () {
    analyze("MAIL;NOM\na@x.example;Dupont\n");
})->throws(ImportException::class, 'Colonne « EMAIL » introuvable. Colonnes trouvées : MAIL, NOM.');

it('échoue sur un en-tête qui répète une colonne', function () {
    analyze("EMAIL;Nom;NOM\na@x.example;A;B\n");
})->throws(ImportException::class, 'apparaît deux fois');

it('échoue sur un fichier vide', function () {
    analyze('');
})->throws(ImportException::class, 'vide');

it('refuse l\'UTF-16 avec un message utile', function () {
    analyze("\xFF\xFE".mb_convert_encoding("EMAIL\na@x.example\n", 'UTF-16LE', 'UTF-8'));
})->throws(ImportException::class, 'UTF-16');

it('lit un fichier Windows-1252 et prévient', function () {
    $analysis = analyze(mb_convert_encoding("EMAIL;NOM\na@x.example;Élodie Garçon\n", 'Windows-1252', 'UTF-8'));

    expect($analysis->importable[0]['data']['NOM'])->toBe('Élodie Garçon')
        ->and($analysis->warnings[0])->toContain('Windows-1252');
});

it('ignore les colonnes de suivi à l\'import', function () {
    $analysis = analyze("EMAIL;NOM;STATUT;Date_Envoi;REPONSE;notes\na@x.example;Dupont;sent;2026-01-01;oui;rappeler\n");

    expect($analysis->columns)->toBe(['NOM'])
        ->and($analysis->importable[0]['data'])->toBe(['NOM' => 'Dupont'])
        ->and($analysis->warnings[0])->toContain('STATUT', 'Date_Envoi', 'REPONSE', 'notes');
});

it('rejette une ligne dont le nombre de colonnes est incorrect', function () {
    $analysis = analyze("EMAIL;NOM;VILLE\na@x.example;Dupont\nb@x.example;Martin;Lyon\nc@x.example;A;B;C\n");

    expect(emails($analysis->importable))->toBe(['b@x.example'])
        ->and(array_column($analysis->rejected, 'reason'))->toBe([
            'nombre de colonnes incorrect (2 au lieu de 3)',
            'nombre de colonnes incorrect (4 au lieu de 3)',
        ]);
});

it('ignore les lignes entièrement vides sans les compter', function () {
    $analysis = analyze("EMAIL;NOM\n\na@x.example;A\n;\n\nb@x.example;B\n");

    expect($analysis->totalRows)->toBe(2)
        ->and($analysis->rejected)->toBe([])
        ->and(array_column($analysis->importable, 'line'))->toBe([3, 6]);
});

it('n\'écrit rien en base tant qu\'on ne confirme pas', function () {
    $campaign = campaign();

    analyze("EMAIL\na@x.example\n", $campaign);

    expect(Recipient::count())->toBe(0)->and(SendLog::count())->toBe(0);
});

it('enregistre les lignes en attente, avec leurs variables et une trace dans le journal', function () {
    $campaign = campaign();
    $importer = app(CsvImporter::class);

    $importer->commit($campaign, $importer->analyze($campaign, csvFile("EMAIL;PRENOM;STRUCTURE\nMarie@Lumiere.example;Marie;\n")));

    $recipient = $campaign->recipients()->first();

    expect($recipient->email)->toBe('marie@lumiere.example')
        ->and($recipient->domain)->toBe('lumiere.example')
        ->and($recipient->status)->toBe(RecipientStatus::Pending)
        ->and($recipient->attempts)->toBe(0)
        ->and($recipient->data)->toBe(['PRENOM' => 'Marie', 'STRUCTURE' => ''])
        ->and($recipient->planned_at)->toBeNull()
        ->and(SendLog::where('event', 'import')->count())->toBe(1);
});

it('importe un gros fichier par paquets sans perdre de ligne', function () {
    $campaign = campaign();
    $csv = "EMAIL;NOM\n".implode('', array_map(fn ($i) => "p{$i}@x.example;Nom {$i}\n", range(1, 650)));

    $importer = app(CsvImporter::class);
    $imported = $importer->commit($campaign, $importer->analyze($campaign, csvFile($csv)));

    expect($imported)->toBe(650)
        ->and($campaign->recipients()->count())->toBe(650)
        ->and($campaign->recipients()->max('position'))->toBe(650);
});
