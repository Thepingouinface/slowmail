<?php

namespace App\Services\Sending;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\Planning\CampaignSettings;
use App\Services\Template\TemplateValidator;
use App\Support\Utc;
use Illuminate\Support\Str;

/**
 * Activer, mettre en pause, reprendre, terminer. Les écrans (phase 5) et le disjoncteur passent par ici.
 *
 * Activer ou reprendre est refusé, avec les raisons en clair, tant que la campagne ne peut pas partir :
 * modèle invalide, mention de désinscription absente, réglages invalides, aucun destinataire, pas d'adresse d'expédition.
 */
final class CampaignLifecycle
{
    public function __construct(private readonly TemplateValidator $validator) {}

    /**
     * Ce qui empêche la campagne de partir. Vide : elle peut.
     *
     * @return list<string>
     */
    public function problems(Campaign $campaign): array
    {
        $problems = CampaignSettings::for($campaign)->violations();

        $validation = $this->validator->validate($campaign);
        array_push($problems, ...$validation->errors, ...$validation->blockers);

        $hasRecipients = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Planned->value])
            ->exists();

        if (! $hasRecipients) {
            $problems[] = 'Aucun destinataire à contacter : importez un CSV.';
        }

        if (filter_var((string) config('mail.from.address'), FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'MAIL_FROM_ADDRESS est vide ou invalide dans la configuration : pas d\'adresse d\'expédition.';
        }

        return $problems;
    }

    /**
     * Active une campagne en brouillon.
     *
     * @return list<string> les raisons du refus ; vide : la campagne est active
     */
    public function activate(Campaign $campaign): array
    {
        $reason = match ($campaign->status) {
            CampaignStatus::Draft => null,
            CampaignStatus::Active => 'La campagne est déjà active.',
            CampaignStatus::Paused => 'La campagne est en pause : utilisez « Reprendre ».',
            CampaignStatus::Finished => 'La campagne est terminée.',
        };

        if ($reason !== null) {
            return [$reason];
        }

        if (($problems = $this->problems($campaign)) !== []) {
            return $problems;
        }

        $this->start($campaign, 'activated', 'Campagne activée.');

        return [];
    }

    /**
     * Reprend une campagne en pause (à la main, ou après le disjoncteur). Remet le disjoncteur à zéro.
     *
     * @return list<string>
     */
    public function resume(Campaign $campaign): array
    {
        if ($campaign->status !== CampaignStatus::Paused) {
            return ['Seule une campagne en pause peut être reprise.'];
        }

        if (($problems = $this->problems($campaign)) !== []) {
            return $problems;
        }

        $this->start($campaign, 'resumed', 'Campagne reprise.');

        return [];
    }

    /**
     * Met une campagne active en pause. $event distingue la pause voulue (`paused`) du disjoncteur.
     *
     * @return bool false : elle n'était pas active
     */
    public function pause(Campaign $campaign, ?string $reason = null, string $event = 'paused'): bool
    {
        $changed = Campaign::query()
            ->whereKey($campaign->id)
            ->where('status', CampaignStatus::Active->value)
            ->update(['status' => CampaignStatus::Paused->value, 'pause_reason' => $reason]);

        if ($changed > 0) {
            Journal::record($campaign, $event, $reason ?? 'Campagne mise en pause.');
        }

        $campaign->refresh();

        return $changed > 0;
    }

    /** La campagne n'a plus rien à envoyer. */
    public function finish(Campaign $campaign, string $reason): bool
    {
        $changed = Campaign::query()
            ->whereKey($campaign->id)
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Paused->value])
            ->update(['status' => CampaignStatus::Finished->value, 'finished_at' => Utc::sql(now()), 'pause_reason' => null]);

        if ($changed > 0) {
            Journal::record($campaign, 'finished', Str::finish($reason, '.'));
        }

        $campaign->refresh();

        return $changed > 0;
    }

    private function start(Campaign $campaign, string $event, string $detail): void
    {
        Campaign::query()->whereKey($campaign->id)->update([
            'status' => CampaignStatus::Active->value,
            'consecutive_failures' => 0,
            'pause_reason' => null,
            'finished_at' => null,
            // Le premier tick qui suit tire le plan du jour.
            'planned_for' => null,
        ]);

        Journal::record($campaign, $event, $detail);

        $campaign->refresh();
    }
}
