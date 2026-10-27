<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Planning\CampaignSettings;
use App\Services\Planning\Planner;
use App\Services\Planning\PlanProjector;
use App\Services\Sending\Journal;
use App\Services\Sending\RecipientActions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * L'onglet Réglages : un formulaire sur CampaignSettings. Les règles (bornes, plage, capacité) sont celles de
 * `violations()`, affichées telles quelles ; ici, seule la forme des champs est vérifiée.
 *
 * Mode simulation (`dry_run`) : il ne change pas tant que la campagne est active (un simple enregistrement ne doit
 * jamais lancer de vrais envois : on la met en pause d'abord, puis « Reprendre »). Les lignes déjà simulées restent
 * `dry_run` (elles ne partiront pas) ; « Remettre en attente » les rend envoyables, explicitement.
 */
class CampaignSettingsController extends Controller
{
    private const RULES = [
        'daily_max' => ['required', 'integer'],
        'window_start' => ['required', 'string', 'max:5'],
        'window_end' => ['required', 'string', 'max:5'],
        'timezone' => ['required', 'string', 'max:64'],
        'days' => ['nullable', 'array'],
        'days.*' => ['integer'],
        'min_gap_minutes' => ['required', 'integer'],
        'start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        'end_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        'max_total' => ['nullable', 'integer'],
        'ramp_start' => ['nullable', 'integer'],
        'ramp_step' => ['nullable', 'integer'],
        'ramp_every_days' => ['nullable', 'integer'],
        'domain_cooldown_days' => ['required', 'integer'],
    ];

    public function edit(Campaign $campaign, PlanProjector $projector): View
    {
        $settings = CampaignSettings::for($campaign);

        return view('campaigns.settings', [
            'campaign' => $campaign,
            'settings' => $settings,
            'timezone' => $campaign->timezone(),
            'projection' => $projector->project($campaign),
            'simulated' => $campaign->recipients()->where('status', RecipientStatus::DryRun)->count(),
            'locked' => $campaign->status === CampaignStatus::Finished,
        ]);
    }

    public function update(Request $request, Campaign $campaign, Planner $planner): RedirectResponse
    {
        $back = redirect()->route('campaigns.settings', $campaign);

        if ($campaign->status === CampaignStatus::Finished) {
            return $back->withErrors(['settings' => ['La campagne est terminée : ses réglages ne changent plus.']]);
        }

        $data = $request->validate(self::RULES);
        $current = CampaignSettings::for($campaign);

        // Les dates saisies sont à l'heure de la campagne, dans le fuseau du formulaire s'il est valable.
        $timezone = CampaignSettings::isValidTimezone(trim($data['timezone'])) ? trim($data['timezone']) : $campaign->timezone();
        $startAt = $this->parseLocal($data['start_at'] ?? null, $timezone);
        $endAt = $this->parseLocal($data['end_at'] ?? null, $timezone);

        $settings = CampaignSettings::fromArray([
            'daily_max' => $data['daily_max'],
            'window_start' => $data['window_start'],
            'window_end' => $data['window_end'],
            'timezone' => $data['timezone'],
            'days' => $data['days'] ?? [],
            'skip_holidays' => $request->boolean('skip_holidays'),
            'min_gap_minutes' => $data['min_gap_minutes'],
            'jitter' => $request->boolean('jitter'),
            'max_total' => $data['max_total'] ?? null,
            'ramp_up' => $request->boolean('ramp_up'),
            'ramp_start' => $data['ramp_start'] ?? null,
            'ramp_step' => $data['ramp_step'] ?? null,
            'ramp_every_days' => $data['ramp_every_days'] ?? null,
            'domain_cooldown_days' => $data['domain_cooldown_days'],
            'dry_run' => $request->boolean('dry_run'),
        ], $startAt, $endAt);

        $problems = $settings->violations();

        if ($settings->dryRun !== $current->dryRun && $campaign->status === CampaignStatus::Active) {
            $problems[] = 'Pour changer le mode simulation, mettez d\'abord la campagne en pause : un enregistrement ne doit pas lancer de vrais envois (ou en arrêter).';
        }

        if ($problems !== []) {
            return $back->withInput()->withErrors(['settings' => $problems]);
        }

        if ($settings->toArray() === $current->toArray() && $this->same($startAt, $campaign->start_at) && $this->same($endAt, $campaign->end_at)) {
            return $back->with('status', 'Aucun changement : les réglages étaient déjà ceux-là.');
        }

        // start_at et end_at sont en UTC : Eloquent écrit un Carbon avec son propre fuseau, sans le convertir.
        $campaign->update(['settings' => $settings->toArray(), 'start_at' => $startAt, 'end_at' => $endAt]);

        // Le plan du jour a été tiré avec les anciens réglages : les créneaux non envoyés sont remis en attente,
        // le prochain passage en tire un nouveau.
        $released = in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true) ? $planner->invalidate($campaign) : 0;

        Journal::record($campaign, 'settings', 'Réglages modifiés : '.$settings->summary().$this->modeNote($current, $settings));

        $message = 'Réglages enregistrés.';

        if ($released > 0) {
            $message .= " {$released} créneau(x) du jour remis en attente : le plan sera retiré au prochain passage.";
        }

        return $back->with('status', $message);
    }

    /** Rend envoyables les lignes d'une simulation, une fois celle-ci coupée. */
    public function releaseSimulated(Campaign $campaign, RecipientActions $actions): RedirectResponse
    {
        $back = redirect()->route('campaigns.settings', $campaign);

        try {
            $released = $actions->releaseSimulated($campaign);
        } catch (DomainException $e) {
            return $back->withErrors(['settings' => [$e->getMessage()]]);
        }

        return $back->with('status', $released === 0
            ? 'Aucune ligne simulée à remettre en attente.'
            : "{$released} ligne(s) simulée(s) remise(s) en attente.");
    }

    private function parseLocal(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $parsed === false ? null : $parsed->utc();
    }

    private function same(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->getTimestamp() === $b->getTimestamp();
    }

    private function modeNote(CampaignSettings $before, CampaignSettings $after): string
    {
        return match (true) {
            ! $before->dryRun && $after->dryRun => ' Mode simulation activé : aucun mail ne partira.',
            $before->dryRun && ! $after->dryRun => ' Mode simulation coupé : les mails partiront pour de vrai.',
            default => '',
        };
    }
}
