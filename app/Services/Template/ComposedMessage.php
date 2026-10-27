<?php

namespace App\Services\Template;

/** L'objet et le corps rendus pour un destinataire. */
final class ComposedMessage
{
    /**
     * @param  list<string>  $missing  variables vides sans valeur par défaut : la ligne ne doit pas partir
     * @param  list<string>  $problems  modèle inutilisable : rien à envoyer
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly array $missing,
        public readonly array $problems,
    ) {}

    public function isReady(): bool
    {
        return $this->problems === [] && $this->missing === [];
    }
}
