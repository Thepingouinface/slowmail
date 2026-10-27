<?php

namespace App\Services\Sending;

use App\Enums\DeliveryOutcome;
use App\Enums\RecipientStatus;
use App\Enums\SendFailureKind;
use App\Enums\SuppressionReason;
use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Planning\CampaignSettings;
use App\Services\Template\ComposedMessage;
use App\Services\Template\MessageComposer;
use App\Services\Template\TemplateValidator;
use App\Support\Utc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie une ligne que le planificateur déclare due, ou décide qu'il ne le faut pas.
 *
 * Tout ce qui peut interdire l'envoi est revérifié ici, juste avant : kill switch, liste de suppression,
 * modèle, variables vides. Puis la ligne est réclamée (`planned` → `sending`, avec le Message-ID et
 * l'heure) par une écriture validée **avant** l'appel SMTP : si la machine s'arrête pendant l'envoi, la
 * ligne reste `sending`, devient `uncertain`, et n'est jamais renvoyée toute seule.
 */
final class DeliveryService
{
    public function __construct(
        private readonly MessageComposer $composer,
        private readonly TemplateValidator $validator,
        private readonly Suppressions $suppressions,
        private readonly SendFailureClassifier $classifier,
        private readonly CampaignLifecycle $lifecycle,
    ) {}

    public function deliver(Campaign $campaign, Recipient $recipient, CarbonImmutable $now): Delivery
    {
        if (! config('slowmail.enabled')) {
            return Delivery::of(DeliveryOutcome::Disabled);
        }

        if (($why = $this->suppressions->matches($recipient->email)) !== null) {
            return $this->suppress($campaign, $recipient, $why);
        }

        $validation = $this->validator->validate($campaign);

        if (! $validation->canActivate()) {
            return Delivery::of(DeliveryOutcome::Halted, 'Le modèle ne respecte plus les règles d\'envoi : '.implode(' ', [...$validation->errors, ...$validation->blockers]));
        }

        $message = $this->composer->compose($campaign, $recipient, $now);

        if ($message->problems !== []) {
            return Delivery::of(DeliveryOutcome::Halted, implode(' ', $message->problems));
        }

        if ($message->missing !== []) {
            return $this->skip($campaign, $recipient, $message);
        }

        if (CampaignSettings::for($campaign)->dryRun) {
            return $this->dryRun($campaign, $recipient, $message, $now);
        }

        $from = (string) config('mail.from.address');

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return Delivery::of(DeliveryOutcome::Halted, 'MAIL_FROM_ADDRESS est vide ou invalide dans la configuration : pas d\'adresse d\'expédition.');
        }

        $messageId = $this->messageId($from);

        if (! $this->claim($recipient, $message, $messageId, $now)) {
            return Delivery::of(DeliveryOutcome::Lost);
        }

        try {
            Mail::to($recipient->email)->send(new ProspectMail($message->subject, $message->body, $messageId));
        } catch (Throwable $e) {
            // Pas de report() : l'exception peut contenir un identifiant ou une adresse, et l'erreur utile va au journal.
            return $this->fail($campaign, $recipient, $this->classifier->classify($e), $now);
        }

        return $this->succeed($campaign, $recipient, $now);
    }

    /**
     * Réclame la ligne avant l'envoi : `planned` → `sending`, avec le Message-ID, l'heure et le rendu.
     * Une seule écriture, validée tout de suite, et conditionnelle : si une autre exécution l'a déjà
     * réclamée, rien ne change et personne n'envoie en double.
     */
    public function claim(Recipient $recipient, ComposedMessage $message, string $messageId, CarbonImmutable $now): bool
    {
        return DB::transaction(fn () => Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Planned->value)
            ->update([
                'status' => RecipientStatus::Sending->value,
                'sending_started_at' => Utc::sql($now),
                'message_id' => $messageId,
                'rendered_subject' => $message->subject,
                'rendered_body' => $message->body,
                'last_error' => null,
            ])) === 1;
    }

    private function succeed(Campaign $campaign, Recipient $recipient, CarbonImmutable $now): Delivery
    {
        Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Sending->value)
            ->update([
                'status' => RecipientStatus::Sent->value,
                'sent_at' => Utc::sql($now),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);

        Campaign::query()->whereKey($campaign->id)->update(['consecutive_failures' => 0]);
        Journal::record($campaign, 'sent', 'Mail envoyé.', $recipient->id);

        return Delivery::of(DeliveryOutcome::Sent);
    }

    private function fail(Campaign $campaign, Recipient $recipient, SendFailure $failure, CarbonImmutable $now): Delivery
    {
        return match ($failure->kind) {
            SendFailureKind::Network => $this->networkUnavailable($campaign, $recipient, $failure),
            SendFailureKind::Uncertain => $this->uncertain($campaign, $recipient, $failure),
            SendFailureKind::RecipientRejected => $this->bounce($campaign, $recipient, $failure),
            SendFailureKind::InvalidAddress => $this->invalidAddress($campaign, $recipient, $failure),
            SendFailureKind::Temporary, SendFailureKind::Permanent => $this->serverFailure($campaign, $recipient, $failure, $now),
        };
    }

    /**
     * Pas de réseau, rien n'est parti : la ligne redevient `planned`, sans consommer de tentative ni toucher au
     * disjoncteur. Le tick suivant la retente, dans la limite de la tolérance (sinon le planificateur la replanifie).
     */
    private function networkUnavailable(Campaign $campaign, Recipient $recipient, SendFailure $failure): Delivery
    {
        $this->back($recipient, RecipientStatus::Planned, "Réseau indisponible : {$failure->message}");

        // Une panne de plusieurs heures ne doit pas noyer le journal : une ligne tant qu'elle dure.
        if (SendLog::query()->where('campaign_id', $campaign->id)->orderByDesc('id')->value('event') !== 'network') {
            Journal::record($campaign, 'network', "Réseau indisponible, nouvelle tentative au prochain passage : {$failure->message}", $recipient->id);
        }

        return Delivery::of(DeliveryOutcome::NetworkUnavailable, $failure->message);
    }

    /** Le message a peut-être été remis : on ne le renvoie jamais seul, c'est à l'utilisateur de trancher. */
    private function uncertain(Campaign $campaign, Recipient $recipient, SendFailure $failure): Delivery
    {
        Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Sending->value)
            ->update([
                'status' => RecipientStatus::Uncertain->value,
                'last_error' => "Envoi interrompu sans réponse du serveur : à vérifier dans vos envoyés. ({$failure->message})",
            ]);

        Journal::record($campaign, 'uncertain', 'Envoi interrompu sans réponse du serveur : à vérifier dans vos envoyés.', $recipient->id);

        return Delivery::of(DeliveryOutcome::Uncertain, $failure->message);
    }

    /** Adresse refusée par le serveur : rebond, et plus jamais de mail à cette adresse. Hors disjoncteur. */
    private function bounce(Campaign $campaign, Recipient $recipient, SendFailure $failure): Delivery
    {
        $this->finalize($recipient, RecipientStatus::Bounced, $failure->message);
        $this->suppressions->add($recipient->email, SuppressionReason::Bounce);
        Journal::record($campaign, 'bounced', "Adresse refusée par le serveur : {$failure->message}", $recipient->id);

        return Delivery::of(DeliveryOutcome::Bounced, $failure->message);
    }

    private function invalidAddress(Campaign $campaign, Recipient $recipient, SendFailure $failure): Delivery
    {
        $this->finalize($recipient, RecipientStatus::Failed, "Adresse invalide : {$failure->message}");
        Journal::record($campaign, 'failed', "Adresse invalide : {$failure->message}", $recipient->id);

        return Delivery::of(DeliveryOutcome::Failed, $failure->message);
    }

    /**
     * Le serveur a répondu par une erreur. Temporaire (4xx) : deux nouvelles tentatives à une heure d'intervalle.
     * Définitive (5xx : authentification, politique…) : la ligne échoue. Dans les deux cas, cela compte pour le disjoncteur.
     */
    private function serverFailure(Campaign $campaign, Recipient $recipient, SendFailure $failure, CarbonImmutable $now): Delivery
    {
        Recipient::query()->whereKey($recipient->id)->update(['attempts' => DB::raw('attempts + 1')]);
        $attempts = (int) Recipient::query()->whereKey($recipient->id)->value('attempts');
        $max = (int) config('slowmail.sending.max_attempts');

        $retry = $failure->kind === SendFailureKind::Temporary && $attempts < $max;

        if ($retry) {
            $at = $now->addMinutes((int) config('slowmail.sending.retry_minutes'));

            $this->back($recipient, RecipientStatus::Planned, "Tentative {$attempts}/{$max} : {$failure->message}", $at);
            Journal::record($campaign, 'retry', sprintf(
                'Le serveur a répondu « plus tard » (tentative %d/%d) : nouvelle tentative vers %s. %s',
                $attempts,
                $max,
                $at->setTimezone($campaign->timezone())->format('H:i'),
                $failure->message,
            ), $recipient->id);
        } else {
            $this->finalize($recipient, RecipientStatus::Failed, $failure->message, countAttempt: false);
            Journal::record($campaign, 'failed', "Échec d'envoi : {$failure->message}", $recipient->id);
        }

        $paused = $this->countFailure($campaign, $failure->message);

        return Delivery::of($retry ? DeliveryOutcome::Retry : DeliveryOutcome::Failed, $failure->message, $paused);
    }

    /** Un échec de plus ; au seuil, la campagne se met en pause (disjoncteur). */
    private function countFailure(Campaign $campaign, string $lastError): bool
    {
        Campaign::query()->whereKey($campaign->id)->update(['consecutive_failures' => DB::raw('consecutive_failures + 1')]);
        $count = (int) Campaign::query()->whereKey($campaign->id)->value('consecutive_failures');

        if ($count < (int) config('slowmail.sending.breaker_threshold')) {
            return false;
        }

        return $this->lifecycle->pause(
            $campaign,
            "Disjoncteur : {$count} échecs d'envoi consécutifs. Dernière erreur : {$lastError}",
            'circuit_breaker',
        );
    }

    private function suppress(Campaign $campaign, Recipient $recipient, SuppressionReason $why): Delivery
    {
        $changed = Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Planned->value)
            ->update(['status' => RecipientStatus::Suppressed->value, 'planned_at' => null]);

        if ($changed > 0) {
            Journal::record($campaign, 'suppressed', "Adresse dans la liste de suppression ({$why->label()}) : retirée au moment de l'envoi.", $recipient->id);
        }

        return Delivery::of(DeliveryOutcome::Suppressed, $why->label());
    }

    /** Variable vide sans valeur par défaut : la ligne ne part pas. Elle ne compte pas dans le quota. */
    private function skip(Campaign $campaign, Recipient $recipient, ComposedMessage $message): Delivery
    {
        $names = implode(', ', array_map(fn (string $name) => "[{$name}]", $message->missing));
        $reason = "Valeur vide sans valeur par défaut : {$names}.";

        $changed = Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Planned->value)
            ->update(['status' => RecipientStatus::Skipped->value, 'planned_at' => null, 'last_error' => $reason]);

        if ($changed > 0) {
            Journal::record($campaign, 'skipped', $reason, $recipient->id);
        }

        return Delivery::of(DeliveryOutcome::Skipped, $reason);
    }

    /** Tout fonctionne, rien ne part : le rendu est gardé, la ligne compte comme un envoi pour la planification. */
    private function dryRun(Campaign $campaign, Recipient $recipient, ComposedMessage $message, CarbonImmutable $now): Delivery
    {
        $changed = Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Planned->value)
            ->update([
                'status' => RecipientStatus::DryRun->value,
                'sent_at' => Utc::sql($now),
                'rendered_subject' => $message->subject,
                'rendered_body' => $message->body,
                'last_error' => null,
            ]);

        if ($changed === 0) {
            return Delivery::of(DeliveryOutcome::Lost);
        }

        Journal::record($campaign, 'dry_run', 'Simulation : rendu gardé, aucun mail envoyé.', $recipient->id);

        return Delivery::of(DeliveryOutcome::DryRun);
    }

    /** Remet la ligne dans un état où elle n'a pas été envoyée (à retenter, ou à replanifier). */
    private function back(Recipient $recipient, RecipientStatus $status, string $lastError, ?CarbonImmutable $plannedAt = null): void
    {
        $values = [
            'status' => $status->value,
            'sending_started_at' => null,
            'message_id' => null,
            'rendered_subject' => null,
            'rendered_body' => null,
            'last_error' => $lastError,
        ];

        if ($plannedAt !== null) {
            $values['planned_at'] = Utc::sql($plannedAt);
        }

        Recipient::query()->whereKey($recipient->id)->where('status', RecipientStatus::Sending->value)->update($values);
    }

    /**
     * La ligne a fini son parcours sans être envoyée : plus de créneau, la raison est gardée.
     *
     * @param  bool  $countAttempt  false quand l'appelant a déjà compté la tentative
     */
    private function finalize(Recipient $recipient, RecipientStatus $status, string $lastError, bool $countAttempt = true): void
    {
        $values = ['status' => $status->value, 'planned_at' => null, 'last_error' => $lastError];

        if ($countAttempt) {
            $values['attempts'] = DB::raw('attempts + 1');
        }

        Recipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Sending->value)
            ->update($values);
    }

    /** Identifiant du message, sans chevrons : stocké avant l'envoi pour retrouver le mail dans les envoyés. */
    private function messageId(string $from): string
    {
        return bin2hex(random_bytes(16)).'@'.substr((string) strrchr($from, '@'), 1);
    }
}
