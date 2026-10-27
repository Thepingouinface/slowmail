<?php

use App\Enums\DecisionKind;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Planning\DispatchHistory;
use App\Services\Planning\FrenchHolidays;
use App\Services\Planning\Planner;
use App\Services\Planning\PlanProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fait tourner le planificateur comme le ferait le scheduler : un tick par minute pendant la plage et ses
 * abords, quelques ticks le reste du temps. Chaque ligne décidée « part » : elle passe à `sent`, rien n'est envoyé.
 *
 * @param  ?Closure(CarbonImmutable): bool  $asleep  vrai : la machine dort à cet instant, aucun tick n'a lieu
 * @return array{sends: list<array{at: CarbonImmutable, recipient: Recipient}>, plans: array<string, list<string>>}
 */
function simulateDays(Planner $planner, Campaign $campaign, string $from, string $to, ?Closure $asleep = null): array
{
    $sends = [];
    $plans = [];

    for ($day = paris($from)->startOfDay(); $day <= paris($to)->startOfDay(); $day = $day->addDay()->startOfDay()) {
        $moments = [$day->setTime(7, 0)];

        if ($day->dayOfWeekIso <= 5) {
            for ($t = $day->setTime(9, 25); $t <= $day->setTime(17, 35); $t = $t->addMinute()) {
                $moments[] = $t;
            }
        } else {
            $moments[] = $day->setTime(12, 0);
        }

        $moments[] = $day->setTime(20, 0);

        foreach ($moments as $now) {
            if ($asleep !== null && $asleep($now)) {
                continue;
            }

            $decision = $planner->tick($campaign, $now);

            if ($now->format('H:i') === '07:00') {
                $plans[$day->format('Y-m-d')] = plannedTimes($campaign);
            }

            if ($decision->shouldSend()) {
                markSent($decision->recipient, $now);
                $sends[] = ['at' => $now, 'recipient' => $decision->recipient];
            } elseif ($decision->kind === DecisionKind::Finished) {
                break 2;
            }
        }
    }

    return ['sends' => $sends, 'plans' => $plans];
}

/** @return array<string, list<int>> identifiants envoyés, par jour local */
function sentByDay(array $sends): array
{
    $byDay = [];

    foreach ($sends as $send) {
        $byDay[$send['at']->setTimezone('Europe/Paris')->format('Y-m-d')][] = $send['recipient']->id;
    }

    return $byDay;
}

/** Les garanties du §14 de la spec, vérifiées sur une suite d'envois. */
function assertInvariants(array $sends, int $dailyMax = 4, int $gapMinutes = 25, int $cooldownDays = 7): void
{
    $holidays = new FrenchHolidays;
    $byDay = [];
    $lastDayByDomain = [];
    $previous = null;

    foreach ($sends as $send) {
        $at = $send['at']->setTimezone('Europe/Paris');
        $day = $at->format('Y-m-d');
        $byDay[$day] = ($byDay[$day] ?? 0) + 1;

        // Dans la plage horaire, un jour ouvré, hors jour férié.
        expect($at >= $at->setTime(9, 30) && $at <= $at->setTime(17, 30))->toBeTrue("{$at->format('Y-m-d H:i')} : hors plage")
            ->and($at->dayOfWeekIso)->toBeLessThanOrEqual(5)
            ->and($holidays->isHoliday($at))->toBeFalse("{$day} : jour férié");

        // Jamais deux mails à moins de l'écart minimum.
        if ($previous !== null) {
            expect($at->getTimestamp() - $previous->getTimestamp())->toBeGreaterThanOrEqual($gapMinutes * 60, "{$previous->format('H:i:s')} → {$at->format('H:i:s')} : écart trop court");
        }

        $previous = $at;

        // Jamais deux mails au même domaine à moins du délai de cooldown.
        $domain = $send['recipient']->domain;
        $number = DispatchHistory::dayNumber($at);

        if ($cooldownDays > 0 && isset($lastDayByDomain[$domain])) {
            expect($number - $lastDayByDomain[$domain])->toBeGreaterThanOrEqual($cooldownDays, "{$domain} contacté deux fois en moins de {$cooldownDays} jours");
        }

        $lastDayByDomain[$domain] = $number;
    }

    foreach ($byDay as $day => $count) {
        expect($count)->toBeLessThanOrEqual($dailyMax, "{$day} : {$count} mails");
    }

    // Une même ligne ne part jamais deux fois.
    $ids = array_map(fn ($send) => $send['recipient']->id, $sends);
    expect($ids)->toBe(array_values(array_unique($ids)));
}

function simulationCampaign(array $settings = [], int $rows = 80, int $domains = 25): Campaign
{
    $campaign = campaign('Simulation');
    $campaign->update(['settings' => $settings === [] ? null : $settings]);

    foreach (range(1, $rows) as $i) {
        recipient($campaign, "p{$i}@d".($i % $domains).'.example');
    }

    return $campaign->fresh();
}

it('simule trois semaines sans envoi réel : quota, plage, écart, jours, cooldown, et le plan annoncé se réalise', function () {
    Mail::fake();
    $campaign = simulationCampaign();

    // Du lundi 27 avril au dimanche 17 mai 2026 : les 1er et 8 mai (vendredis) et le 14 (Ascension) sont fériés.
    $this->travelTo(paris('2026-04-27 07:00'));
    expect(Artisan::call('slowmail:status', ['campagne' => 'Simulation', '--days' => 30]))->toBe(0);
    $affiche = Artisan::output();

    $annonce = app(PlanProjector::class)->project($campaign, paris('2026-04-27 07:00'));
    $run = simulateDays(seededPlanner(11), $campaign, '2026-04-27', '2026-05-17');
    $sends = $run['sends'];
    $parJour = sentByDay($sends);

    // Les douze jours d'envoi, jamais un jour férié ni un week-end, quatre mails chacun.
    expect(array_keys($parJour))->toBe([
        '2026-04-27', '2026-04-28', '2026-04-29', '2026-04-30',
        '2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07',
        '2026-05-11', '2026-05-12', '2026-05-13', '2026-05-15',
    ])->and($sends)->toHaveCount(48);

    foreach ($parJour as $ids) {
        expect($ids)->toHaveCount(4);
    }

    assertInvariants($sends);

    // Le plan annoncé par slowmail:status avant le début est celui qui s'est réalisé, jour après jour, ligne après ligne.
    foreach ($annonce->days as $jour) {
        $date = $jour->date->format('Y-m-d');

        if ($date <= '2026-05-17') {
            expect($parJour[$date] ?? [])->toBe(array_column($jour->entries, 'id'), "{$date} : le plan annoncé n'est pas celui qui est parti");
        }
    }

    // Et `slowmail:status` l'affichait, sans les jours fériés.
    expect($affiche)->toContain('lun. 27 avr.')->toContain('ven. 15 mai')->toContain('lun. 18 mai')
        ->not->toContain('jeu. 14 mai')->not->toContain('ven. 1 mai')->not->toContain('ven. 8 mai');

    // Les heures tirées chaque matin : quatre, jamais pile sur l'heure ronde, rien le week-end.
    foreach ($run['plans'] as $date => $heures) {
        $ouvre = in_array($date, array_keys($parJour), true);

        expect($heures)->toHaveCount($ouvre ? 4 : 0);

        foreach ($heures as $heure) {
            expect(str_ends_with($heure, ':00:00'))->toBeFalse("{$date} {$heure} : heure ronde");
        }
    }

    Mail::assertNothingSent();
})->group('simulation');

it('monte par paliers sur les jours d\'envoi réels, sans sauter de palier après une absence', function () {
    $campaign = simulationCampaign(['ramp_up' => true, 'ramp_start' => 2, 'ramp_step' => 1, 'ramp_every_days' => 3], rows: 60, domains: 60);
    $planner = seededPlanner(4);

    // Deux jours d'envoi, puis le Mac est éteint jusqu'au lundi 11 mai (douze jours de calendrier).
    $avant = simulateDays($planner, $campaign, '2026-04-27', '2026-04-28')['sends'];

    $annonce = app(PlanProjector::class)->project($campaign->fresh(), paris('2026-05-11 07:00'), 30);
    $apres = simulateDays($planner, $campaign, '2026-05-11', '2026-05-15')['sends'];

    $comptes = array_map('count', [...array_values(sentByDay($avant)), ...array_values(sentByDay($apres))]);

    // Jours d'envoi réels : 1, 2 (palier 2), 3 (encore 2), puis le palier suivant, sans saut dû à l'absence.
    expect($comptes)->toBe([2, 2, 2, 3, 3, 3]);

    // La prévision faite au réveil annonçait la même chose.
    $parJour = sentByDay($apres);

    foreach (array_slice($annonce->days, 0, 4) as $jour) {
        expect($parJour[$jour->date->format('Y-m-d')] ?? [])->toBe(array_column($jour->entries, 'id'));
    }

    assertInvariants([...$avant, ...$apres], dailyMax: 4);
})->group('simulation');

it('tient les garanties malgré des veilles aléatoires et un arrêt de trois jours', function () {
    $campaign = simulationCampaign();

    // Des trous aléatoires dans les ticks : machine endormie de 1 à 8 h presque chaque jour, éteinte du 22 au 24.
    $hasard = new Randomizer(new Mt19937(2026));
    $sommeils = [[paris('2026-10-22 00:00'), paris('2026-10-24 23:59')]];

    for ($jour = paris('2026-10-19'); $jour <= paris('2026-11-01'); $jour = $jour->addDay()) {
        if ($hasard->getInt(1, 10) <= 7) {
            $debut = $jour->setTime(7, 0)->addMinutes($hasard->getInt(0, 9 * 60));
            $sommeils[] = [$debut, $debut->addMinutes($hasard->getInt(60, 8 * 60))];
        }
    }

    $dort = function (CarbonImmutable $maintenant) use ($sommeils): bool {
        foreach ($sommeils as [$debut, $fin]) {
            if ($maintenant >= $debut && $maintenant <= $fin) {
                return true;
            }
        }

        return false;
    };

    $run = simulateDays(seededPlanner(5), $campaign, '2026-10-19', '2026-11-01', $dort);
    $sends = $run['sends'];

    expect($sends)->not->toBeEmpty()->and(count($sends))->toBeLessThanOrEqual(40);

    // Pas un jour au-delà de daily_max, jamais d'envoi en rafale, jamais hors plage, jamais de doublon.
    assertInvariants($sends);

    // Rien ne part pendant l'arrêt de trois jours, et la reprise se fait à daily_max.
    $parJour = sentByDay($sends);

    expect(array_keys($parJour))->not->toContain('2026-10-22')->not->toContain('2026-10-23');

    foreach ($parJour as $date => $ids) {
        expect(count($ids))->toBeLessThanOrEqual(4, $date);
    }
})->group('simulation');

it('ne revient jamais en arrière : les lignes sont tirées dans l\'ordre du fichier, sauf cooldown', function () {
    $campaign = simulationCampaign(rows: 40, domains: 40);

    $sends = simulateDays(seededPlanner(7), $campaign, '2026-10-19', '2026-10-30')['sends'];
    $positions = array_map(fn ($send) => $send['recipient']->position, $sends);

    // Un domaine par ligne : aucun cooldown ne s'applique, l'ordre du fichier est celui des envois.
    expect($positions)->toBe(collect($positions)->sort()->values()->all())->and($sends)->toHaveCount(40);
})->group('simulation');

it('termine la campagne quand il n\'y a plus rien à envoyer', function () {
    $campaign = simulationCampaign(rows: 10, domains: 10);
    $planner = seededPlanner(3);

    $run = simulateDays($planner, $campaign, '2026-10-19', '2026-10-30');

    expect($run['sends'])->toHaveCount(10)
        ->and($planner->tick($campaign, paris('2026-11-02 10:00'))->kind)->toBe(DecisionKind::Finished);
})->group('simulation');
