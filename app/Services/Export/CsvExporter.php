<?php

namespace App\Services\Export;

use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Import\CsvParser;
use App\Support\LocalTime;
use Illuminate\Support\Str;

/**
 * Réécrit une campagne en CSV : les colonnes d'origine des fichiers importés (dans l'ordre de
 * `campaigns.columns`), puis `STATUT`, `DATE_ENVOI`, `REPONSE` et `NOTES`, lus dans la base.
 *
 * Séparateur `;` et BOM UTF-8, pour qu'un tableur français l'ouvre sans assistant. Aucun rendu de mail
 * n'est exporté. Toute cellule qui pourrait être lue comme une formule est neutralisée (voir protect()).
 */
final class CsvExporter
{
    public const DELIMITER = ';';

    /** Noms essayés, dans l'ordre, pour la colonne des adresses : le premier qui n'est pas déjà une colonne du CSV. */
    private const EMAIL_COLUMN_NAMES = ['EMAIL', 'ADRESSE', 'ADRESSE_EMAIL'];

    /** Début de cellule qu'un tableur interpréterait comme une formule (OWASP : = + - @, tabulation, retour chariot). */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Écrit le CSV dans un flux ouvert en écriture.
     *
     * @param  resource  $stream
     * @return int nombre de lignes de données écrites
     */
    public function write($stream, Campaign $campaign): int
    {
        $columns = array_values($campaign->columns ?? []);
        $emailColumn = $this->emailColumn($columns);
        $timezone = $campaign->timezone();

        fwrite($stream, "\xEF\xBB\xBF");
        $this->put($stream, [$emailColumn, ...$columns, ...CsvParser::TRACKING_COLUMNS]);

        $count = 0;

        // Par paquets, dans l'ordre d'envoi : une grosse campagne ne tient pas en mémoire d'un coup.
        $campaign->recipients()->orderBy('position')->chunkById(500, function ($rows) use ($stream, $columns, $timezone, &$count) {
            foreach ($rows as $recipient) {
                $this->put($stream, $this->row($recipient, $columns, $timezone));
                $count++;
            }
        });

        return $count;
    }

    /** Nom de fichier proposé au téléchargement : « agences-lyon-2026-10-21.csv ». */
    public function filename(Campaign $campaign): string
    {
        $slug = Str::slug($campaign->name);

        return ($slug === '' ? 'campagne' : $slug).'-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * Neutralise une cellule qui commence par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot :
     * une apostrophe devant, et le tableur la lit comme du texte. Appliqué à toute cellule, en-têtes compris.
     */
    public static function protect(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'".$cell : $cell;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function row(Recipient $recipient, array $columns, string $timezone): array
    {
        $data = $recipient->data ?? [];

        return [
            $recipient->email,
            ...array_map(fn (string $column) => (string) ($data[$column] ?? ''), $columns),
            $recipient->status->label(),
            // Seul un envoi réel a une date d'envoi : une simulation n'a rien envoyé, un envoi incertain n'est pas confirmé.
            $recipient->status === RecipientStatus::Sent ? LocalTime::compact($recipient->sent_at, $timezone, '') : '',
            $recipient->reply_status instanceof ReplyStatus ? $recipient->reply_status->label() : '',
            (string) $recipient->notes,
        ];
    }

    /** @param  list<string>  $columns */
    private function emailColumn(array $columns): string
    {
        $taken = array_map(fn (string $column) => mb_strtoupper($column), $columns);

        foreach (self::EMAIL_COLUMN_NAMES as $name) {
            if (! in_array($name, $taken, true)) {
                return $name;
            }
        }

        return 'ADRESSE_EMAIL_CAMPAGNE';
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $cells
     */
    private function put($stream, array $cells): void
    {
        // Échappement désactivé : le format est celui de la RFC 4180 (guillemets doublés), comme à l'import.
        fputcsv($stream, array_map(self::protect(...), $cells), self::DELIMITER, '"', '');
    }
}
