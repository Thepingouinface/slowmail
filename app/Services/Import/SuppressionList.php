<?php

namespace App\Services\Import;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Suppression;
use Illuminate\Support\Str;

/** Instantané de la liste de suppression (adresses et domaines entiers). */
final class SuppressionList
{
    /**
     * @param  array<string, SuppressionReason>  $emails
     * @param  array<string, SuppressionReason>  $domains
     */
    private function __construct(
        private readonly array $emails,
        private readonly array $domains,
    ) {}

    public static function load(): self
    {
        $emails = [];
        $domains = [];

        foreach (Suppression::query()->get(['value', 'type', 'reason']) as $suppression) {
            $value = mb_strtolower($suppression->value);

            if ($suppression->type === SuppressionType::Domain) {
                $domains[$value] = $suppression->reason;
            } else {
                $emails[$value] = $suppression->reason;
            }
        }

        return new self($emails, $domains);
    }

    public function match(string $email): ?SuppressionReason
    {
        $email = mb_strtolower(trim($email));

        return $this->emails[$email] ?? $this->domains[Str::after($email, '@')] ?? null;
    }
}
