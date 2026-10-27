<?php

namespace App\Services\Planning;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Import\SuppressionList;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le planificateur d'une campagne : appelé à chaque tick, il décide, il n'envoie rien.
 *
 * - Au premier passage de chaque jour (dans le fuseau de la campagne) il tire les heures du jour
 *   et les range dans `recipients.planned_at`. Le jour tiré est réclamé atomiquement
 *   (`campaigns.planned_for`) : un redémarrage ou un second tick ne les retire jamais.
 * - Un créneau passé de plus de SLOWMAIL_GRACE_MINUTES n'est jamais rattrapé : il est replanifié sur
 *   la plage restante du jour, ou remis en attente pour le prochain jour autorisé.
 * - Le quota d'un jour manqué est perdu, pas cumulé : le quota se compte par jour, sur ce qui est parti.
 * - Plage, jour autorisé, quota et écart sont revérifiés à l'instant de l'envoi, pas seulement au tirage.
 *
 * Un appel renvoie au plus une ligne à envoyer. Le tick de la phase 4 l'envoie.
 */
final class Planner
{
    /** Un créneau replanifié ou tiré en cours de journée n'est jamais dû dans le tick qui le crée. */
    private const LEAD_SECONDS = 60;

    public function __construct(
        private readonly SendCalendar $calendar,
        private readonly QuotaCalculator $quota,
        private readonly SlotDrawer $drawer,
        private readonly RowPicker $picker,
    ) {}

    public function tick(Campaign $campaign, ?CarbonInterface $now = null): Decision
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $settings = CampaignSettings::for($campaign);

        if (($problems = $settings->violations()) !== []) {
            return Decision::blocked('Réglages invalides : '.implode(' ', $problems));
        }

        $history = DispatchHistory::load($campaign, $settings->timezone);

        if ($settings->endAt !== null && $now >= $settings->endAt) {
            return Decision::finished('La date de fin de la campagne est passée.');
        }

        if ($settings->maxTotal !== null && $history->total() >= $settings->maxTotal) {
            return Decision::finished("Le maximum de {$settings->maxTotal} mails est atteint.");
        }

        if (! $this->hasRemaining($campaign)) {
            return Decision::finished('Plus aucun destinataire à contacter.');
        }

        if ($settings->startAt !== null && $now < $settings->startAt) {
            return Decision::wait('La campagne n\'a pas encore atteint sa date de début.', $this->calendar->nextWindowStart($settings, $now));
        }

        $day = $now->setTimezone($settings->timezone)->startOfDay();

        $this->releaseStale($campaign, $settings, $day);
        $this->drawDay($campaign, $settings, $day, $now, $history);

        $window = $this->calendar->window($settings, $day);

        if ($window === null) {
            return Decision::wait(
                'Pas d\'envoi aujourd\'hui (jour non autorisé, férié, ou hors des dates de la campagne).',
                $this->calendar->nextWindowStart($settings, $now),
            );
        }

        $this->replanLate($campaign, $settings, $window, $day, $now, $history);

        return $this->decide($campaign, $settings, $window, $day, $now, $history);
    }

    /**
     * Remet en attente les créneaux d'aujourd'hui non envoyés et oublie le plan du jour : le prochain
     * tick en tire un nouveau. À appeler quand les réglages changent en cours de journée.
     *
     * @return int nombre de lignes remises en attente
     */
    public function invalidate(Campaign $campaign): int
    {
        return DB::transaction(function () use ($campaign) {
            $released = 0;

            foreach ($this->planned($campaign)->get(['id']) as $row) {
                $released += $this->release($campaign, $row->id, 'Plan du jour annulé (réglages modifiés) : remis en attente.') ? 1 : 0;
            }

            Campaign::query()->whereKey($campaign->id)->update(['planned_for' => null]);
            $campaign->forceFill(['planned_for' => null])->syncOriginalAttribute('planned_for');

            return $released;
        });
    }

    /** Les créneaux d'un jour passé n'ont plus de sens : leurs lignes retournent en attente. */
    private function releaseStale(Campaign $campaign, CampaignSettings $settings, CarbonImmutable $day): void
    {
        $stale = $this->planned($campaign)->where('planned_at', '<', $this->sql($day))->orderBy('planned_at')->get(['id', 'planned_at']);

        foreach ($stale as $row) {
            $this->release($campaign, $row->id, sprintf(
                'Planifié le %s mais non envoyé : remis en attente.',
                $this->label(CarbonImmutable::instance($row->planned_at), $settings),
            ));
        }
    }

    /** Tire les heures du jour, une seule fois. */
    private function drawDay(Campaign $campaign, CampaignSettings $settings, CarbonImmutable $day, CarbonImmutable $now, DispatchHistory $history): void
    {
        $date = $day->format('Y-m-d');

        if ($campaign->planned_for?->format('Y-m-d') === $date) {
            return;
        }

        DB::transaction(function () use ($campaign, $settings, $day, $now, $history, $date) {
            // Réclamation atomique : un seul tick obtient le droit de tirer ce jour-là.
            $claimed = Campaign::query()
                ->whereKey($campaign->id)
                ->where(fn (Builder $query) => $query->whereNull('planned_for')->orWhere('planned_for', '!=', $date))
                ->update(['planned_for' => $date]);

            // Qu'on ait réclamé le jour ou qu'un autre processus l'ait fait avant nous, la campagne le sait désormais.
            $campaign->forceFill(['planned_for' => $date])->syncOriginalAttribute('planned_for');

            if ($claimed === 0) {
                return;
            }

            $window = $this->calendar->window($settings, $day);

            if ($window === null) {
                return;
            }

            $already = $this->plannedToday($campaign, $day);
            $quota = $this->quota->quotaFor($settings, $history->activeDaysBefore($day));
            $room = max(0, $quota - $history->countOn($day) - $already->count());

            if ($settings->maxTotal !== null) {
                $room = min($room, max(0, $settings->maxTotal - $history->total() - $already->count()));
            }

            $lead = $now->addSeconds(self::LEAD_SECONDS);
            $from = $lead > $window[0] ? $lead : $window[0];
            $to = $window[1];

            if ($room === 0 || $from >= $to) {
                return;
            }

            $occupied = [
                ...$history->momentsOn($day),
                ...$already->map(fn (Recipient $row) => CarbonImmutable::instance($row->planned_at))->all(),
            ];

            $limit = min($room, SlotDrawer::capacity($to->getTimestamp() - $from->getTimestamp(), $settings->gapSeconds()));
            $picks = $this->picker->pick($this->candidates($campaign), $limit, $history, $settings, $day, SuppressionList::load());

            foreach ($picks['suppressed'] as $row) {
                $this->suppress($campaign, $row['id']);
            }

            $slots = $this->drawer->drawAround($from, $to, count($picks['picked']), $settings->gapSeconds(), $settings->jitter, $occupied);
            $assigned = [];

            foreach ($slots as $i => $slot) {
                $updated = Recipient::query()
                    ->whereKey($picks['picked'][$i]['id'])
                    ->where('status', RecipientStatus::Pending->value)
                    ->update(['status' => RecipientStatus::Planned->value, 'planned_at' => $this->sql($slot)]);

                if ($updated > 0) {
                    $assigned[] = $slot->setTimezone($settings->timezone)->format('H:i');
                }
            }

            if ($assigned !== []) {
                $this->log($campaign, 'plan', sprintf(
                    '%d envoi(s) planifié(s) pour le %s : %s (quota du jour : %d).',
                    count($assigned),
                    $day->locale('fr')->isoFormat('D MMMM'),
                    implode(', ', $assigned),
                    $quota,
                ));
            }
        });
    }

    /**
     * Un créneau en retard de plus que la tolérance n'est pas rattrapé : il est replanifié sur la plage
     * restante du jour (écart minimum respecté), sinon remis en attente pour le prochain jour autorisé.
     * Le nombre de lignes planifiées aujourd'hui ne change pas : le quota non plus.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     */
    private function replanLate(Campaign $campaign, CampaignSettings $settings, array $window, CarbonImmutable $day, CarbonImmutable $now, DispatchHistory $history): void
    {
        $grace = (int) config('slowmail.grace_minutes');
        $limit = $now->subMinutes($grace);

        $late = $this->planned($campaign)->where('planned_at', '<', $this->sql($limit))->orderBy('planned_at')->get(['id', 'planned_at']);

        if ($late->isEmpty()) {
            return;
        }

        $lead = $now->addSeconds(self::LEAD_SECONDS);
        $from = $lead > $window[0] ? $lead : $window[0];
        $to = $window[1];
        $lateIds = $late->pluck('id')->all();

        $occupied = [
            ...$history->momentsOn($day),
            ...$this->plannedToday($campaign, $day)
                ->reject(fn (Recipient $row) => in_array($row->id, $lateIds, true))
                ->map(fn (Recipient $row) => CarbonImmutable::instance($row->planned_at))
                ->all(),
        ];

        foreach ($late as $row) {
            $was = CarbonImmutable::instance($row->planned_at);
            $slot = $from < $to ? $this->drawer->pickFree($from, $to, $occupied, $settings->gapSeconds(), $settings->jitter) : null;

            if ($slot === null) {
                $this->release($campaign, $row->id, sprintf(
                    'Créneau de %s passé de plus de %d min et plus de place aujourd\'hui : remis en attente pour le prochain jour autorisé.',
                    $was->setTimezone($settings->timezone)->format('H:i'),
                    $grace,
                ));

                continue;
            }

            $moved = Recipient::query()
                ->whereKey($row->id)
                ->where('status', RecipientStatus::Planned->value)
                ->update(['planned_at' => $this->sql($slot)]);

            if ($moved > 0) {
                $occupied[] = $slot;
                $this->log($campaign, 'replan', sprintf(
                    'Créneau de %s passé de plus de %d min : replanifié à %s.',
                    $was->setTimezone($settings->timezone)->format('H:i'),
                    $grace,
                    $slot->setTimezone($settings->timezone)->format('H:i'),
                ), $row->id);
            }
        }
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     */
    private function decide(Campaign $campaign, CampaignSettings $settings, array $window, CarbonImmutable $day, CarbonImmutable $now, DispatchHistory $history): Decision
    {
        $due = $this->planned($campaign)
            ->where('planned_at', '<=', $this->sql($now))
            ->orderBy('planned_at')
            ->orderBy('position')
            ->get();

        foreach ($due as $row) {
            // Revérifié à l'instant de l'envoi : la plage peut s'être refermée depuis le tirage.
            if ($now < $window[0] || $now > $window[1]) {
                $this->release($campaign, $row->id, 'Hors de la plage horaire au moment de l\'envoi : remis en attente.');

                continue;
            }

            if ($history->countOn($day) >= $this->quota->quotaFor($settings, $history->activeDaysBefore($day))) {
                return Decision::wait('Le quota du jour est atteint.', $this->calendar->nextWindowStart($settings, $now));
            }

            $last = $history->lastDispatchAt();

            if ($settings->minGapMinutes > 0 && $last !== null && $now < $last->addSeconds($settings->gapSeconds())) {
                return Decision::wait('L\'écart minimum depuis le dernier mail n\'est pas encore écoulé.', $last->addSeconds($settings->gapSeconds()));
            }

            $lastDay = $history->lastDayFor($row->domain);

            if ($settings->domainCooldownDays > 0 && $lastDay !== null && DispatchHistory::dayNumber($day) - $lastDay < $settings->domainCooldownDays) {
                $this->release($campaign, $row->id, "Un mail est déjà parti à ce domaine il y a moins de {$settings->domainCooldownDays} jours : remis en attente.");

                continue;
            }

            return Decision::send($row);
        }

        $next = $this->planned($campaign)->where('planned_at', '>', $this->sql($now))->min('planned_at');

        if ($next !== null) {
            return Decision::wait('Prochain envoi planifié.', CarbonImmutable::parse($next, 'UTC'));
        }

        return Decision::wait('Plus d\'envoi prévu aujourd\'hui.', $this->calendar->nextWindowStart($settings, $now));
    }

    /**
     * Reste-t-il quelque chose à faire ? Une ligne en cours d'envoi ou incertaine compte : tant que l'utilisateur
     * n'a pas tranché (« C'est parti » / « Pas parti »), la campagne ne se termine pas, sinon une ligne remise en
     * attente se retrouverait dans une campagne finie qui n'enverrait plus rien.
     */
    private function hasRemaining(Campaign $campaign): bool
    {
        return Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Planned->value,
                RecipientStatus::Sending->value,
                RecipientStatus::Uncertain->value,
            ])
            ->exists();
    }

    /** @return Builder<Recipient> */
    private function planned(Campaign $campaign): Builder
    {
        return Recipient::query()->where('campaign_id', $campaign->id)->where('status', RecipientStatus::Planned->value);
    }

    /** @return Collection<int, Recipient> */
    private function plannedToday(Campaign $campaign, CarbonImmutable $day): Collection
    {
        return $this->planned($campaign)
            ->where('planned_at', '>=', $this->sql($day))
            ->where('planned_at', '<', $this->sql($day->addDay()->startOfDay()))
            ->orderBy('planned_at')
            ->get();
    }

    /** @return list<array{id: int, position: int, email: string, domain: string}> */
    private function candidates(Campaign $campaign): array
    {
        return Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Pending->value)
            ->orderBy('position')
            ->get(['id', 'position', 'email', 'domain'])
            ->map(fn (Recipient $row) => ['id' => $row->id, 'position' => $row->position, 'email' => $row->email, 'domain' => $row->domain])
            ->all();
    }

    private function release(Campaign $campaign, int $recipientId, string $detail): bool
    {
        $released = Recipient::query()
            ->whereKey($recipientId)
            ->where('status', RecipientStatus::Planned->value)
            ->update(['status' => RecipientStatus::Pending->value, 'planned_at' => null]);

        if ($released > 0) {
            $this->log($campaign, 'replan', $detail, $recipientId);
        }

        return $released > 0;
    }

    /** Une adresse supprimée ne reçoit jamais de mail : elle sort du plan (l'envoi la revérifie encore). */
    private function suppress(Campaign $campaign, int $recipientId): void
    {
        $changed = Recipient::query()
            ->whereKey($recipientId)
            ->where('status', RecipientStatus::Pending->value)
            ->update(['status' => RecipientStatus::Suppressed->value]);

        if ($changed > 0) {
            $this->log($campaign, 'suppressed', 'Adresse présente dans la liste de suppression : retirée du plan.', $recipientId);
        }
    }

    private function log(Campaign $campaign, string $event, string $detail, ?int $recipientId = null): void
    {
        SendLog::create(['campaign_id' => $campaign->id, 'recipient_id' => $recipientId, 'event' => $event, 'detail' => $detail]);
    }

    /** Les dates se stockent et se comparent en UTC, au format de la base. */
    private function sql(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s');
    }

    private function label(CarbonImmutable $time, CampaignSettings $settings): string
    {
        return $time->setTimezone($settings->timezone)->locale('fr')->isoFormat('D MMMM [à] HH:mm');
    }
}
