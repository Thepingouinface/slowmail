<?php

namespace App\Services\Import;

/**
 * Résultat de la validation d'un CSV, avant toute écriture en base.
 *
 * Une ligne importable : {line, email, domain, data, priority}.
 * Une ligne « déjà contactée » : idem + {campaign} (la campagne qui l'a déjà contactée).
 * Une ligne rejetée : {line, email, reason}.
 */
final class ImportAnalysis
{
    /**
     * @param  list<string>  $columns
     * @param  list<array{line: int, email: string, domain: string, data: array<string, string>, priority: ?float}>  $importable
     * @param  list<array{line: int, email: string, domain: string, data: array<string, string>, priority: ?float, campaign: string}>  $contacted
     * @param  list<array{line: int, email: string, reason: string}>  $rejected
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $delimiter,
        public readonly array $columns,
        public readonly int $totalRows,
        public readonly array $importable,
        public readonly array $contacted,
        public readonly array $rejected,
        public readonly array $warnings,
    ) {}
}
