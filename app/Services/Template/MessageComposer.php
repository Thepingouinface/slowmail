<?php

namespace App\Services\Template;

use App\Models\Campaign;
use App\Models\Recipient;
use Carbon\CarbonInterface;

/**
 * Rend l'objet et le corps de la campagne pour un destinataire.
 * Ne modifie rien : c'est à l'appelant de décider (aperçu, test, ou envoi qui passera la ligne en `skipped`).
 */
final class MessageComposer
{
    public function __construct(
        private readonly TemplateParser $parser,
        private readonly TemplateRenderer $renderer,
    ) {}

    /** @param  ?CarbonInterface  $at  moment de l'envoi, qui décide de [DATE] ; maintenant par défaut */
    public function compose(Campaign $campaign, Recipient $recipient, ?CarbonInterface $at = null): ComposedMessage
    {
        $subject = $this->parser->parse((string) $campaign->subject);
        $body = $this->parser->parse((string) $campaign->body);

        if (! $subject->isValid() || ! $body->isValid()) {
            return $this->unusable('Le modèle contient des erreurs de syntaxe.');
        }

        if (Variables::trim((string) $campaign->subject) === '' || Variables::trim((string) $campaign->body) === '') {
            return $this->unusable('L\'objet ou le corps du modèle est vide.');
        }

        $at ??= now();
        $data = $recipient->data ?? [];

        $renderedSubject = $this->renderer->render($subject, $data, $recipient->email, $at, $campaign->timezone());
        $renderedBody = $this->renderer->render($body, $data, $recipient->email, $at, $campaign->timezone());

        return new ComposedMessage(
            subject: $this->singleLine($renderedSubject->text),
            body: str_replace(["\r\n", "\r"], "\n", $renderedBody->text),
            missing: $this->merge($renderedSubject->missing, $renderedBody->missing),
            problems: [],
        );
    }

    /**
     * La même variable, écrite [Ville] dans l'objet et [VILLE] dans le corps, ne compte qu'une fois.
     *
     * @param  list<string>  $subject
     * @param  list<string>  $body
     * @return list<string>
     */
    private function merge(array $subject, array $body): array
    {
        $names = [];

        foreach ([...$subject, ...$body] as $name) {
            $names[Variables::key($name)] ??= $name;
        }

        return array_values($names);
    }

    /**
     * Une cellule du CSV peut contenir un saut de ligne : dans l'objet, ce serait une injection d'en-tête.
     */
    private function singleLine(string $subject): string
    {
        return trim(preg_replace('/[\p{Cc}\s]+/u', ' ', $subject) ?? '');
    }

    private function unusable(string $problem): ComposedMessage
    {
        return new ComposedMessage('', '', [], [$problem]);
    }
}
