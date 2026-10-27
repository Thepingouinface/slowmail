<?php

namespace App\Services\Sending;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Support\Utc;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ce que l'utilisateur fait à la main sur une ligne : noter une réponse, trancher un envoi incertain.
 * Les écrans (phase 5) ne sont que des boutons posés dessus.
 */
final class RecipientActions
{
    public function __construct(private readonly Suppressions $suppressions) {}

    /** « répondu », « stop » ou « rendez-vous », avec des notes. `stop` ajoute l'adresse à la liste de suppression. */
    public function markReply(Recipient $recipient, ReplyStatus $status, ?string $notes = null): void
    {
        $recipient->reply_status = $status;

        if ($notes !== null) {
            $recipient->notes = $notes === '' ? null : $notes;
        }

        $recipient->save();

        Journal::record($recipient->campaign_id, 'reply', "Marqué : {$status->label()}.", $recipient->id);

        if ($status === ReplyStatus::Stop) {
            $this->suppressions->add($recipient->email, SuppressionReason::Stop);
        }
    }

    /** « C'est parti » : le mail incertain est bien dans vos envoyés. */
    public function confirmSent(Recipient $recipient): bool
    {
        $changed = Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Uncertain->value)
            ->update([
                'status' => RecipientStatus::Sent->value,
                'sent_at' => Utc::sql($recipient->sending_started_at ?? now()),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);

        if ($changed > 0) {
            Journal::record($recipient->campaign_id, 'resolved', 'Envoi incertain confirmé comme parti.', $recipient->id);
        }

        $recipient->refresh();

        return $changed > 0;
    }

    /** « Pas parti » : le mail incertain n'est pas dans vos envoyés, la ligne retourne en attente. */
    public function notSent(Recipient $recipient): bool
    {
        $changed = Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Uncertain->value)
            ->update([
                'status' => RecipientStatus::Pending->value,
                'planned_at' => null,
                'sending_started_at' => null,
                'message_id' => null,
                'rendered_subject' => null,
                'rendered_body' => null,
                'last_error' => null,
            ]);

        if ($changed > 0) {
            Journal::record($recipient->campaign_id, 'resolved', 'Envoi incertain confirmé comme non parti : remis en attente.', $recipient->id);
        }

        $recipient->refresh();

        return $changed > 0;
    }

    /**
     * Remet en attente les lignes simulées (`dry_run`) d'une campagne, pour qu'elles partent pour de vrai une fois
     * la simulation coupée. Rien n'était parti : le rendu, la date et le Message-ID de la simulation sont effacés.
     *
     * Refusé sur une campagne active (un envoi réel pourrait suivre dans la minute) ou terminée (elle n'enverrait plus rien).
     *
     * @return int nombre de lignes remises en attente
     *
     * @throws DomainException avec la raison du refus
     */
    public function releaseSimulated(Campaign $campaign): int
    {
        if ($campaign->status === CampaignStatus::Active) {
            throw new DomainException('Mettez d\'abord la campagne en pause : des lignes remises en attente pourraient partir tout de suite.');
        }

        if ($campaign->status === CampaignStatus::Finished) {
            throw new DomainException('La campagne est terminée : elle n\'enverrait plus rien.');
        }

        $released = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::DryRun->value)
            ->update([
                'status' => RecipientStatus::Pending->value,
                'planned_at' => null,
                'sending_started_at' => null,
                'sent_at' => null,
                'message_id' => null,
                'rendered_subject' => null,
                'rendered_body' => null,
                'last_error' => null,
                'attempts' => 0,
            ]);

        if ($released > 0) {
            Journal::record($campaign, 'simulation_reset', "{$released} ligne(s) simulée(s) remise(s) en attente.");
        }

        return $released;
    }
}
