<?php

use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Models\Suppression;
use App\Services\Planning\PlanProjector;
use App\Services\Planning\ProjectedDay;
use App\Services\Planning\Projection;

function projectionOf(Campaign $campaign, string $now, int $horizon = 400): Projection
{
    return app(PlanProjector::class)->project($campaign, paris($now), $horizon);
}

/** @return list<string> « 2026-10-19 ×4 » */
function dayCounts(Projection $projection): array
{
    return array_map(fn (ProjectedDay $day) => $day->date->format('Y-m-d').' ×'.count($day->entries), $projection->days);
}

it('prévoit les jours d\'envoi à venir, dans l\'ordre du fichier', function () {
    $campaign = planningCampaign(rows: 10);

    $projection = projectionOf($campaign, '2026-10-19 07:00');

    expect(dayCounts($projection))->toBe(['2026-10-19 ×4', '2026-10-20 ×4', '2026-10-21 ×2'])
        ->and($projection->estimatedEnd->format('Y-m-d'))->toBe('2026-10-21')
        ->and($projection->totalPlanned())->toBe(10)
        ->and($projection->stoppedBy)->toBeNull()
        ->and($projection->leftOver)->toBe(0);

    $positions = array_merge(...array_map(fn (ProjectedDay $day) => array_column($day->entries, 'position'), $projection->days));
    $attendues = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->pluck('position')->all();

    expect($positions)->toBe($attendues);
});

it('ne donne pas d\'heure aux jours prévus : elles sont tirées le jour même', function () {
    $projection = projectionOf(planningCampaign(rows: 10), '2026-10-19 07:00');

    foreach ($projection->days as $day) {
        expect($day->exact)->toBeFalse();

        foreach ($day->entries as $entry) {
            expect($entry['time'])->toBeNull();
        }
    }
});

it('saute les week-ends et les jours fériés', function () {
    // Le jeudi 14 mai 2026 est l'Ascension.
    $projection = projectionOf(planningCampaign(rows: 20), '2026-05-13 18:00');

    expect(dayCounts($projection))->toBe(['2026-05-15 ×4', '2026-05-18 ×4', '2026-05-19 ×4', '2026-05-20 ×4', '2026-05-21 ×4']);
});

it('montre les heures exactes du plan déjà tiré aujourd\'hui, puis prévoit la suite sans les redonner', function () {
    $campaign = planningCampaign(rows: 12);
    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));
    $attendues = plannedTimes($campaign);

    $projection = projectionOf($campaign->fresh(), '2026-10-19 07:05');
    $aujourdhui = $projection->days[0];

    expect($aujourdhui->exact)->toBeTrue()
        ->and(array_map(fn (array $e) => $e['time']->setTimezone('Europe/Paris')->format('H:i:s'), $aujourdhui->entries))->toBe($attendues)
        ->and(dayCounts($projection))->toBe(['2026-10-19 ×4', '2026-10-20 ×4', '2026-10-21 ×4']);

    $ids = array_merge(...array_map(fn (ProjectedDay $day) => array_column($day->entries, 'id'), $projection->days));

    expect($ids)->toBe(array_values(array_unique($ids)));
});

it('ne reprévoit pas aujourd\'hui quand le plan du jour est tiré et déjà parti', function () {
    $campaign = planningCampaign(rows: 12);
    $planner = seededPlanner();
    $planner->tick($campaign, paris('2026-10-19 07:00'));
    simulateTicks($planner, $campaign, paris('2026-10-19 07:01'), paris('2026-10-19 17:45'), 5);

    $projection = projectionOf($campaign->fresh(), '2026-10-19 18:00');

    expect(dayCounts($projection))->toBe(['2026-10-20 ×4', '2026-10-21 ×4']);
});

it('ne prévoit que ce qui reste à envoyer', function () {
    $campaign = planningCampaign(rows: 10);

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(6)->get() as $i => $row) {
        markSent($row, paris('2026-10-15 10:00')->addMinutes($i * 30));
    }

    expect(dayCounts(projectionOf($campaign, '2026-10-19 07:00')))->toBe(['2026-10-19 ×4']);
});

it('ne prévoit pour aujourd\'hui que ce qui reste du quota et de la plage', function () {
    $campaign = planningCampaign(rows: 10);
    $deja = Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(2)->get();
    markSent($deja[0], paris('2026-10-19 08:50'));
    markSent($deja[1], paris('2026-10-19 09:35'));

    expect(dayCounts(projectionOf($campaign, '2026-10-19 10:00'))[0])->toBe('2026-10-19 ×2');

    $tard = planningCampaign(rows: 10);

    // 16 h 40 à 17 h 30 : 49 minutes, deux mails espacés de 25 minutes.
    expect(dayCounts(projectionOf($tard, '2026-10-19 16:40'))[0])->toBe('2026-10-19 ×2')
        ->and(dayCounts(projectionOf($tard, '2026-10-19 21:00'))[0])->toBe('2026-10-20 ×4');
});

it('suit la montée progressive', function () {
    $campaign = planningCampaign(['ramp_up' => true, 'ramp_start' => 2, 'ramp_step' => 1, 'ramp_every_days' => 3], 40);

    $projection = projectionOf($campaign, '2026-10-19 07:00');
    $parJour = array_map(fn (ProjectedDay $day) => count($day->entries), $projection->days);

    expect(array_slice($parJour, 0, 9))->toBe([2, 2, 2, 3, 3, 3, 4, 4, 4]);
});

it('espace d\'une semaine les mails à un même domaine', function () {
    $campaign = campaign();

    foreach (range(1, 3) as $i) {
        recipient($campaign, "a{$i}@agence.example");
    }

    foreach (range(1, 30) as $i) {
        recipient($campaign, "x{$i}@autre{$i}.example");
    }

    $jours = [];

    foreach (projectionOf($campaign, '2026-10-19 07:00')->days as $day) {
        foreach ($day->entries as $entry) {
            if (str_starts_with($entry['email'], 'a')) {
                $jours[$entry['email']] = $day->date->format('Y-m-d');
            }
        }
    }

    expect($jours)->toBe(['a1@agence.example' => '2026-10-19', 'a2@agence.example' => '2026-10-26', 'a3@agence.example' => '2026-11-02']);
});

it('s\'arrête à la date de fin et dit combien de lignes ne seront pas contactées', function () {
    $campaign = planningCampaign(rows: 20, attributes: ['end_at' => paris('2026-10-21 12:00')->utc()]);

    $projection = projectionOf($campaign, '2026-10-19 07:00');

    expect(dayCounts($projection))->toBe(['2026-10-19 ×4', '2026-10-20 ×4', '2026-10-21 ×4'])
        ->and($projection->stoppedBy)->toBe('end_at')
        ->and($projection->leftOver)->toBe(8)
        ->and($projection->estimatedEnd)->toBeNull();
});

it('s\'arrête au maximum total, et c\'est la fin estimée', function () {
    $campaign = planningCampaign(['max_total' => 6], 20);

    $projection = projectionOf($campaign, '2026-10-19 07:00');

    expect(dayCounts($projection))->toBe(['2026-10-19 ×4', '2026-10-20 ×2'])
        ->and($projection->stoppedBy)->toBe('max_total')
        ->and($projection->estimatedEnd->format('Y-m-d'))->toBe('2026-10-20');
});

it('décompte du maximum total ce qui est déjà parti', function () {
    $campaign = planningCampaign(['max_total' => 6], 20);

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(5)->get() as $i => $row) {
        markSent($row, paris('2026-10-15 10:00')->addMinutes($i * 30));
    }

    expect(dayCounts(projectionOf($campaign, '2026-10-19 07:00')))->toBe(['2026-10-19 ×1']);
});

it('ne prévoit rien avec des réglages invalides, et dit pourquoi', function () {
    $projection = projectionOf(planningCampaign(['daily_max' => 0]), '2026-10-19 07:00');

    expect($projection->days)->toBe([])->and($projection->blocked)->toContain('entre 1 et 100');
});

it('ne prévoit rien pour une campagne sans destinataire', function () {
    $projection = projectionOf(campaign(), '2026-10-19 07:00');

    expect($projection->days)->toBe([])->and($projection->estimatedEnd)->toBeNull();
});

it('retire de la prévision les adresses de la liste de suppression', function () {
    $campaign = planningCampaign(rows: 6);
    Suppression::create(['value' => 'p1@domaine1.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);

    $projection = projectionOf($campaign, '2026-10-19 07:00');
    $emails = array_merge(...array_map(fn (ProjectedDay $day) => array_column($day->entries, 'email'), $projection->days));

    expect($emails)->toHaveCount(5)->not->toContain('p1@domaine1.example');
});

it('n\'estime pas la fin au-delà de l\'horizon', function () {
    $campaign = planningCampaign(rows: 60);

    $projection = projectionOf($campaign, '2026-10-19 07:00', horizon: 5);

    // Lundi 19 au samedi 24 : cinq jours d'envoi, vingt mails sur soixante.
    expect($projection->totalPlanned())->toBe(20)->and($projection->estimatedEnd)->toBeNull()->and($projection->stoppedBy)->toBeNull();
});

it('prévoit comme en attente un créneau resté d\'un jour passé', function () {
    $campaign = planningCampaign(rows: 6);
    $oubliee = planAt(Recipient::where('campaign_id', $campaign->id)->orderBy('position')->first(), '2026-10-19 15:00:00');

    $projection = projectionOf($campaign, '2026-10-20 07:00');

    expect($projection->days[0]->entries[0]['id'])->toBe($oubliee->id)->and($projection->days[0]->exact)->toBeFalse();
});

it('n\'écrit rien en base', function () {
    $campaign = planningCampaign(rows: 10);

    projectionOf($campaign, '2026-10-19 07:00');

    expect($campaign->fresh()->planned_for)->toBeNull()
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', '!=', RecipientStatus::Pending->value)->count())->toBe(0)
        ->and(SendLog::count())->toBe(0);
});

it('tient compte d\'une date de début lointaine', function () {
    $campaign = planningCampaign(rows: 6, attributes: ['start_at' => paris('2026-11-02 09:00')->utc()]);

    expect(dayCounts(projectionOf($campaign, '2026-10-19 07:00')))->toBe(['2026-11-02 ×4', '2026-11-03 ×2']);
});
