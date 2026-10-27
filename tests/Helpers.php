<?php

use App\Enums\DecisionKind;
use App\Enums\RecipientStatus;
use App\Http\Middleware\RequireSlowmailPassword;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Planning\Planner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Écrit un CSV dans un fichier temporaire, supprimé à la fin du test. */
function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'slowmail-test-');
    file_put_contents($path, $content);

    $GLOBALS['slowmail_test_files'][] = $path;

    return $path;
}

function cleanUpCsvFiles(): void
{
    foreach ($GLOBALS['slowmail_test_files'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['slowmail_test_files'] = [];
}

function campaign(string $name = 'Prospection test'): Campaign
{
    return Campaign::create(['name' => $name]);
}

function recipient(Campaign $campaign, string $email, RecipientStatus $status = RecipientStatus::Pending, array $data = []): Recipient
{
    static $position = 0;

    return Recipient::create([
        'campaign_id' => $campaign->id,
        'position' => ++$position,
        'email' => $email,
        'domain' => substr($email, strrpos($email, '@') + 1),
        'status' => $status,
        'data' => $data,
    ]);
}

function authenticated(): array
{
    return [RequireSlowmailPassword::SESSION_KEY => true];
}

// --- Planification ---------------------------------------------------------------------------

/** Un instant, à l'heure de Paris sauf indication contraire. */
function paris(string $time, string $timezone = 'Europe/Paris'): CarbonImmutable
{
    return CarbonImmutable::parse($time, $timezone);
}

/**
 * Une campagne avec $rows destinataires, chacun à son propre domaine.
 *
 * @param  array<string, mixed>  $settings  options de planification (clés de campaigns.settings)
 * @param  array<string, mixed>  $attributes  start_at, end_at…
 */
function planningCampaign(array $settings = [], int $rows = 20, array $attributes = []): Campaign
{
    $campaign = campaign('Plan');
    $campaign->update(['settings' => $settings === [] ? null : $settings] + $attributes);

    foreach (range(1, $rows) as $i) {
        recipient($campaign, "p{$i}@domaine{$i}.example");
    }

    return $campaign->fresh();
}

/** Un planificateur dont le hasard est reproductible. */
function seededPlanner(int $seed = 1): Planner
{
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937($seed)));

    return app()->make(Planner::class);
}

/** Pose un créneau à une ligne, comme le fait le tirage du jour. */
function planAt(Recipient $recipient, string $time): Recipient
{
    $recipient->forceFill(['status' => RecipientStatus::Planned, 'planned_at' => paris($time)->utc()])->save();

    return $recipient;
}

/** Marque le plan d'un jour comme déjà tiré. */
function markDrawn(Campaign $campaign, string $date): void
{
    Campaign::query()->whereKey($campaign->id)->update(['planned_for' => $date]);
    $campaign->refresh();
}

function markSent(Recipient $recipient, CarbonImmutable $at): void
{
    $recipient->forceFill(['status' => RecipientStatus::Sent, 'sent_at' => $at->utc()])->save();
}

/**
 * Appelle le planificateur toutes les $stepMinutes minutes et « envoie » chaque ligne décidée,
 * comme le fera le tick de la phase 4 : la ligne passe à `sent`, rien ne part.
 *
 * @return list<array{at: CarbonImmutable, recipient: Recipient}>
 */
function simulateTicks(Planner $planner, Campaign $campaign, CarbonImmutable $from, CarbonImmutable $to, int $stepMinutes = 1): array
{
    $sends = [];

    for ($now = $from; $now <= $to; $now = $now->addMinutes($stepMinutes)) {
        $decision = $planner->tick($campaign, $now);

        if ($decision->shouldSend()) {
            markSent($decision->recipient, $now);
            $sends[] = ['at' => $now, 'recipient' => $decision->recipient];
        } elseif ($decision->kind === DecisionKind::Finished) {
            break;
        }
    }

    return $sends;
}

/**
 * Les heures locales (H:i:s) des lignes planifiées, dans l'ordre.
 *
 * @return list<string>
 */
function plannedTimes(Campaign $campaign, string $timezone = 'Europe/Paris'): array
{
    return Recipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('status', RecipientStatus::Planned->value)
        ->orderBy('planned_at')
        ->get()
        ->map(fn (Recipient $row) => $row->planned_at->copy()->setTimezone($timezone)->format('H:i:s'))
        ->all();
}

/** @return Collection<int, Recipient> */
function plannedRows(Campaign $campaign)
{
    return Recipient::query()
        ->where('campaign_id', $campaign->id)
        ->where('status', RecipientStatus::Planned->value)
        ->orderBy('planned_at')
        ->get();
}
