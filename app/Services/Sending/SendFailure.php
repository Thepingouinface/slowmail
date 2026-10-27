<?php

namespace App\Services\Sending;

use App\Enums\SendFailureKind;

final class SendFailure
{
    /** @param  string  $message  l'erreur, sans identifiants, sur une ligne et tronquée */
    public function __construct(
        public readonly SendFailureKind $kind,
        public readonly string $message,
        public readonly ?int $code = null,
    ) {}

    /**
     * Compte pour le disjoncteur : seules les réponses d'erreur du serveur (refus d'authentification,
     * « plus tard », politique) en font partie. Ni le réseau, ni une adresse rejetée ou invalide.
     */
    public function countsForBreaker(): bool
    {
        return in_array($this->kind, [SendFailureKind::Temporary, SendFailureKind::Permanent], true);
    }
}
