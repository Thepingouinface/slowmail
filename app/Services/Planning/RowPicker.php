<?php

namespace App\Services\Planning;

use App\Services\Import\SuppressionList;
use Carbon\CarbonImmutable;

/**
 * Choisit, dans l'ordre d'envoi, les lignes d'une journée : ni adresse supprimée, ni domaine
 * contacté trop récemment. Une ligne bloquée est sautée, pas supprimée : elle reste en attente.
 * Partagé par le plan réel et par la projection, pour qu'ils décident pareil.
 */
final class RowPicker
{
    /**
     * @param  list<array{id: int, position: int, email: string, domain: string}>  $candidates  triés par position
     * @return array{picked: list<array{id: int, position: int, email: string, domain: string}>, suppressed: list<array{id: int, position: int, email: string, domain: string}>}
     */
    public function pick(
        array $candidates,
        int $limit,
        DispatchHistory $history,
        CampaignSettings $settings,
        CarbonImmutable $localDay,
        ?SuppressionList $suppressions = null,
    ): array {
        $picked = [];
        $suppressed = [];
        $domainsToday = [];
        $today = DispatchHistory::dayNumber($localDay);

        foreach ($candidates as $candidate) {
            if (count($picked) >= $limit) {
                break;
            }

            if ($suppressions?->match($candidate['email']) !== null) {
                $suppressed[] = $candidate;

                continue;
            }

            if ($settings->domainCooldownDays > 0) {
                $last = $history->lastDayFor($candidate['domain']);

                if (($last !== null && $today - $last < $settings->domainCooldownDays) || isset($domainsToday[$candidate['domain']])) {
                    continue;
                }
            }

            $picked[] = $candidate;
            $domainsToday[$candidate['domain']] = true;
        }

        return ['picked' => $picked, 'suppressed' => $suppressed];
    }
}
