<?php

namespace App\Services\Import;

final class ParsedCsv
{
    /**
     * @param  list<string>  $columns  colonnes qui deviennent des variables (hors e-mail et colonnes de suivi)
     * @param  list<array{line: int, email: string, data: array<string, string>, error: ?string}>  $rows
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $delimiter,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $warnings,
        public readonly ?string $priorityColumn,
    ) {}
}
