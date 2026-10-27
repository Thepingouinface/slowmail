<?php

namespace App\Services\Planning;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Import\SuppressionList;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Prévision des envois, jour après jour, sans rien écrire en base : de quoi afficher le plan et estimer la fin.
 *
 * Elle suit les mêmes règles que le Planner (quota, montée progressive, jours et plage, cooldown de domaine,
 * maximum total, date de fin) et choisit les lignes avec le même RowPicker. Les heures des jours à venir ne
 * sont pas tirées : elles le seront le jour même. Seul le plan du jour, s'il est déjà tiré, a des heures exactes.
 */
final class PlanProjector
{
    private const LEAD_SECONDS = 60;

    public function __construct(
        private readonly SendCalendar $calendar,
        private readonly QuotaCalculator $quota,
        private readonly RowPicker $picker,
    ) {}

    public function project(Campaign $campaign, ?CarbonInterface $now = null, int $horizonDays = 400): Projection
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $settings = CampaignSettings::for($campaign);

        if (($problems = $settings->violations()) !== []) {
            return new Projection([], null, blocked: implode(' ', $problems));
        }

        $history = DispatchHistory::load($campaign, $settings->timezone);
        $suppressions = SuppressionList::load();
        $today = $now->setTimezone($settings->timezone)->startOfDay();
        $tomorrow = $today->addDay()->startOfDay();

        $rows = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Planned->value])
            ->orderBy('position')
            ->get(['id', 'position', 'email', 'domain', 'status', 'planned_at']);

        // Les créneaux d'aujourd'hui sont fixes ; ceux d'un autre jour seront remis en attente par le Planner.
        $fixed = $rows
            ->filter(fn (Recipient $row) => $row->status === RecipientStatus::Planned && $row->planned_at >= $today && $row->planned_at < $tomorrow)
            ->sortBy('planned_at');

        $fixedIds = $fixed->pluck('id')->all();

        $pool = $rows
            ->reject(fn (Recipient $row) => in_array($row->id, $fixedIds, true))
            ->map(fn (Recipient $row) => ['id' => $row->id, 'position' => $row->position, 'email' => $row->email, 'domain' => $row->domain])
            ->values()
            ->all();

        $drawnToday = $campaign->planned_for?->format('Y-m-d') === $today->format('Y-m-d') || $fixed->isNotEmpty();
        $remainingTotal = $settings->maxTotal === null ? PHP_INT_MAX : max(0, $settings->maxTotal - $history->total());

        $days = [];
        $lastDay = null;
        $stoppedBy = null;
        $cursor = $today;

        for ($i = 0; $i <= $horizonDays; $i++, $cursor = $cursor->addDay()->startOfDay()) {
            if ($settings->endAt !== null && $cursor >= $settings->endAt) {
                $stoppedBy = 'end_at';

                break;
            }

            if ($i === 0 && $drawnToday) {
                if ($fixed->isNotEmpty()) {
                    $entries = [];

                    foreach ($fixed as $row) {
                        $at = CarbonImmutable::instance($row->planned_at);
                        $entries[] = ['time' => $at, 'id' => $row->id, 'position' => $row->position, 'email' => $row->email];
                        $history->add($at, $row->domain);
                    }

                    $remainingTotal -= count($entries);
                    $days[] = new ProjectedDay($cursor, true, $entries);
                    $lastDay = $cursor;
                }

                continue;
            }

            if ($remainingTotal <= 0) {
                $stoppedBy = 'max_total';

                break;
            }

            if ($pool === []) {
                break;
            }

            $window = $this->calendar->window($settings, $cursor);

            if ($window === null) {
                continue;
            }

            $lead = $now->addSeconds(self::LEAD_SECONDS);
            $from = $i === 0 && $lead > $window[0] ? $lead : $window[0];
            $to = $window[1];

            if ($from >= $to) {
                continue;
            }

            $room = $this->quota->quotaFor($settings, $history->activeDaysBefore($cursor)) - $history->countOn($cursor);
            $limit = min($room, $remainingTotal, SlotDrawer::capacity($to->getTimestamp() - $from->getTimestamp(), $settings->gapSeconds()));

            if ($limit <= 0) {
                continue;
            }

            $picks = $this->picker->pick($pool, $limit, $history, $settings, $cursor, $suppressions);

            $gone = array_flip(array_column([...$picks['picked'], ...$picks['suppressed']], 'id'));
            $pool = array_values(array_filter($pool, fn (array $row) => ! isset($gone[$row['id']])));

            if ($picks['picked'] === []) {
                continue;
            }

            $entries = [];

            foreach ($picks['picked'] as $row) {
                $entries[] = ['time' => null, 'id' => $row['id'], 'position' => $row['position'], 'email' => $row['email']];
                $history->add($from, $row['domain']);
            }

            $remainingTotal -= count($entries);
            $days[] = new ProjectedDay($cursor, false, $entries);
            $lastDay = $cursor;
        }

        $finishes = $pool === [] || $stoppedBy === 'max_total';

        return new Projection(
            days: $days,
            estimatedEnd: $finishes ? $lastDay : null,
            stoppedBy: $stoppedBy,
            leftOver: $stoppedBy === 'end_at' ? count($pool) : 0,
        );
    }
}
