<?php

namespace App\Services\Sending;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Copie à chaud de la base SQLite (VACUUM INTO) : un instantané cohérent, même pendant un envoi.
 * On ne copie pas le fichier à la main : avec le journal WAL, il serait incomplet.
 */
class DatabaseBackup
{
    /**
     * @return string le chemin du fichier écrit
     *
     * @throws RuntimeException
     */
    public function write(?string $target = null): string
    {
        $source = $this->sourcePath();
        $target ??= dirname($source).'/backups/slowmail-'.now(config('slowmail.timezone'))->format('Ymd-His').'.sqlite';

        if (file_exists($target)) {
            throw new RuntimeException("Le fichier {$target} existe déjà : rien n'a été écrasé.");
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier {$directory}.");
        }

        try {
            // Une connexion à part : VACUUM refuse de tourner dans une transaction, et Laravel peut en avoir une ouverte.
            $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('VACUUM INTO '.$pdo->quote($target));
        } catch (PDOException $e) {
            @unlink($target);

            throw new RuntimeException('La sauvegarde a échoué : '.$e->getMessage());
        }

        @chmod($target, 0600);

        return $target;
    }

    private function sourcePath(): string
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            throw new RuntimeException('La sauvegarde ne gère que SQLite.');
        }

        $path = (string) config("database.connections.{$connection}.database");

        if ($path === '' || $path === ':memory:' || ! is_file($path)) {
            throw new RuntimeException('La base SQLite est introuvable : '.($path ?: '(chemin vide)').'.');
        }

        return $path;
    }
}
