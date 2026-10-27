<?php

use App\Enums\DecisionKind;
use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Le lundi 19 octobre 2026 est un jour d'envoi ordinaire (CEST, pas férié).

function localTimes(iterable $rows, string $timezone = 'Europe/Paris'): array
{
    $times = [];

    foreach ($rows as $row) {
        $times[] = CarbonImmutable::instance($row->planned_at)->setTimezone($timezone);
    }

    return $times;
}

function assertSpacedAtLeast(array $times, int $seconds): void
{
    usort($times, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

    foreach ($times as $i => $time) {
        if ($i > 0) {
            expect($time->getTimestamp() - $times[$i - 1]->getTimestamp())->toBeGreaterThanOrEqual($seconds);
        }
    }
}

// --- Le plan du jour ---------------------------------------------------------------------------

it('tire les heures du jour au premier passage, dans la plage, espacées, dans l\'ordre d\'envoi', function () {
    $campaign = planningCampaign();

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));
    $rows = plannedRows($campaign);
    $times = localTimes($rows);

    expect($decision->kind)->toBe(DecisionKind::Wait)
        ->and($campaign->fresh()->planned_for->format('Y-m-d'))->toBe('2026-10-19')
        ->and($rows)->toHaveCount(4)
        ->and($decision->nextAt->equalTo($rows[0]->planned_at))->toBeTrue();

    foreach ($times as $time) {
        expect($time >= paris('2026-10-19 09:30') && $time <= paris('2026-10-19 17:30'))->toBeTrue()
            ->and($time->minute === 0 && $time->second === 0)->toBeFalse();
    }

    assertSpacedAtLeast($times, 25 * 60);

    // Les lignes dans l'ordre du fichier, aux heures dans l'ordre du temps.
    $premieres = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(4)->pluck('id')->all();
    expect($rows->pluck('id')->all())->toBe($premieres)
        ->and(SendLog::where('event', 'plan')->count())->toBe(1);
});

it('range les heures en UTC dans la base, et seulement à la plage locale', function () {
    $campaign = planningCampaign();

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    // En octobre, Paris est à UTC+2 : 9 h 30 locales = 7 h 30 UTC.
    $enBase = DB::table('recipients')->where('status', 'planned')->pluck('planned_at')->map(fn ($v) => substr($v, 11))->sort()->values();

    expect($enBase->first() >= '07:30:00')->toBeTrue()
        ->and($enBase->last() <= '15:30:00')->toBeTrue();
});

it('suit le changement d\'heure : la plage reste 9 h 30 à 17 h 30 locales', function () {
    $avant = planningCampaign();
    seededPlanner()->tick($avant, paris('2026-10-23 07:00'));
    $avantUtc = DB::table('recipients')->where('campaign_id', $avant->id)->where('status', 'planned')->pluck('planned_at')->map(fn ($v) => substr($v, 11))->sort()->values();

    $apres = planningCampaign();
    seededPlanner()->tick($apres, paris('2026-10-26 07:00'));
    $apresUtc = DB::table('recipients')->where('campaign_id', $apres->id)->where('status', 'planned')->pluck('planned_at')->map(fn ($v) => substr($v, 11))->sort()->values();

    // Passage à l'heure d'hiver le 25 octobre : UTC+2 puis UTC+1.
    expect($avantUtc->first() >= '07:30:00' && $avantUtc->last() <= '15:30:00')->toBeTrue()
        ->and($apresUtc->first() >= '08:30:00' && $apresUtc->last() <= '16:30:00')->toBeTrue();
});

it('calcule la plage dans le fuseau de la campagne', function () {
    $campaign = planningCampaign(['timezone' => 'America/New_York']);

    seededPlanner()->tick($campaign, paris('2026-10-19 08:00', 'America/New_York'));

    foreach (localTimes(plannedRows($campaign), 'America/New_York') as $time) {
        expect($time >= paris('2026-10-19 09:30', 'America/New_York') && $time <= paris('2026-10-19 17:30', 'America/New_York'))->toBeTrue();
    }

    expect(plannedRows($campaign))->toHaveCount(4);
});

it('ne retire pas le plan du même jour, même après un redémarrage', function () {
    $campaign = planningCampaign();

    seededPlanner(1)->tick($campaign, paris('2026-10-19 07:00'));
    $avant = plannedTimes($campaign);

    // Redémarrage : un nouveau planificateur, une campagne relue en base, une autre graine.
    foreach (['2026-10-19 07:01', '2026-10-19 09:00', '2026-10-19 09:29'] as $moment) {
        seededPlanner(99)->tick(Campaign::find($campaign->id), paris($moment));
    }

    expect(plannedTimes($campaign))->toBe($avant)
        ->and(SendLog::where('event', 'plan')->count())->toBe(1)
        ->and(plannedRows($campaign))->toHaveCount(4);
});

it('ne tire pas deux fois quand deux ticks partent d\'une copie périmée de la campagne', function () {
    $campaign = planningCampaign();
    $perimee = Campaign::find($campaign->id);
    $planner = seededPlanner();

    $planner->tick($campaign, paris('2026-10-19 07:00'));
    $planner->tick($perimee, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(4)->and(SendLog::where('event', 'plan')->count())->toBe(1);
});

it('ne tire pas un jour qu\'un autre processus vient de réclamer', function () {
    $campaign = planningCampaign();

    // Un autre processus a réclamé le 19 octobre, mais ses créneaux ne sont pas encore visibles :
    // notre copie de la campagne, périmée, ne doit pas tirer le même jour une seconde fois.
    Campaign::query()->whereKey($campaign->id)->update(['planned_for' => '2026-10-19']);
    expect($campaign->planned_for)->toBeNull();

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(0)
        ->and(SendLog::where('event', 'plan')->count())->toBe(0)
        ->and($campaign->planned_for->format('Y-m-d'))->toBe('2026-10-19');
});

it('tire un nouveau plan le lendemain', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();

    $planner->tick($campaign, paris('2026-10-19 07:00'));
    simulateTicks($planner, $campaign, paris('2026-10-19 07:01'), paris('2026-10-19 17:45'), 5);
    $planner->tick($campaign, paris('2026-10-20 07:00'));

    expect($campaign->fresh()->planned_for->format('Y-m-d'))->toBe('2026-10-20')
        ->and(plannedRows($campaign))->toHaveCount(4)
        ->and(Recipient::where('status', 'sent')->count())->toBe(4);
});

it('tire le plan du jour au premier passage même avant la plage', function () {
    $campaign = planningCampaign();

    seededPlanner()->tick($campaign, paris('2026-10-19 03:00'));

    expect(plannedRows($campaign))->toHaveCount(4);
});

it('plan vide, jour marqué, quand le premier passage a lieu après la plage', function () {
    $campaign = planningCampaign();

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 21:00'));

    expect(plannedRows($campaign))->toHaveCount(0)
        ->and($campaign->fresh()->planned_for->format('Y-m-d'))->toBe('2026-10-19')
        ->and($decision->kind)->toBe(DecisionKind::Wait)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-10-20 09:30');
});

it('ne tire que sur la plage restante quand la campagne démarre en cours de journée', function () {
    $campaign = planningCampaign();

    seededPlanner()->tick($campaign, paris('2026-10-19 15:00'));

    $times = localTimes(plannedRows($campaign));

    expect($times)->not->toBeEmpty();

    foreach ($times as $time) {
        expect($time >= paris('2026-10-19 15:01') && $time <= paris('2026-10-19 17:30'))->toBeTrue();
    }

    assertSpacedAtLeast($times, 25 * 60);
});

it('ne tire que ce qui tient quand la plage restante est courte', function () {
    $campaign = planningCampaign();

    // 16 h 40 → 17 h 30 : 49 minutes, deux mails espacés de 25 minutes au plus.
    seededPlanner()->tick($campaign, paris('2026-10-19 16:40'));

    expect(plannedRows($campaign)->count())->toBeLessThanOrEqual(2)->and(plannedRows($campaign)->count())->toBeGreaterThanOrEqual(1);
});

it('compte ce qui est déjà parti aujourd\'hui dans le quota du jour', function () {
    $campaign = planningCampaign();
    $deja = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get();
    markSent($deja[0], paris('2026-10-19 08:50'));
    markSent($deja[1], paris('2026-10-19 09:35'));

    seededPlanner()->tick($campaign, paris('2026-10-19 10:00'));

    $times = localTimes(plannedRows($campaign));

    expect($times)->toHaveCount(2);
    assertSpacedAtLeast([...$times, paris('2026-10-19 09:35')], 25 * 60);
});

it('ne planifie rien de plus quand le quota du jour est déjà atteint', function () {
    $campaign = planningCampaign();

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(4)->get() as $i => $row) {
        markSent($row, paris('2026-10-19 08:00')->addMinutes($i * 30));
    }

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 10:00'));

    expect(plannedRows($campaign))->toHaveCount(0)->and($decision->kind)->toBe(DecisionKind::Wait);
});

it('respecte daily_max, quelle que soit sa valeur', function () {
    foreach ([1, 2, 7, 10] as $max) {
        $campaign = planningCampaign(['daily_max' => $max], 30);

        seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

        expect(plannedRows($campaign))->toHaveCount($max);
    }
});

it('planifie moins que le quota quand il ne reste pas assez de destinataires', function () {
    $campaign = planningCampaign(rows: 2);

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(2);
});

it('tire des heures régulières sans jitter', function () {
    $campaign = planningCampaign(['jitter' => false]);

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedTimes($campaign))->toBe(['09:30:00', '12:10:00', '14:50:00', '17:30:00']);
});

// --- Jours, dates ------------------------------------------------------------------------------

it('ne tire rien un week-end et attend le lundi', function () {
    $campaign = planningCampaign();

    foreach (['2026-10-24 11:00', '2026-10-25 11:00'] as $jour) {
        $decision = seededPlanner()->tick($campaign, paris($jour));

        expect($decision->kind)->toBe(DecisionKind::Wait)
            ->and($decision->nextAt->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-10-26 09:30')
            ->and(plannedRows($campaign))->toHaveCount(0);
    }
});

it('ne tire rien un jour férié et attend le jour suivant', function () {
    $campaign = planningCampaign();

    // Le mercredi 11 novembre 2026 : Armistice.
    $decision = seededPlanner()->tick($campaign, paris('2026-11-11 08:00'));

    expect($decision->kind)->toBe(DecisionKind::Wait)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-11-12 09:30')
        ->and(plannedRows($campaign))->toHaveCount(0);
});

it('envoie un jour férié quand skip_holidays est coupé', function () {
    $campaign = planningCampaign(['skip_holidays' => false]);

    seededPlanner()->tick($campaign, paris('2026-11-11 08:00'));

    expect(plannedRows($campaign))->toHaveCount(4);
});

it('envoie les jours choisis, y compris le samedi', function () {
    $campaign = planningCampaign(['days' => [6]]);

    seededPlanner()->tick($campaign, paris('2026-10-24 08:00'));

    expect(plannedRows($campaign))->toHaveCount(4);
});

it('attend la date de début sans rien tirer', function () {
    $campaign = planningCampaign(attributes: ['start_at' => paris('2026-10-19 14:00')->utc()]);

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 08:00'));

    expect($decision->kind)->toBe(DecisionKind::Wait)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('H:i'))->toBe('14:00')
        ->and($campaign->fresh()->planned_for)->toBeNull()
        ->and(plannedRows($campaign))->toHaveCount(0);
});

it('ne tire qu\'à partir de la date de début', function () {
    $campaign = planningCampaign(attributes: ['start_at' => paris('2026-10-19 14:00')->utc()]);

    seededPlanner()->tick($campaign, paris('2026-10-19 14:00:30'));

    foreach (localTimes(plannedRows($campaign)) as $time) {
        expect($time >= paris('2026-10-19 14:01') && $time <= paris('2026-10-19 17:30'))->toBeTrue();
    }

    expect(plannedRows($campaign)->count())->toBeGreaterThan(0);
});

it('ne tire rien après la date de fin et se déclare terminée', function () {
    $campaign = planningCampaign(attributes: ['end_at' => paris('2026-10-19 12:00')->utc()]);

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    foreach (localTimes(plannedRows($campaign)) as $time) {
        expect($time < paris('2026-10-19 12:00'))->toBeTrue();
    }

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 12:00:01'));

    expect($decision->kind)->toBe(DecisionKind::Finished)->and($decision->reason)->toContain('date de fin');
});

it('respecte la date de fin après une longue interruption', function () {
    $campaign = planningCampaign(attributes: ['end_at' => paris('2026-10-21 12:00')->utc()]);

    $decision = seededPlanner()->tick($campaign, paris('2026-11-30 10:00'));

    expect($decision->kind)->toBe(DecisionKind::Finished)->and(plannedRows($campaign))->toHaveCount(0);
});

it('s\'arrête au maximum total, après une longue interruption comme à l\'ordinaire', function () {
    $campaign = planningCampaign(['max_total' => 3], 20);

    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(3);

    $sends = simulateTicks($planner, $campaign, paris('2026-10-19 07:01'), paris('2026-10-19 17:45'), 5);

    expect($sends)->toHaveCount(3);

    $decision = $planner->tick($campaign, paris('2026-12-01 10:00'));

    expect($decision->kind)->toBe(DecisionKind::Finished)->and($decision->reason)->toContain('3 mails');
});

it('ne tire pas plus que le reste du maximum total', function () {
    $campaign = planningCampaign(['max_total' => 5], 20);
    $deja = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(3)->get();

    foreach ($deja as $i => $row) {
        markSent($row, paris('2026-10-16 10:00')->addMinutes($i * 30));
    }

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(2);
});

it('se déclare terminée quand il ne reste plus de destinataire', function () {
    $campaign = planningCampaign(rows: 2);

    foreach (Recipient::where('campaign_id', $campaign->id)->get() as $row) {
        markSent($row, paris('2026-10-16 10:00'));
    }

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect($decision->kind)->toBe(DecisionKind::Finished)->and($decision->reason)->toContain('Plus aucun destinataire');
});

it('ne se déclare pas terminée tant qu\'une ligne est en cours d\'envoi ou incertaine', function () {
    foreach ([RecipientStatus::Sending, RecipientStatus::Uncertain] as $status) {
        $campaign = planningCampaign(rows: 2);

        foreach (Recipient::where('campaign_id', $campaign->id)->get() as $i => $row) {
            $i === 0
                ? $row->forceFill(['status' => $status, 'sending_started_at' => paris('2026-10-16 10:00')->utc()])->save()
                : markSent($row, paris('2026-10-16 10:30'));
        }

        // Sans cela, « Pas parti » remettrait la ligne en attente dans une campagne finie, qui n'enverrait plus rien.
        $decision = seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

        expect($decision->kind)->not->toBe(DecisionKind::Finished, $status->value);
    }
});

it('ne se déclare pas terminée tant qu\'une ligne attend ou est planifiée', function () {
    $campaign = planningCampaign(rows: 3);

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get() as $row) {
        $row->update(['status' => RecipientStatus::Skipped]);
    }

    expect(seededPlanner()->tick($campaign, paris('2026-10-19 07:00'))->kind)->toBe(DecisionKind::Wait);
});

it('refuse d\'envoyer quoi que ce soit avec des réglages invalides', function () {
    $campaign = planningCampaign(['window_start' => 'neuf heures', 'daily_max' => 0]);

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect($decision->kind)->toBe(DecisionKind::Blocked)
        ->and($decision->reason)->toContain('Réglages invalides')
        ->and(plannedRows($campaign))->toHaveCount(0)
        ->and($campaign->fresh()->planned_for)->toBeNull();
});

// --- Ce qui est dû, et seulement ce qui est dû -------------------------------------------------

it('renvoie une seule ligne à envoyer par tick, la plus ancienne', function () {
    $campaign = planningCampaign();
    [$a, $b] = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get()->all();
    planAt($a, '2026-10-19 10:00:13');
    planAt($b, '2026-10-19 10:00:40');
    markDrawn($campaign, '2026-10-19');

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 10:01:00'));

    expect($decision->shouldSend())->toBeTrue()->and($decision->recipient->id)->toBe($a->id);
});

it('n\'envoie rien avant l\'heure du créneau', function () {
    $campaign = planningCampaign();
    planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 10:00:13');
    markDrawn($campaign, '2026-10-19');

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 10:00:12'));

    expect($decision->shouldSend())->toBeFalse()
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('H:i:s'))->toBe('10:00:13');
});

it('ne renvoie jamais la ligne qui vient de partir', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 10:00:13');
    markDrawn($campaign, '2026-10-19');
    $planner = seededPlanner();

    $decision = $planner->tick($campaign, paris('2026-10-19 10:01:00'));
    markSent($decision->recipient, paris('2026-10-19 10:01:00'));

    expect($planner->tick($campaign, paris('2026-10-19 10:02:00'))->shouldSend())->toBeFalse();
});

it('fait attendre l\'écart minimum depuis le dernier mail, même si un autre est dû', function () {
    $campaign = planningCampaign();
    [$a, $b] = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get()->all();
    planAt($a, '2026-10-19 10:00:13');
    planAt($b, '2026-10-19 10:00:40');
    markDrawn($campaign, '2026-10-19');
    $planner = seededPlanner();

    markSent($planner->tick($campaign, paris('2026-10-19 10:01:00'))->recipient, paris('2026-10-19 10:01:00'));

    $decision = $planner->tick($campaign, paris('2026-10-19 10:02:00'));

    expect($decision->kind)->toBe(DecisionKind::Wait)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('H:i:s'))->toBe('10:26:00')
        ->and($planner->tick($campaign, paris('2026-10-19 10:25:59'))->shouldSend())->toBeFalse()
        ->and($planner->tick($campaign, paris('2026-10-19 10:26:00'))->shouldSend())->toBeTrue();
});

it('revérifie le quota du jour à l\'instant de l\'envoi', function () {
    $campaign = planningCampaign();

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(4)->get() as $i => $row) {
        markSent($row, paris('2026-10-19 08:00')->addMinutes($i * 30));
    }

    planAt(Recipient::where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('position')->first(), '2026-10-19 11:00:13');
    markDrawn($campaign, '2026-10-19');

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 11:01:00'));

    expect($decision->shouldSend())->toBeFalse()->and($decision->reason)->toContain('quota');
});

it('revérifie la plage horaire à l\'instant de l\'envoi', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 17:20:00');
    markDrawn($campaign, '2026-10-19');

    // 15 minutes de retard : dans la tolérance, mais la plage s'est refermée à 17 h 30.
    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 17:35:00'));

    expect($decision->shouldSend())->toBeFalse()
        ->and($row->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($row->fresh()->planned_at)->toBeNull();
});

it('revérifie le cooldown de domaine à l\'instant de l\'envoi', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'ancien@agence.example'), paris('2026-10-17 10:00'));
    $row = planAt(recipient($campaign, 'nouveau@agence.example'), '2026-10-19 10:00:13');
    markDrawn($campaign, '2026-10-19');

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 10:01:00'));

    expect($decision->shouldSend())->toBeFalse()
        ->and($row->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and(SendLog::where('recipient_id', $row->id)->where('event', 'replan')->first()->detail)->toContain('7 jours');
});

// --- Retard, veille du Mac, réveil -------------------------------------------------------------

it('envoie un créneau en retard de moins que la tolérance', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 10:12:00');
    markDrawn($campaign, '2026-10-19');

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 10:41:59'));

    expect($decision->shouldSend())->toBeTrue()->and($decision->recipient->id)->toBe($row->id);
});

it('replanifie, sans l\'envoyer dans le même tick, un créneau passé de plus que la tolérance', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 10:12:00');
    markDrawn($campaign, '2026-10-19');
    $now = paris('2026-10-19 10:43:00');

    $decision = seededPlanner()->tick($campaign, $now);
    $row = $row->fresh();

    expect($decision->shouldSend())->toBeFalse()
        ->and($row->status)->toBe(RecipientStatus::Planned)
        ->and($row->planned_at >= $now->addSeconds(60) && $row->planned_at <= paris('2026-10-19 17:30'))->toBeTrue()
        ->and($decision->nextAt->equalTo($row->planned_at))->toBeTrue()
        ->and(SendLog::where('event', 'replan')->where('recipient_id', $row->id)->value('detail'))->toContain('10:12')->toContain('replanifié');
});

it('replanifie sur la plage restante en gardant l\'écart avec ce qui est déjà parti', function () {
    foreach (range(1, 25) as $seed) {
        $campaign = planningCampaign();
        $deja = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first();
        markSent($deja, paris('2026-10-19 15:30:00'));
        $row = planAt(Recipient::where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('position')->first(), '2026-10-19 10:00:13');
        markDrawn($campaign, '2026-10-19');

        seededPlanner($seed)->tick($campaign, paris('2026-10-19 15:45:00'));

        $row = $row->fresh();

        // La place existe (de 15 h 55 à 17 h 30) : le créneau est toujours replanifié, jamais perdu.
        expect($row->status)->toBe(RecipientStatus::Planned, "graine {$seed}")
            ->and($row->planned_at >= paris('2026-10-19 15:55:00') && $row->planned_at <= paris('2026-10-19 17:30'))->toBeTrue("graine {$seed}");
    }
});

it('remet en attente un créneau en retard quand il n\'y a plus de place aujourd\'hui', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 10:12:00');
    markDrawn($campaign, '2026-10-19');

    // 17 h 20 : il reste dix minutes, moins que l'écart minimum depuis le dernier mail.
    markSent(Recipient::where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('position')->first(), paris('2026-10-19 17:15'));

    $decision = seededPlanner()->tick($campaign, paris('2026-10-19 17:20:00'));

    expect($decision->shouldSend())->toBeFalse()
        ->and($row->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($row->fresh()->planned_at)->toBeNull()
        ->and(SendLog::where('recipient_id', $row->id)->value('detail'))->toContain('prochain jour autorisé');
});

it('ne rattrape rien en rafale après six heures de veille : les mails sont replanifiés et espacés', function () {
    $campaign = planningCampaign();
    $lignes = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(4)->get();

    foreach (['10:00:13', '11:00:07', '12:30:41', '16:10:22'] as $i => $heure) {
        planAt($lignes[$i], "2026-10-19 {$heure}");
    }

    markDrawn($campaign, '2026-10-19');
    $planner = seededPlanner();

    // Le Mac dort de 9 h 40 à 15 h 45 : au réveil, trois créneaux sont passés.
    $reveil = paris('2026-10-19 15:45:00');
    $decision = $planner->tick($campaign, $reveil);

    expect($decision->shouldSend())->toBeFalse();

    $planifies = plannedRows($campaign);
    $remisEnAttente = Recipient::where('campaign_id', $campaign->id)->where('status', 'pending')->whereIn('id', $lignes->pluck('id'))->count();

    // Rien ne se perd : planifié à nouveau ou remis en attente, sans dépasser le quota.
    expect($planifies->count() + $remisEnAttente)->toBe(4)
        ->and($planifies->count())->toBeLessThanOrEqual(4)
        ->and(SendLog::where('event', 'replan')->count())->toBe(3);

    foreach ($planifies as $row) {
        expect($row->planned_at >= $reveil->addSeconds(60) || $row->id === $lignes[3]->id)->toBeTrue()
            ->and($row->planned_at <= paris('2026-10-19 17:30'))->toBeTrue();
    }

    assertSpacedAtLeast(localTimes($planifies), 25 * 60);

    // La suite, minute par minute : chaque mail part à son heure, espacés, jamais deux d'un coup.
    $envois = simulateTicks($planner, $campaign, $reveil->addMinute(), paris('2026-10-19 17:30'), 1);

    expect($envois)->toHaveCount($planifies->count());
    assertSpacedAtLeast(array_map(fn ($envoi) => $envoi['at'], $envois), 25 * 60);

    foreach ($envois as $envoi) {
        expect($envoi['at'] > $reveil)->toBeTrue();
    }
});

it('n\'envoie rien au réveil hors de la plage : soir, nuit', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));

    foreach (['2026-10-19 21:00', '2026-10-19 23:59', '2026-10-20 02:00'] as $moment) {
        expect($planner->tick($campaign, paris($moment))->shouldSend())->toBeFalse();
    }
});

it('remet en attente les créneaux du soir et attend le lendemain', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));

    $decision = $planner->tick($campaign, paris('2026-10-19 21:00'));

    expect(plannedRows($campaign))->toHaveCount(0)
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count())->toBe(20)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-10-20 09:30');
});

it('n\'envoie rien au réveil un week-end, et remet en attente les créneaux du vendredi', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-23 07:00'));

    $decision = $planner->tick($campaign, paris('2026-10-24 11:00'));

    expect($decision->shouldSend())->toBeFalse()
        ->and(plannedRows($campaign))->toHaveCount(0)
        ->and($decision->nextAt->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-10-26 09:30');
});

it('n\'envoie rien au réveil un jour férié', function () {
    $campaign = planningCampaign();
    $row = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-11-10 16:00:00');
    markDrawn($campaign, '2026-11-10');

    $decision = seededPlanner()->tick($campaign, paris('2026-11-11 10:00'));

    expect($decision->shouldSend())->toBeFalse()->and($row->fresh()->status)->toBe(RecipientStatus::Pending);
});

it('reprend à daily_max, pas à 3 × daily_max, après trois jours d\'arrêt', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();

    $planner->tick($campaign, paris('2026-10-19 07:00'));
    markSent(plannedRows($campaign)->first(), paris('2026-10-19 10:00'));

    // Rien ne tourne mardi, mercredi, jeudi. Vendredi matin, le Mac se réveille.
    $planner->tick($campaign, paris('2026-10-23 08:00'));

    expect(plannedRows($campaign))->toHaveCount(4)
        ->and($campaign->fresh()->planned_for->format('Y-m-d'))->toBe('2026-10-23')
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(1);

    foreach (localTimes(plannedRows($campaign)) as $time) {
        expect($time->format('Y-m-d'))->toBe('2026-10-23');
    }
});

it('remet d\'abord en tête de file les lignes qui n\'étaient pas parties, au jour suivant', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));
    $oubliees = plannedRows($campaign)->pluck('id')->all();

    $planner->tick($campaign, paris('2026-10-20 07:00'));

    // Les mêmes quatre lignes, en tête du fichier, sont replanifiées le lendemain.
    expect(plannedRows($campaign)->pluck('id')->all())->toBe($oubliees);
});

it('compte les jours d\'envoi réels, pas ceux du calendrier, pour la montée progressive', function () {
    $campaign = planningCampaign(['ramp_up' => true, 'ramp_start' => 2, 'ramp_step' => 1, 'ramp_every_days' => 3]);
    [$lundi, $mardi] = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get()->all();
    markSent($lundi, paris('2026-10-12 10:00'));
    markSent($mardi, paris('2026-10-13 10:00'));

    // Une semaine d'absence : dix-sept jours de calendrier, deux jours d'envoi.
    seededPlanner()->tick($campaign, paris('2026-10-26 07:00'));

    expect(plannedRows($campaign))->toHaveCount(2);
});

it('passe au palier suivant au troisième jour d\'envoi réel', function () {
    $campaign = planningCampaign(['ramp_up' => true, 'ramp_start' => 2, 'ramp_step' => 1, 'ramp_every_days' => 3]);

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(3)->get() as $i => $row) {
        markSent($row, paris('2026-10-12 10:00')->addDays($i));
    }

    seededPlanner()->tick($campaign, paris('2026-10-26 07:00'));

    expect(plannedRows($campaign))->toHaveCount(3);
});

// --- Domaines et suppression -------------------------------------------------------------------

it('ne planifie pas deux fois le même domaine le même jour', function () {
    $campaign = campaign();
    $a1 = recipient($campaign, 'a1@agence.example');
    $a2 = recipient($campaign, 'a2@agence.example');
    $a3 = recipient($campaign, 'a3@agence.example');

    foreach (range(1, 8) as $i) {
        recipient($campaign, "x{$i}@autre{$i}.example");
    }

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    $emails = plannedRows($campaign)->pluck('email')->all();

    expect($emails)->toHaveCount(4)->toContain('a1@agence.example')
        ->not->toContain('a2@agence.example')->not->toContain('a3@agence.example')
        ->and($a2->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($a3->fresh()->status)->toBe(RecipientStatus::Pending);
});

it('laisse passer devant une ligne dont le domaine est en cooldown, sans la perdre', function () {
    $campaign = campaign();
    markSent(recipient($campaign, 'ancien@agence.example'), paris('2026-10-16 10:00'));
    $bloquee = recipient($campaign, 'bloquee@agence.example');

    foreach (range(1, 6) as $i) {
        recipient($campaign, "x{$i}@autre{$i}.example");
    }

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect(plannedRows($campaign)->pluck('email')->all())->not->toContain('bloquee@agence.example')
        ->and($bloquee->fresh()->status)->toBe(RecipientStatus::Pending);
});

it('accepte plusieurs mails au même domaine le même jour quand le cooldown est à zéro', function () {
    $campaign = campaign();
    $campaign->update(['settings' => ['domain_cooldown_days' => 0]]);

    foreach (range(1, 6) as $i) {
        recipient($campaign, "a{$i}@agence.example");
    }

    seededPlanner()->tick($campaign->fresh(), paris('2026-10-19 07:00'));

    expect(plannedRows($campaign))->toHaveCount(4);
});

it('laisse passer un domaine une semaine après son dernier mail, ni avant ni après', function () {
    $campaign = campaign();

    foreach (range(1, 3) as $i) {
        recipient($campaign, "a{$i}@agence.example");
    }

    foreach (range(1, 40) as $i) {
        recipient($campaign, "x{$i}@autre{$i}.example");
    }

    $planner = seededPlanner();
    $envois = simulateTicks($planner, $campaign, paris('2026-10-19 07:00'), paris('2026-11-06 20:00'), 7);

    $jours = [];

    foreach ($envois as $envoi) {
        if ($envoi['recipient']->domain === 'agence.example') {
            $jours[$envoi['recipient']->email] = $envoi['at']->format('Y-m-d');
        }
    }

    // Le premier part le lundi 19, le suivant exactement sept jours après, puis encore sept jours.
    expect($jours)->toBe(['a1@agence.example' => '2026-10-19', 'a2@agence.example' => '2026-10-26', 'a3@agence.example' => '2026-11-02']);
});

it('retire du plan une adresse de la liste de suppression, sans lui prendre de créneau', function () {
    $campaign = planningCampaign();
    $premiere = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first();
    Suppression::create(['value' => $premiere->email, 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);
    Suppression::create(['value' => 'domaine2.example', 'type' => SuppressionType::Domain, 'reason' => SuppressionReason::Manual]);

    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));

    expect($premiere->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and(Recipient::where('campaign_id', $campaign->id)->where('email', 'p2@domaine2.example')->value('status'))->toBe(RecipientStatus::Suppressed)
        ->and(plannedRows($campaign))->toHaveCount(4)
        ->and(plannedRows($campaign)->pluck('email')->all())->not->toContain($premiere->email)
        ->and(SendLog::where('event', 'suppressed')->count())->toBe(2);
});

// --- Réglages modifiés -------------------------------------------------------------------------

it('annule le plan du jour quand on le demande, et en tire un nouveau au tick suivant', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));

    $campaign->update(['settings' => ['daily_max' => 2]]);
    $liberees = $planner->invalidate($campaign);

    expect($liberees)->toBe(4)
        ->and(plannedRows($campaign))->toHaveCount(0)
        ->and($campaign->fresh()->planned_for)->toBeNull();

    $planner->tick($campaign->fresh(), paris('2026-10-19 08:00'));

    expect(plannedRows($campaign))->toHaveCount(2);
});

it('laisse en place ce qui est déjà parti quand on annule le plan', function () {
    $campaign = planningCampaign();
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));
    markSent(plannedRows($campaign)->first(), paris('2026-10-19 10:00'));

    expect($planner->invalidate($campaign))->toBe(3)
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(1);
});

it('lit le fuseau de la campagne dans ses réglages, avec Paris pour défaut et repli', function () {
    $campaign = campaign();

    expect($campaign->timezone())->toBe('Europe/Paris');

    $campaign->update(['settings' => ['timezone' => 'Asia/Tokyo']]);
    expect($campaign->timezone())->toBe('Asia/Tokyo');

    $campaign->update(['settings' => ['timezone' => 'Mars/Olympus']]);
    expect($campaign->timezone())->toBe('Europe/Paris');
});
