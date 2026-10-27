<?php

namespace App\Console\Commands;

use App\Services\Sending\DatabaseBackup;
use Illuminate\Console\Command;
use RuntimeException;

class BackupCommand extends Command
{
    protected $signature = 'slowmail:backup {fichier? : Où écrire la sauvegarde (défaut : dossier backups/ à côté de la base)}';

    protected $description = 'Écrit une copie cohérente de la base SQLite, sans arrêter SlowMail';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $path = $backup->write($this->argument('fichier') ?: null);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Sauvegarde écrite : %s (%s).', $path, $this->readableSize((int) filesize($path))));

        return self::SUCCESS;
    }

    private function readableSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', ' ').' Mo'
            : number_format(max($bytes, 1) / 1024, 0, ',', ' ').' Ko';
    }
}
