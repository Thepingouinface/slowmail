<?php

namespace App\Services\Sending;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Support\Utc;
use Carbon\CarbonInterface;

/**
 * Efface les rendus et les données du CSV des campagnes terminées depuis longtemps (confidentialité, §11).
 * Restent : l'adresse, le statut, les dates, les notes. De quoi savoir qui a déjà été contacté.
 */
final class Purger
{
    /**
     * @return array{campaigns: int, recipients: int}
     */
    public function purge(int $days, ?CarbonInterface $now = null): array
    {
        $limit = Utc::sql(($now ?? now())->copy()->subDays($days));
        $campaigns = 0;
        $recipients = 0;

        $finished = Campaign::query()
            ->where('status', CampaignStatus::Finished->value)
            ->where('finished_at', '<', $limit)
            ->get(['id']);

        foreach ($finished as $campaign) {
            $count = Recipient::query()
                ->where('campaign_id', $campaign->id)
                ->where(fn ($query) => $query->whereNotNull('data')->orWhereNotNull('rendered_subject')->orWhereNotNull('rendered_body'))
                ->update(['data' => null, 'rendered_subject' => null, 'rendered_body' => null]);

            if ($count > 0) {
                $campaigns++;
                $recipients += $count;
                Journal::record($campaign->id, 'purge', "Rendus et données effacés ({$count} ligne(s)).");
            }
        }

        return ['campaigns' => $campaigns, 'recipients' => $recipients];
    }
}
