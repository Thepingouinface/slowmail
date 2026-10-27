<?php

namespace App\Services\Sending;

use App\Enums\CampaignStatus;
use App\Enums\DecisionKind;
use App\Enums\DeliveryOutcome;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Planning\Planner;
use App\Support\Utc;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Un passage du scheduler (`slowmail:tick`, chaque minute).
 *
 * En cas de doute, il n'envoie rien : un tick envoie **au plus un mail**, jamais en rafale ; une ligne
 * dont l'envoi a été interrompu n'est jamais renvoyée seule ; le kill switch coupe tout aussitôt.
 */
final class TickRunner
{
    /** Garde-fou : combien de lignes écartées (supprimées, ignorées) un tick enchaîne avant de s'arrêter. */
    private const MAX_STEPS = 10;

    /** Le verrou expire de lui-même si un tick meurt sans le rendre. */
    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly Planner $planner,
        private readonly DeliveryService $delivery,
        private readonly CampaignLifecycle $lifecycle,
        private readonly SchedulerState $state,
        private readonly SendingRecovery $recovery,
    ) {}

    public function run(?CarbonInterface $now = null): TickReport
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        // Un seul tick à la fois. Le verrou est la première barrière ; la réclamation atomique de la ligne,
        // la seconde, au cas où deux processus passeraient quand même.
        $lock = Cache::lock('slowmail:tick', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return TickReport::busy();
        }

        try {
            return $this->runLocked($now);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(CarbonImmutable $now): TickReport
    {
        $report = new TickReport;

        $this->noteInterruption($report, $this->state->recordTick($now), $now);

        // Même kill switch coupé : une ligne restée en `sending` doit être signalée.
        $report->uncertain = $this->recovery->sweep($now);

        if (! config('slowmail.enabled')) {
            $report->disabled = true;

            return $report;
        }

        $waitingUntil = [];

        foreach (Campaign::query()->where('status', CampaignStatus::Active->value)->orderBy('id')->get() as $campaign) {
            if ($this->process($campaign, $now, $report, $waitingUntil)) {
                break;
            }
        }

        $this->state->setNextSendAt($this->nextSend($now, $waitingUntil));

        return $report;
    }

    /**
     * Fait avancer une campagne.
     *
     * @param  list<CarbonImmutable>  $waitingUntil
     * @return bool vrai : un envoi a été tenté, le tick s'arrête (un mail au plus par tick)
     */
    private function process(Campaign $campaign, CarbonImmutable $now, TickReport $report, array &$waitingUntil): bool
    {
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $decision = $this->planner->tick($campaign, $now);

            switch ($decision->kind) {
                case DecisionKind::Finished:
                    $this->lifecycle->finish($campaign, (string) $decision->reason);
                    $report->finished[] = ['campaign' => $campaign->id, 'reason' => (string) $decision->reason];

                    return false;

                case DecisionKind::Blocked:
                    $this->pause($campaign, (string) $decision->reason, $report);

                    return false;

                case DecisionKind::Wait:
                    if ($decision->nextAt !== null) {
                        $waitingUntil[] = $decision->nextAt;
                    }

                    return false;

                case DecisionKind::Send:
                    $delivery = $this->delivery->deliver($campaign, $decision->recipient, $now);

                    $report->deliveries[] = [
                        'campaign' => $campaign->id,
                        'recipient' => $decision->recipient->id,
                        'outcome' => $delivery->outcome,
                        'reason' => $delivery->reason,
                    ];

                    if ($delivery->outcome === DeliveryOutcome::Halted) {
                        $this->pause($campaign, (string) $delivery->reason, $report);

                        return false;
                    }

                    if ($delivery->outcome === DeliveryOutcome::Disabled) {
                        $report->disabled = true;

                        return true;
                    }

                    if ($delivery->pausedCampaign) {
                        $report->paused[] = ['campaign' => $campaign->id, 'reason' => (string) $delivery->reason];
                    }

                    if ($delivery->outcome->consumesTick()) {
                        return true;
                    }

                    // Une ligne écartée (supprimée, valeur vide) n'est pas un envoi : on regarde la suivante.
            }
        }

        return false;
    }

    private function pause(Campaign $campaign, string $reason, TickReport $report): void
    {
        $this->lifecycle->pause($campaign, $reason);
        $report->paused[] = ['campaign' => $campaign->id, 'reason' => $reason];
    }

    /** « interruption détectée (6 h) » dans le journal de chaque campagne active, quand le scheduler a manqué un long moment. */
    private function noteInterruption(TickReport $report, ?CarbonImmutable $previous, CarbonImmutable $now): void
    {
        if ($previous === null) {
            return;
        }

        $minutes = intdiv($now->getTimestamp() - $previous->getTimestamp(), 60);

        if ($minutes <= (int) config('slowmail.sending.interruption_minutes')) {
            return;
        }

        $report->interruptionMinutes = $minutes;

        foreach (Campaign::query()->where('status', CampaignStatus::Active->value)->pluck('id') as $id) {
            Journal::record($id, 'interruption', 'Interruption détectée ('.Journal::duration($minutes).') : aucun passage du scheduler pendant ce temps.');
        }
    }

    /**
     * Le prochain envoi prévu : le plus proche créneau planifié des campagnes actives, ou à défaut
     * l'ouverture de la prochaine plage annoncée par le planificateur.
     *
     * @param  list<CarbonImmutable>  $waitingUntil
     */
    private function nextSend(CarbonImmutable $now, array $waitingUntil): ?CarbonImmutable
    {
        $slot = Recipient::query()
            ->whereIn('campaign_id', Campaign::query()->where('status', CampaignStatus::Active->value)->select('id'))
            ->where('status', RecipientStatus::Planned->value)
            ->where('planned_at', '>', Utc::sql($now))
            ->min('planned_at');

        $candidates = $waitingUntil;

        if ($slot !== null) {
            $candidates[] = CarbonImmutable::parse($slot, 'UTC');
        }

        $candidates = array_filter($candidates, fn (CarbonImmutable $at) => $at > $now);

        return $candidates === [] ? null : min($candidates);
    }
}
