<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Un mail de prospection : texte brut, sans pièce jointe, désinscription possible par réponse.
 * From et Reply-To viennent de la configuration (.env ou variables d'environnement).
 */
class ProspectMail extends Mailable
{
    /** @param  ?string  $mailMessageId  identifiant sans chevrons, généré avant l'envoi pour pouvoir le stocker */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly ?string $mailMessageId = null,
    ) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');

        return new Envelope(
            from: new Address($from, (string) config('mail.from.name')),
            replyTo: [new Address(config('slowmail.reply_to') ?: $from)],
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.plain', with: ['body' => $this->mailBody]);
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->mailMessageId,
            text: ['List-Unsubscribe' => '<mailto:'.config('mail.from.address').'>'],
        );
    }
}
