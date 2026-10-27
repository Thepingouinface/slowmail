<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/**
 * Lit un CSV : séparateur `;` ou `,` détecté, UTF-8 avec ou sans BOM.
 * Les numéros de ligne sont ceux d'un tableur (l'en-tête est la ligne 1).
 */
final class CsvParser
{
    /** Réécrites à l'export, donc ignorées à l'import. */
    public const TRACKING_COLUMNS = ['STATUT', 'DATE_ENVOI', 'REPONSE', 'NOTES'];

    public const PRIORITY_COLUMN = 'PRIORITE';

    public function parse(string $path, string $emailColumn = 'EMAIL'): ParsedCsv
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new ImportException('Le fichier est illisible.');
        }

        $warnings = [];
        $raw = $this->toUtf8($raw, $warnings);
        $delimiter = $this->detectDelimiter($raw);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $header = null;
        $emailIndex = null;
        $variables = [];
        $priorityColumn = null;
        $rows = [];
        $line = 0;

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($record === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $record;
                [$emailIndex, $variables, $priorityColumn] = $this->readHeader($record, $emailColumn, $warnings);

                continue;
            }

            if (count($record) !== count($header)) {
                $rows[] = [
                    'line' => $line,
                    'email' => $this->clean($record[$emailIndex] ?? ''),
                    'data' => [],
                    'error' => 'nombre de colonnes incorrect ('.count($record).' au lieu de '.count($header).')',
                ];

                continue;
            }

            $data = [];
            foreach ($variables as $index => $name) {
                $data[$name] = $this->clean($record[$index]);
            }

            $email = $this->clean($record[$emailIndex]);

            if ($email === '' && implode('', $data) === '') {
                continue;
            }

            $rows[] = ['line' => $line, 'email' => $email, 'data' => $data, 'error' => null];
        }

        fclose($stream);

        if ($header === null) {
            throw new ImportException('Le fichier est vide.');
        }

        return new ParsedCsv($delimiter, array_values($variables), $rows, $warnings, $priorityColumn);
    }

    /**
     * @param  list<string|null>  $record
     * @param  list<string>  $warnings
     * @return array{0: int, 1: array<int, string>, 2: ?string}
     */
    private function readHeader(array $record, string $emailColumn, array &$warnings): array
    {
        $names = [];
        $seen = [];

        foreach ($record as $index => $cell) {
            $name = $this->clean($cell);

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                throw new ImportException("La colonne « {$name} » apparaît deux fois dans l'en-tête.");
            }

            $seen[$key] = $index;
            $names[$index] = $name;
        }

        $emailIndex = $seen[mb_strtolower($this->clean($emailColumn))] ?? null;

        if ($emailIndex === null) {
            throw new ImportException(sprintf(
                'Colonne « %s » introuvable. Colonnes trouvées : %s. Indiquez le nom de la colonne e-mail à l\'import.',
                $emailColumn,
                $names === [] ? 'aucune' : implode(', ', $names),
            ));
        }

        $variables = [];
        $ignored = [];
        $priorityColumn = null;

        foreach ($names as $index => $name) {
            if ($index === $emailIndex) {
                continue;
            }

            $key = strtoupper(Str::ascii($name));

            if (in_array($key, self::TRACKING_COLUMNS, true)) {
                $ignored[] = $name;

                continue;
            }

            if ($key === self::PRIORITY_COLUMN) {
                $priorityColumn = $name;
            }

            $variables[$index] = $name;
        }

        if ($ignored !== []) {
            $warnings[] = 'Colonnes de suivi ignorées à l\'import : '.implode(', ', $ignored).'.';
        }

        return [$emailIndex, $variables, $priorityColumn];
    }

    /** @param  list<string>  $warnings */
    private function toUtf8(string $raw, array &$warnings): string
    {
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            throw new ImportException('Le fichier est en UTF-16. Enregistrez-le en CSV UTF-8 puis réessayez.');
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $warnings[] = 'Le fichier n\'est pas en UTF-8 : il a été lu comme du Windows-1252. Vérifiez les accents.';
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    private function detectDelimiter(string $raw): string
    {
        $counts = [';' => 0, ',' => 0];
        $quoted = false;
        $sample = ltrim($raw, "\r\n");

        for ($i = 0, $length = strlen($sample); $i < $length; $i++) {
            $char = $sample[$i];

            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && ($char === "\n" || $char === "\r")) {
                break;
            } elseif (! $quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        return $counts[';'] > 0 && $counts[';'] >= $counts[','] ? ';' : ',';
    }

    /** Retire les espaces, y compris l'espace insécable que les tableurs glissent parfois. */
    private function clean(?string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $value) ?? '';
    }
}
