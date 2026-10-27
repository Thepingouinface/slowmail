<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCampaign;
use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Planning\CampaignSettings;
use App\Services\Planning\PlanProjector;
use App\Services\Planning\Projection;
use App\Services\Sending\SchedulerSnapshot;
use App\Services\Sending\SchedulerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    use FindsCampaign;

    protected $signature = 'slowmail:status
        {campagne : Identifiant ou nom exact de la campagne}
        {--days=14 : Nombre de jours de prévision affichés}';

    protected $description = 'Affiche l\'avancement, le plan d\'envoi et la fin estimée d\'une campagne';

    public function handle(PlanProjector $projector, SchedulerStatus $scheduler): int
    {
        $campaign = $this->findCampaign((string) $this->argument('campagne'));

        if ($campaign === null) {
            return self::FAILURE;
        }

        $settings = CampaignSettings::for($campaign);
        $now = CarbonImmutable::instance(now())->utc();

        $this->line("<options=bold>Campagne « {$campaign->name} »</> (n°{$campaign->id}) · {$campaign->status->label()}");
        $this->line($settings->summary());

        $this->banner($scheduler->snapshot($now), $campaign, $settings);
        $this->progress($campaign);

        $violations = $settings->violations();

        if ($violations !== []) {
            $this->newLine();

            foreach ($violations as $violation) {
                $this->components->error($violation);
            }

            return self::FAILURE;
        }

        $this->plan($projector->project($campaign, $now), $settings, max(1, (int) $this->option('days')));

        return self::SUCCESS;
    }

    /** Le bandeau d'état (addendum §6) : le scheduler tourne-t-il, que faut-il vérifier. */
    private function banner(SchedulerSnapshot $snapshot, Campaign $campaign, CampaignSettings $settings): void
    {
        $this->newLine();

        if ($snapshot->lastTickAt === null) {
            $this->line('<fg=red>Scheduler : jamais exécuté (aucun passage enregistré).</>');
        } else {
            $text = sprintf(
                'Scheduler : dernier passage il y a %d min (%s)',
                $snapshot->minutesSinceLastTick,
                $snapshot->lastTickAt->setTimezone($settings->timezone)->format('d/m H:i:s'),
            );

            $this->line($snapshot->stale ? "<fg=red>{$text} : en retard.</>" : $text);
        }

        if (! $snapshot->enabled) {
            $this->line('<fg=yellow>Envois désactivés (SLOWMAIL_ENABLED=false) : aucun mail ne part.</>');
        }

        if ($snapshot->uncertainCount > 0) {
            $this->line("<fg=yellow>{$snapshot->uncertainCount} ligne(s) incertaine(s) : à vérifier dans vos envoyés.</>");
        }

        if ($snapshot->nextSendAt !== null) {
            $this->line('Prochain envoi prévu : '.$snapshot->nextSendAt->setTimezone($settings->timezone)->locale('fr')->isoFormat('ddd D MMM [à] HH:mm:ss').'.');
        }

        if ($campaign->status === CampaignStatus::Paused && $campaign->pause_reason !== null) {
            $this->line("<fg=red>En pause : {$campaign->pause_reason}</>");
        }

        if ($campaign->consecutive_failures > 0) {
            $this->line("Échecs d'envoi consécutifs : {$campaign->consecutive_failures}.");
        }
    }

    private function progress(Campaign $campaign): void
    {
        $counts = $campaign->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $sent = (int) ($counts[RecipientStatus::Sent->value] ?? 0);

        $this->newLine();
        $this->line(sprintf('Avancement : %d envoyé(s) sur %d%s', $sent, $total, $total > 0 ? sprintf(' (%d %%)', (int) round($sent / $total * 100)) : ''));

        $details = [];

        foreach (RecipientStatus::cases() as $status) {
            if (($counts[$status->value] ?? 0) > 0) {
                $details[] = "{$status->label()} : {$counts[$status->value]}";
            }
        }

        if ($details !== []) {
            $this->line('  '.implode(' · ', $details));
        }
    }

    private function plan(Projection $projection, CampaignSettings $settings, int $daysShown): void
    {
        $this->newLine();

        if ($projection->days === []) {
            $this->line('Aucun envoi à prévoir.');

            return;
        }

        $this->line('<options=bold>Plan d\'envoi</> (les heures des jours à venir sont tirées le jour même)');

        $rows = [];

        foreach (array_slice($projection->days, 0, $daysShown) as $day) {
            foreach ($day->entries as $index => $entry) {
                $rows[] = [
                    $index === 0 ? $day->date->locale('fr')->isoFormat('ddd D MMM') : '',
                    $entry['time']?->setTimezone($settings->timezone)->format('H:i:s') ?? ($day->exact ? '' : '—'),
                    "n°{$entry['position']}",
                    $entry['email'],
                ];
            }
        }

        $this->table(['Jour', 'Heure', 'Ligne', 'Adresse'], $rows);

        if (count($projection->days) > $daysShown) {
            $this->line(sprintf('… et %d autre(s) jour(s) d\'envoi (--days=%d pour en voir plus).', count($projection->days) - $daysShown, count($projection->days)));
        }

        $this->newLine();
        $this->end($projection);
    }

    private function end(Projection $projection): void
    {
        if ($projection->estimatedEnd !== null) {
            $this->line(sprintf(
                '<options=bold>Fin estimée :</> %s, au rythme actuel (%d jour(s) d\'envoi, %d mail(s) à envoyer).',
                $projection->estimatedEnd->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                count($projection->days),
                $projection->totalPlanned(),
            ));

            return;
        }

        if ($projection->stoppedBy === 'end_at') {
            $this->line(sprintf('<options=bold>Fin :</> la date de fin arrêtera la campagne : %d ligne(s) ne seront pas contactées.', $projection->leftOver));

            return;
        }

        $this->line('<options=bold>Fin estimée :</> au-delà de l\'horizon de prévision.');
    }
}
