<?php

use Illuminate\Support\Facades\Artisan;

/*
| La base de test est en mémoire : la commande lit un vrai fichier SQLite, qu'on fabrique ici, et on le désigne
| à la place de la connexion de test le temps de l'essai.
*/
function backupSourceDatabase(): string
{
    $dir = sys_get_temp_dir().'/slowmail-backup-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $source = $dir.'/database.sqlite';
    $pdo = new PDO('sqlite:'.$source);
    $pdo->exec('CREATE TABLE prospects (id INTEGER PRIMARY KEY, email TEXT)');
    $pdo->exec("INSERT INTO prospects (email) VALUES ('marie@exemple.example'), ('paul@exemple.example')");

    config(['database.connections.sqlite.database' => $source]);

    return $source;
}

function removeBackupDirectory(string $source): void
{
    $dir = dirname($source);

    foreach (array_merge(glob($dir.'/*') ?: [], glob($dir.'/backups/*') ?: []) as $file) {
        @unlink($file);
    }
    @rmdir($dir.'/backups');
    @rmdir($dir);
}

it('écrit une copie complète de la base, dans backups/ à côté d\'elle', function () {
    $source = backupSourceDatabase();

    expect(Artisan::call('slowmail:backup'))->toBe(0);

    $files = glob(dirname($source).'/backups/slowmail-*.sqlite');
    expect($files)->toHaveCount(1);
    expect(Artisan::output())->toContain('Sauvegarde écrite');

    $copy = new PDO('sqlite:'.$files[0]);
    expect($copy->query('SELECT email FROM prospects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['marie@exemple.example', 'paul@exemple.example']);
    expect(substr(sprintf('%o', fileperms($files[0])), -4))->toBe('0600');

    removeBackupDirectory($source);
});

it('écrit là où on le demande, et refuse d\'écraser un fichier existant', function () {
    $source = backupSourceDatabase();
    $target = dirname($source).'/ma-sauvegarde.sqlite';

    expect(Artisan::call('slowmail:backup', ['fichier' => $target]))->toBe(0);
    expect(file_exists($target))->toBeTrue();

    file_put_contents($target, 'important');

    expect(Artisan::call('slowmail:backup', ['fichier' => $target]))->toBe(1);
    expect(Artisan::output())->toContain('existe déjà');
    expect(file_get_contents($target))->toBe('important');

    removeBackupDirectory($source);
});

it('dit clairement quand la base n\'est pas un fichier', function () {
    config(['database.connections.sqlite.database' => ':memory:']);

    expect(Artisan::call('slowmail:backup'))->toBe(1);
    expect(Artisan::output())->toContain('introuvable');
});
