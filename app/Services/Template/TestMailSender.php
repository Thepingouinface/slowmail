<?php

namespace App\Services\Template;

use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Support\SecretScrubber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * « M'envoyer un test » : un vrai mail, à l'adresse d'expédition, avec les données d'une ligne.
 * La ligne n'est pas modifiée : ni statut, ni tentative, ni rendu stocké.
 */
final class TestMailSender
{
    public function __construct(
        private readonly TemplateValidator $validator,
        private readonly MessageComposer $composer,
    ) {}

    /** Pourquoi le test ne peut pas partir pour cette ligne, ou null s'il le peut. */
    public function problem(Campaign $campaign, Recipient $recipient): ?string
    {
        try {
            $this->prepare($campaign, $recipient);
        } catch (TestMailException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @return string l'adresse à laquelle le test est parti
     *
     * @throws TestMailException
     */
    public function send(Campaign $campaign, Recipient $recipient): string
    {
        [$to, $message] = $this->prepare($campaign, $recipient);

        try {
            Mail::to($to)->send(new ProspectMail($message->subject, $message->body));
        } catch (Throwable $e) {
            $reason = Str::limit(SecretScrubber::scrub($e->getMessage()), 300);

            $this->log($campaign, 'test_failed', $reason);

            // Sans `previous` : l'exception d'origine peut contenir des identifiants.
            throw new TestMailException("L'envoi a échoué : {$reason}");
        }

        $this->log($campaign, 'test', "Mail de test envoyé à {$to} (données de la ligne n°{$recipient->position}).");

        return $to;
    }

    /**
     * @return array{0: string, 1: ComposedMessage}
     *
     * @throws TestMailException
     */
    private function prepare(Campaign $campaign, Recipient $recipient): array
    {
        if (! config('slowmail.enabled')) {
            throw new TestMailException('Envois désactivés (SLOWMAIL_ENABLED=false) : le mail de test ne peut pas partir.');
        }

        $to = (string) config('mail.from.address');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new TestMailException('MAIL_FROM_ADDRESS est vide ou invalide dans la configuration : pas d\'adresse où envoyer le test.');
        }

        if (blank($campaign->subject) && blank($campaign->body)) {
            throw new TestMailException('Le modèle est vide : écrivez-le et enregistrez-le avant d\'envoyer un test.');
        }

        if (! $this->validator->validate($campaign)->canRender()) {
            throw new TestMailException('Le modèle contient des erreurs : corrigez-le avant d\'envoyer un test.');
        }

        $message = $this->composer->compose($campaign, $recipient);

        if ($message->missing !== []) {
            throw new TestMailException(sprintf(
                'Cette ligne ne partirait pas : valeur vide pour %s, sans valeur par défaut. Choisissez une autre ligne.',
                implode(', ', array_map(fn (string $name) => "[{$name}]", $message->missing)),
            ));
        }

        if (! $message->isReady()) {
            throw new TestMailException('Le modèle est inutilisable : '.implode(' ', $message->problems));
        }

        return [$to, $message];
    }

    private function log(Campaign $campaign, string $event, string $detail): void
    {
        SendLog::create(['campaign_id' => $campaign->id, 'event' => $event, 'detail' => $detail]);
    }
}
