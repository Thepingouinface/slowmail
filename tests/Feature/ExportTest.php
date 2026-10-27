<?php

use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Export\CsvExporter;
use App\Services\Import\CsvImporter;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

/** Exporte une campagne dans une chaîne. */
function exportOf(Campaign $campaign): string
{
    $stream = fopen('php://temp', 'r+');
    app(CsvExporter::class)->write($stream, $campaign);
    rewind($stream);

    return stream_get_contents($stream);
}

function exportCampaign(): Campaign
{
    $campaign = campaign('Agences Lyon');
    $campaign->update(['columns' => ['PRENOM', 'STRUCTURE', 'PRIORITE']]);

    return $campaign;
}

it('écrit les colonnes d\'origine puis STATUT, DATE_ENVOI, REPONSE et NOTES, lues dans la base', function () {
    $campaign = exportCampaign();
    $sent = recipient($campaign, 'marie@agence.example', RecipientStatus::Sent, ['PRENOM' => 'Marie', 'STRUCTURE' => 'Agence Lumière', 'PRIORITE' => '2']);
    $sent->forceFill(['sent_at' => paris('2026-10-20 11:30:00')->utc(), 'reply_status' => ReplyStatus::Replied, 'notes' => "Rappeler jeudi;\navec le devis"])->save();
    recipient($campaign, 'paul@studio.example', RecipientStatus::Pending, ['PRENOM' => 'Paul', 'STRUCTURE' => '', 'PRIORITE' => '']);

    $rows = parseCsv(exportOf($campaign));

    expect($rows[0])->toBe(['EMAIL', 'PRENOM', 'STRUCTURE', 'PRIORITE', 'STATUT', 'DATE_ENVOI', 'REPONSE', 'NOTES'])
        ->and($rows[1])->toBe(['marie@agence.example', 'Marie', 'Agence Lumière', '2', 'Envoyé', '20/10/2026 11:30', 'Répondu', "Rappeler jeudi;\navec le devis"])
        ->and($rows[2])->toBe(['paul@studio.example', 'Paul', '', '', 'En attente', '', '', ''])
        ->and($rows)->toHaveCount(3);
});

it('exporte dans l\'ordre d\'envoi', function () {
    $campaign = exportCampaign();
    $late = recipient($campaign, 'second@x.example');
    $early = recipient($campaign, 'premier@x.example');
    $early->forceFill(['position' => 1])->save();
    $late->forceFill(['position' => 2])->save();

    $rows = parseCsv(exportOf($campaign));

    expect($rows[1][0])->toBe('premier@x.example')->and($rows[2][0])->toBe('second@x.example');
});

it('écrit un BOM UTF-8 et sépare par des points-virgules', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example', data: ['PRENOM' => 'Élodie, "Lou"', 'STRUCTURE' => 'S', 'PRIORITE' => '1']);

    $csv = exportOf($campaign);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and(explode("\n", $csv)[0])->toContain('EMAIL;PRENOM;STRUCTURE')
        // Guillemets doublés, comme à l'import (RFC 4180).
        ->and($csv)->toContain('"Élodie, ""Lou"""');
});

it('relit à l\'import ce qu\'il a écrit à l\'export', function () {
    $campaign = exportCampaign();
    $data = ['PRENOM' => "Zoé; l'\"Ancienne\"", 'STRUCTURE' => 'Atelier', 'PRIORITE' => '3'];
    recipient($campaign, 'zoe@x.example', RecipientStatus::Pending, $data)->forceFill(['notes' => 'Une note', 'reply_status' => ReplyStatus::Replied])->save();

    $analysis = app(CsvImporter::class)->analyze(campaign('Autre'), csvFile(exportOf($campaign)));

    expect($analysis->importable)->toHaveCount(1)
        ->and($analysis->importable[0]['email'])->toBe('zoe@x.example')
        ->and($analysis->importable[0]['data'])->toBe($data)
        // Les colonnes de suivi sont réservées à l'export : ignorées à l'import.
        ->and($analysis->columns)->toBe(['PRENOM', 'STRUCTURE', 'PRIORITE']);
});

it('neutralise les cellules qui pourraient être lues comme des formules', function () {
    $campaign = exportCampaign();
    $row = recipient($campaign, 'piege@x.example', RecipientStatus::Sent, [
        'PRENOM' => '=HYPERLINK("http://mal.example","clic")',
        'STRUCTURE' => '+33 6 12 34 56 78',
        'PRIORITE' => '-5',
    ]);
    $row->forceFill(['notes' => '@SUM(A1:A9)', 'reply_status' => ReplyStatus::Meeting])->save();
    recipient($campaign, '-debut@x.example', data: ['PRENOM' => "\t=cmd", 'STRUCTURE' => "\r=cmd", 'PRIORITE' => '']);

    $rows = parseCsv(exportOf($campaign));

    expect($rows[1][1])->toBe('\'=HYPERLINK("http://mal.example","clic")')
        ->and($rows[1][2])->toBe("'+33 6 12 34 56 78")
        ->and($rows[1][3])->toBe("'-5")
        ->and($rows[1][7])->toBe("'@SUM(A1:A9)")
        ->and($rows[2][0])->toBe("'-debut@x.example")
        ->and($rows[2][1])->toBe("'\t=cmd")
        ->and($rows[2][2])->toBe("'\r=cmd");
});

it('protège aussi les noms de colonnes venus du fichier d\'origine', function () {
    $campaign = campaign();
    $campaign->update(['columns' => ['=COLONNE', 'NOM']]);
    recipient($campaign, 'a@x.example', data: ['=COLONNE' => 'v', 'NOM' => 'n']);

    expect(parseCsv(exportOf($campaign))[0][1])->toBe("'=COLONNE");
});

it('ne neutralise pas ce qui ne commence pas par un caractère de formule', function () {
    expect(CsvExporter::protect('Bonjour = ça va'))->toBe('Bonjour = ça va')
        ->and(CsvExporter::protect('jean@x.example'))->toBe('jean@x.example')
        ->and(CsvExporter::protect('3 - 2'))->toBe('3 - 2')
        ->and(CsvExporter::protect(''))->toBe('')
        ->and(CsvExporter::protect('=1+1'))->toBe("'=1+1")
        ->and(CsvExporter::protect('+1'))->toBe("'+1")
        ->and(CsvExporter::protect('-1'))->toBe("'-1")
        ->and(CsvExporter::protect('@a'))->toBe("'@a");
});

it('met un statut français lisible, et la date d\'envoi seulement pour un envoi réel', function () {
    $campaign = exportCampaign();
    $at = paris('2026-10-20 11:30:00')->utc();

    foreach ([
        'sent@x.example' => [RecipientStatus::Sent, 'Envoyé', '20/10/2026 11:30'],
        'dry@x.example' => [RecipientStatus::DryRun, 'Simulé', ''],
        'unc@x.example' => [RecipientStatus::Uncertain, 'À vérifier', ''],
        'bounce@x.example' => [RecipientStatus::Bounced, 'Rebond', ''],
        'fail@x.example' => [RecipientStatus::Failed, 'Échec', ''],
        'skip@x.example' => [RecipientStatus::Skipped, 'Ignoré', ''],
        'sup@x.example' => [RecipientStatus::Suppressed, 'Supprimé', ''],
    ] as $email => [$status, $label, $date]) {
        recipient($campaign, $email, $status)->forceFill(['sent_at' => $at, 'sending_started_at' => $at])->save();
    }

    $byEmail = collect(parseCsv(exportOf($campaign)))->skip(1)->keyBy(0);

    foreach ([
        'sent@x.example' => ['Envoyé', '20/10/2026 11:30'],
        'dry@x.example' => ['Simulé', ''],
        'unc@x.example' => ['À vérifier', ''],
        'bounce@x.example' => ['Rebond', ''],
        'fail@x.example' => ['Échec', ''],
        'skip@x.example' => ['Ignoré', ''],
        'sup@x.example' => ['Supprimé', ''],
    ] as $email => [$label, $date]) {
        expect($byEmail[$email][4])->toBe($label)->and($byEmail[$email][5])->toBe($date);
    }
});

it('met la date d\'envoi à l\'heure de la campagne', function () {
    $campaign = exportCampaign();
    $campaign->update(['settings' => ['timezone' => 'America/New_York']]);
    recipient($campaign, 'a@x.example', RecipientStatus::Sent)->forceFill(['sent_at' => paris('2026-10-20 11:30:00')->utc()])->save();

    expect(parseCsv(exportOf($campaign))[1][5])->toBe('20/10/2026 05:30');
});

it('n\'exporte jamais le rendu d\'un mail', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example', RecipientStatus::Sent)->forceFill([
        'rendered_subject' => 'OBJET-RENDU-SECRET',
        'rendered_body' => 'CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE',
        'message_id' => 'id-secret@exemple.test',
        'last_error' => 'ERREUR-INTERNE',
    ])->save();

    $csv = exportOf($campaign);

    expect($csv)->not->toContain('OBJET-RENDU-SECRET')->not->toContain('CORPS-SECRET')->not->toContain('id-secret@')->not->toContain('ERREUR-INTERNE');
});

it('exporte une campagne purgée : l\'adresse, le statut et les notes restent', function () {
    $campaign = exportCampaign();
    $row = recipient($campaign, 'purge@x.example', RecipientStatus::Sent, ['PRENOM' => 'Marie']);
    $row->forceFill(['notes' => 'À rappeler', 'sent_at' => now()])->save();
    $row->update(['data' => null]);

    $rows = parseCsv(exportOf($campaign));

    expect($rows[1])->toBe(['purge@x.example', '', '', '', 'Envoyé', '21/10/2026 10:00', '', 'À rappeler']);
});

it('n\'écrase pas une colonne du fichier d\'origine nommée comme la colonne des adresses', function () {
    $campaign = campaign();
    $campaign->update(['columns' => ['EMAIL', 'NOM']]);
    recipient($campaign, 'a@x.example', data: ['EMAIL' => 'contact@autre.example', 'NOM' => 'N']);

    $rows = parseCsv(exportOf($campaign));

    expect($rows[0])->toBe(['ADRESSE', 'EMAIL', 'NOM', 'STATUT', 'DATE_ENVOI', 'REPONSE', 'NOTES'])
        ->and($rows[1][0])->toBe('a@x.example')
        ->and($rows[1][1])->toBe('contact@autre.example');
});

it('exporte une campagne vide : l\'en-tête seul', function () {
    $campaign = exportCampaign();

    expect(parseCsv(exportOf($campaign)))->toHaveCount(1);
});

it('ne mélange pas les campagnes', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example');
    recipient(campaign('Autre'), 'etranger@x.example');

    expect(exportOf($campaign))->toContain('a@x.example')->not->toContain('etranger@x.example');
});

it('exporte plusieurs paquets de lignes sans en perdre', function () {
    $campaign = exportCampaign();

    foreach (range(1, 1203) as $i) {
        recipient($campaign, "p{$i}@x.example");
    }

    expect(parseCsv(exportOf($campaign)))->toHaveCount(1204);
});

// --- Téléchargement ---------------------------------------------------------------------------

it('propose le CSV au téléchargement depuis l\'interface', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example', RecipientStatus::Sent)->forceFill(['sent_at' => now()])->save();

    $response = $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/export");

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('agences-lyon-2026-10-21.csv');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $rows = parseCsv($response->streamedContent());
    expect($rows[0][0])->toBe('EMAIL')->and($rows[1][0])->toBe('a@x.example')->and($rows[1][4])->toBe('Envoyé');
});

it('donne un nom de fichier même quand le nom de la campagne n\'en fait pas un', function () {
    $campaign = campaign('???');

    expect(app(CsvExporter::class)->filename($campaign))->toBe('campagne-2026-10-21.csv');
});

it('affiche le bouton d\'export sur chaque onglet de la campagne', function () {
    $campaign = exportCampaign();

    foreach (['', '/template', '/settings', '/journal'] as $page) {
        $this->withSession(authenticated())->get("/campaigns/{$campaign->id}{$page}")
            ->assertSee('Exporter en CSV')
            ->assertSeeHtml('href="'.route('campaigns.export', $campaign).'"');
    }
});

// --- Commande ---------------------------------------------------------------------------------

function exportPath(): string
{
    $path = sys_get_temp_dir().'/slowmail-export-'.bin2hex(random_bytes(6)).'.csv';
    $GLOBALS['slowmail_test_files'][] = $path;

    return $path;
}

it('exporte avec slowmail:export {campagne} {fichier.csv}', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example', RecipientStatus::Sent, ['PRENOM' => 'A', 'STRUCTURE' => 'S', 'PRIORITE' => '1'])->forceFill(['sent_at' => now()])->save();
    recipient($campaign, 'b@x.example');
    $path = exportPath();

    $this->artisan('slowmail:export', ['campagne' => 'Agences Lyon', 'fichier' => $path])
        ->expectsOutputToContain('2 ligne(s)')
        ->assertSuccessful();

    $rows = parseCsv(file_get_contents($path));
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['EMAIL', 'PRENOM', 'STRUCTURE', 'PRIORITE', 'STATUT', 'DATE_ENVOI', 'REPONSE', 'NOTES'])
        ->and($rows[1][4])->toBe('Envoyé')
        ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600');
});

it('désigne la campagne par son identifiant', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example');
    $path = exportPath();

    $this->artisan('slowmail:export', ['campagne' => (string) $campaign->id, 'fichier' => $path])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();
});

it('refuse d\'écraser un fichier existant sans --force', function () {
    $campaign = exportCampaign();
    recipient($campaign, 'a@x.example');
    $path = exportPath();
    file_put_contents($path, 'à ne pas perdre');

    $this->artisan('slowmail:export', ['campagne' => 'Agences Lyon', 'fichier' => $path])
        ->expectsOutputToContain('existe déjà')
        ->assertFailed();

    expect(file_get_contents($path))->toBe('à ne pas perdre');

    $this->artisan('slowmail:export', ['campagne' => 'Agences Lyon', 'fichier' => $path, '--force' => true])->assertSuccessful();

    expect(file_get_contents($path))->toContain('a@x.example');
});

it('échoue proprement pour une campagne inconnue ou un dossier absent', function () {
    $campaign = exportCampaign();

    $this->artisan('slowmail:export', ['campagne' => 'Inconnue', 'fichier' => exportPath()])->expectsOutputToContain('Campagne introuvable')->assertFailed();

    $this->artisan('slowmail:export', ['campagne' => 'Agences Lyon', 'fichier' => '/dossier/qui/n/existe/pas.csv'])
        ->expectsOutputToContain('Impossible d\'écrire')
        ->assertFailed();

    expect(Campaign::count())->toBe(1)->and(Recipient::count())->toBe(0);
});

it('ne modifie rien dans la base en exportant', function () {
    $campaign = exportCampaign();
    $row = recipient($campaign, 'a@x.example', RecipientStatus::Uncertain);
    $before = Recipient::query()->get()->toArray();

    Artisan::call('slowmail:export', ['campagne' => 'Agences Lyon', 'fichier' => exportPath()]);

    expect(Recipient::query()->get()->toArray())->toBe($before)->and($row->fresh()->status)->toBe(RecipientStatus::Uncertain);
});
