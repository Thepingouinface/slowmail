<?php

namespace App\Services\Sending;

use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Recipient;
use App\Models\Suppression;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * La liste de suppression : une adresse, ou un domaine entier. Vérifiée juste avant chaque envoi,
 * et appliquée tout de suite aux lignes qui attendent leur tour.
 */
final class Suppressions
{
    /** Pourquoi cette adresse ne doit pas recevoir de mail, ou null. Adresse exacte ou domaine entier. */
    public function matches(string $email): ?SuppressionReason
    {
        $email = mb_strtolower(trim($email));
        $domain = Str::after($email, '@');

        $found = Suppression::query()
            ->where(fn ($query) => $query
                ->where(fn ($exact) => $exact->where('type', SuppressionType::Email->value)->where('value', $email))
                ->orWhere(fn ($whole) => $whole->where('type', SuppressionType::Domain->value)->where('value', $domain)))
            ->first();

        return $found?->reason;
    }

    /**
     * Ajoute une adresse (`jean@exemple.fr`) ou un domaine entier (`@exemple.fr`) à la liste.
     * Sans effet si elle y est déjà. Les lignes en attente ou planifiées qui correspondent, dans
     * toutes les campagnes, sortent de l'envoi.
     *
     * @throws InvalidArgumentException si la valeur n'est ni une adresse valide ni un domaine
     */
    public function add(string $value, SuppressionReason $reason = SuppressionReason::Stop): SuppressionResult
    {
        [$type, $normalized] = $this->normalize($value);

        $suppression = Suppression::query()->firstOrCreate(
            ['type' => $type->value, 'value' => $normalized],
            ['reason' => $reason->value],
        );

        return new SuppressionResult($suppression, $suppression->wasRecentlyCreated, $this->apply($type, $normalized, $suppression->reason));
    }

    /** @return array{0: SuppressionType, 1: string} */
    private function normalize(string $value): array
    {
        $value = mb_strtolower(trim($value));

        if (str_starts_with($value, '@')) {
            $domain = substr($value, 1);

            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1) {
                throw new InvalidArgumentException("« {$value} » n'est pas un nom de domaine valide.");
            }

            return [SuppressionType::Domain, $domain];
        }

        if (preg_match('/[^\x20-\x7E]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("« {$value} » n'est ni une adresse valide ni un domaine (écrivez @domaine.fr pour un domaine entier).");
        }

        return [SuppressionType::Email, $value];
    }

    private function apply(SuppressionType $type, string $value, SuppressionReason $reason): int
    {
        $rows = Recipient::query()
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Planned->value])
            ->where($type === SuppressionType::Domain ? 'domain' : 'email', $value)
            ->get(['id', 'campaign_id']);

        $affected = 0;

        foreach ($rows as $row) {
            $changed = Recipient::query()
                ->whereKey($row->id)
                ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Planned->value])
                ->update(['status' => RecipientStatus::Suppressed->value, 'planned_at' => null]);

            if ($changed > 0) {
                $affected++;
                Journal::record($row->campaign_id, 'suppressed', "Ajouté à la liste de suppression ({$reason->label()}) : retiré de l'envoi.", $row->id);
            }
        }

        return $affected;
    }
}
