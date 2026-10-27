<?php

use App\Enums\RecipientStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function upload(string $content, string $name = 'prospects.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function storedImports(): array
{
    return Storage::disk('local')->files('imports');
}

function tokenOf(string $path): string
{
    return basename($path, '.csv');
}

it('exige le mot de passe pour importer', function () {
    $campaign = campaign();

    $this->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\na@x.example\n")])->assertRedirect('/login');

    expect(storedImports())->toBe([]);
});

it('analyse sans rien importer, puis importe à la confirmation et supprime le fichier', function () {
    $campaign = campaign();

    $response = $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL;NOM\na@x.example;A\nb@x.example;B\na@x.example;A\n")]);

    $response->assertOk()
        ->assertSee('Rapport d\'import', false)
        ->assertSee('doublon dans le fichier')
        ->assertSee('Lancer l\'import', false);

    expect($campaign->recipients()->count())->toBe(0)->and(storedImports())->toHaveCount(1);

    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL'])
        ->assertRedirect("/campaigns/{$campaign->id}")
        ->assertSessionHas('import_result', fn (array $result) => $result['imported'] === 2 && $result['rejected_total'] === 1);

    expect($campaign->recipients()->count())->toBe(2)->and(storedImports())->toBe([]);
});

it('affiche le résultat de l\'import sur la page de la campagne', function () {
    $campaign = campaign();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\na@x.example\nnope\n")]);
    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())
        ->followingRedirects()
        ->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL'])
        ->assertOk()
        ->assertSee('ligne(s) importée(s)')
        ->assertSee('1 ligne(s) rejetée(s)')
        ->assertSee('adresse invalide');
});

it('annule : le fichier est supprimé et rien n\'est importé', function () {
    $campaign = campaign();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\na@x.example\n")]);
    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())
        ->delete("/campaigns/{$campaign->id}/import/{$token}")
        ->assertRedirect("/campaigns/{$campaign->id}");

    expect(storedImports())->toBe([])->and($campaign->recipients()->count())->toBe(0);
});

it('ne peut pas confirmer deux fois le même fichier', function () {
    $campaign = campaign();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\na@x.example\n")]);
    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL']);
    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL'])->assertNotFound();

    expect($campaign->recipients()->count())->toBe(1);
});

it('rejette un jeton qui ne ressemble pas à un jeton', function () {
    $campaign = campaign();

    foreach (['..%2F..%2F.env', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'court', str_repeat('a', 27)] as $token) {
        $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}")->assertNotFound();
        $this->withSession(authenticated())->delete("/campaigns/{$campaign->id}/import/{$token}")->assertNotFound();
    }
});

it('supprime le fichier et explique quand la colonne des adresses manque', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->from("/campaigns/{$campaign->id}")
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload("MAIL;NOM\na@x.example;A\n")])
        ->assertRedirect("/campaigns/{$campaign->id}")
        ->assertSessionHasErrors('file');

    expect(storedImports())->toBe([]);
});

it('permet de choisir la colonne des adresses', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload("Courriel;Nom\na@x.example;A\n"), 'email_column' => 'Courriel'])
        ->assertOk()
        ->assertSee('Lancer l\'import', false);

    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'Courriel']);

    expect($campaign->recipients()->count())->toBe(1);
});

it('ne garde pas le fichier quand il n\'y a rien à importer', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\nnope\n")])
        ->assertOk()
        ->assertSee('Rien à importer')
        ->assertDontSee('Lancer l\'import', false);

    expect(storedImports())->toBe([]);
});

it('propose d\'importer les adresses déjà contactées et respecte le choix', function () {
    recipient(campaign('Ancienne'), 'vu@x.example', RecipientStatus::Sent);
    $campaign = campaign('Nouvelle');

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\nvu@x.example\nneuf@x.example\n")])
        ->assertOk()
        ->assertSee('Ancienne')
        ->assertSee('Importer aussi');

    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL']);
    expect($campaign->recipients()->pluck('email')->all())->toBe(['neuf@x.example']);
});

it('importe les adresses déjà contactées quand la case est cochée', function () {
    recipient(campaign('Ancienne'), 'vu@x.example', RecipientStatus::Sent);
    $campaign = campaign('Nouvelle');

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\nvu@x.example\n")]);
    $token = tokenOf(storedImports()[0]);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import/{$token}", ['email_column' => 'EMAIL', 'include_contacted' => '1']);

    expect($campaign->recipients()->pluck('email')->all())->toBe(['vu@x.example']);
});

it('supprime les fichiers d\'import périmés', function () {
    $campaign = campaign();
    $stale = 'imports/'.str_repeat('a', 26).'.csv';
    Storage::disk('local')->put($stale, "EMAIL\nvieux@x.example\n");
    touch(Storage::disk('local')->path($stale), now()->subHours(25)->getTimestamp());

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", ['file' => upload("EMAIL\na@x.example\n")]);

    expect(Storage::disk('local')->exists($stale))->toBeFalse()->and(storedImports())->toHaveCount(1);
});

it('refuse un fichier qui n\'est pas un CSV', function () {
    $campaign = campaign();

    $this->withSession(authenticated())
        ->post("/campaigns/{$campaign->id}/import", ['file' => upload('EMAIL', 'prospects.exe')])
        ->assertSessionHasErrors('file');

    expect(storedImports())->toBe([]);
});

it('exige un fichier', function () {
    $campaign = campaign();

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/import", [])->assertSessionHasErrors('file');
});
