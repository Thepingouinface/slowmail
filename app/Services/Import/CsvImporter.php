<?php

namespace App\Services\Import;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\SendLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import en deux temps : analyze() valide sans rien écrire, commit() insère.
 * Le fichier n'est jamais conservé ici : à l'appelant de décider de sa durée de vie.
 */
class CsvImporter
{
    public function __construct(private readonly CsvParser $parser) {}

    public function analyze(Campaign $campaign, string $path, string $emailColumn = 'EMAIL'): ImportAnalysis
    {
        $csv = $this->parser->parse($path, $emailColumn);
        $warnings = $csv->warnings;

        $suppressions = SuppressionList::load();
        $inCampaign = array_flip($campaign->recipients()->pluck('email')->all());

        $seen = [];
        $accepted = [];
        $rejected = [];

        foreach ($csv->rows as $row) {
            $line = $row['line'];
            $email = mb_strtolower($row['email']);

            $reject = function (string $reason) use (&$rejected, $line, $email) {
                $rejected[] = ['line' => $line, 'email' => $email, 'reason' => $reason];
            };

            if ($row['error'] !== null) {
                $reject($row['error']);
            } elseif ($email === '') {
                $reject('adresse vide');
            } elseif (preg_match('/[^\x20-\x7E]/', $email) === 1) {
                $reject('caractères accentués ou non ASCII dans l\'adresse (non pris en charge)');
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reject('adresse invalide');
            } elseif (isset($seen[$email])) {
                $reject("doublon dans le fichier (déjà vue ligne {$seen[$email]})");
            } else {
                $seen[$email] = $line;

                if (isset($inCampaign[$email])) {
                    $reject('déjà présente dans cette campagne');
                } elseif (($why = $suppressions->match($email)) !== null) {
                    $reject("dans la liste de suppression ({$why->label()})");
                } else {
                    $accepted[] = [
                        'line' => $line,
                        'email' => $email,
                        'domain' => Str::after($email, '@'),
                        'data' => $row['data'],
                        'priority' => $this->priority($csv->priorityColumn, $row['data'], $line, $warnings),
                    ];
                }
            }
        }

        $elsewhere = $this->contactedElsewhere($campaign, array_column($accepted, 'email'));
        $importable = [];
        $contacted = [];

        foreach ($accepted as $row) {
            if (isset($elsewhere[$row['email']])) {
                $contacted[] = $row + ['campaign' => $elsewhere[$row['email']]];
            } else {
                $importable[] = $row;
            }
        }

        return new ImportAnalysis(
            delimiter: $csv->delimiter,
            columns: $csv->columns,
            totalRows: count($csv->rows),
            importable: $importable,
            contacted: $contacted,
            rejected: $rejected,
            warnings: $warnings,
        );
    }

    /**
     * Insère les lignes importables, et les « déjà contactées » si demandé.
     * Elles passent après les lignes déjà présentes, triées par PRIORITE puis par ordre du fichier.
     *
     * @return int nombre de lignes insérées
     */
    public function commit(Campaign $campaign, ImportAnalysis $analysis, bool $includeContacted = false): int
    {
        $rows = $analysis->importable;

        if ($includeContacted) {
            $rows = array_merge($rows, $analysis->contacted);
        }

        usort($rows, fn (array $a, array $b) => [$a['priority'] ?? INF, $a['line']] <=> [$b['priority'] ?? INF, $b['line']]);

        DB::transaction(function () use ($campaign, $rows, $analysis, $includeContacted) {
            $position = (int) $campaign->recipients()->max('position');
            $now = now();

            foreach (array_chunk($rows, 200) as $chunk) {
                $campaign->recipients()->insert(array_map(function (array $row) use ($campaign, &$position, $now) {
                    return [
                        'campaign_id' => $campaign->id,
                        'position' => ++$position,
                        'email' => $row['email'],
                        'domain' => $row['domain'],
                        'data' => json_encode($row['data'], JSON_UNESCAPED_UNICODE),
                        'status' => RecipientStatus::Pending->value,
                        'attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $chunk));
            }

            if ($rows !== []) {
                $this->rememberColumns($campaign, $analysis->columns);
            }

            SendLog::create([
                'campaign_id' => $campaign->id,
                'event' => 'import',
                'detail' => sprintf(
                    '%d importées, %d rejetées, %d déjà contactées ailleurs (%s).',
                    count($rows),
                    count($analysis->rejected),
                    count($analysis->contacted),
                    $includeContacted ? 'importées' : 'ignorées',
                ),
            ]);
        });

        return count($rows);
    }

    /**
     * Les colonnes du fichier deviennent les variables de la campagne (sans doublon de casse).
     *
     * @param  list<string>  $columns
     */
    private function rememberColumns(Campaign $campaign, array $columns): void
    {
        $known = [];

        foreach (array_merge($campaign->columns ?? [], $columns) as $column) {
            $known[mb_strtolower($column)] ??= $column;
        }

        $campaign->update(['columns' => array_values($known)]);
    }

    /**
     * @param  array<string, string>  $data
     * @param  list<string>  $warnings
     */
    private function priority(?string $column, array $data, int $line, array &$warnings): ?float
    {
        $raw = $column === null ? '' : ($data[$column] ?? '');

        if ($raw === '') {
            return null;
        }

        $number = str_replace(',', '.', $raw);

        if (! is_numeric($number)) {
            $warnings[] = "Ligne {$line} : {$column} « {$raw} » n'est pas un nombre, la ligne passe en fin de liste.";

            return null;
        }

        return (float) $number;
    }

    /**
     * Adresses déjà contactées (envoyées, ou envoi incertain) par une autre campagne.
     *
     * @param  list<string>  $emails
     * @return array<string, string> adresse => nom de la campagne
     */
    private function contactedElsewhere(Campaign $campaign, array $emails): array
    {
        $statuses = array_map(fn (RecipientStatus $status) => $status->value, RecipientStatus::contacted());
        $found = [];

        foreach (array_chunk($emails, 500) as $chunk) {
            $matches = DB::table('recipients')
                ->join('campaigns', 'campaigns.id', '=', 'recipients.campaign_id')
                ->where('recipients.campaign_id', '!=', $campaign->id)
                ->whereIn('recipients.status', $statuses)
                ->whereIn('recipients.email', $chunk)
                ->orderBy('recipients.id')
                ->get(['recipients.email', 'campaigns.name as campaign_name']);

            foreach ($matches as $match) {
                $found[$match->email] ??= $match->campaign_name;
            }
        }

        return $found;
    }
}
