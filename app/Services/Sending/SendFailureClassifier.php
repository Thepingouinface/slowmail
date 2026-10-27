<?php

namespace App\Services\Sending;

use App\Enums\SendFailureKind;
use App\Support\SecretScrubber;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerInvalidArgument;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Throwable;

/**
 * Comprend ce qui s'est passé quand l'envoi échoue, en s'appuyant sur ce que Symfony Mailer garde :
 * le code de réponse du serveur (une panne réseau n'en a pas) et le journal de la conversation SMTP.
 *
 * Le principe : dans le doute, ne pas renvoyer. Un message dont le « . » final est parti sans réponse
 * a peut-être été remis : incertain. Tant que ce « . » n'est pas parti, le serveur ne peut pas l'avoir
 * accepté : réessayer est sans risque.
 */
final class SendFailureClassifier
{
    public function classify(Throwable $e): SendFailure
    {
        $message = $this->describe($e);

        // RfcComplianceException (adresse mal formée) est une exception du composant Mime, pas une InvalidArgument.
        if ($e instanceof MimeException || $e instanceof MailerInvalidArgument) {
            return new SendFailure(SendFailureKind::InvalidAddress, $message);
        }

        if (! $e instanceof TransportExceptionInterface) {
            // Une erreur que l'on ne comprend pas, pendant l'envoi : le mail est peut-être parti.
            return new SendFailure(SendFailureKind::Uncertain, $message);
        }

        $debug = $e instanceof TransportException ? $e->getDebug() : '';
        $code = $this->replyCode($e);

        if ($code === null) {
            // Aucune réponse du serveur : connexion impossible, coupée ou expirée.
            return new SendFailure($this->wasTransmitted($debug) ? SendFailureKind::Uncertain : SendFailureKind::Network, $message);
        }

        if ($code >= 400 && $code < 500) {
            return new SendFailure(SendFailureKind::Temporary, $message, $code);
        }

        return new SendFailure(
            $this->isRecipientRejection($debug, $message) ? SendFailureKind::RecipientRejected : SendFailureKind::Permanent,
            $message,
            $code,
        );
    }

    /** Le code de réponse SMTP, seulement si c'est le serveur qui a répondu. */
    private function replyCode(Throwable $e): ?int
    {
        $code = (int) $e->getCode();

        return $code >= 200 && $code < 600 ? $code : null;
    }

    /** Le « . » qui termine le message est parti : le serveur a pu l'accepter sans que nous le sachions. */
    private function wasTransmitted(string $debug): bool
    {
        return preg_match('/\] > \.\s*$/m', $debug) === 1;
    }

    /** Un refus 5xx de l'adresse du destinataire (réponse à RCPT TO), pas de l'authentification ni de l'expéditeur. */
    private function isRecipientRejection(string $debug, string $message): bool
    {
        if (preg_match_all('/\] > (.+?)\s*$/m', $debug, $commands) > 0) {
            return str_starts_with(strtoupper((string) end($commands[1])), 'RCPT TO');
        }

        // Sans journal de conversation : l'état « adresse de destination » de la réponse, ou ses mots.
        return preg_match('/\b5\.1\.[1-3]\b/', $message) === 1
            || preg_match('/user unknown|no such user|unknown user|mailbox (unavailable|not found|does not exist)|recipient (address )?rejected|address rejected/i', $message) === 1;
    }

    private function describe(Throwable $e): string
    {
        $text = preg_replace('/\s+/', ' ', trim($e->getMessage())) ?? '';

        // 297 + « ... » : 300 caractères au plus, ellipse comprise.
        return Str::limit(SecretScrubber::scrub($text), 297);
    }
}
