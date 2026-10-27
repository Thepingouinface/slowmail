<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Planned = 'planned';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Bounced = 'bounced';
    case DryRun = 'dry_run';
    case Suppressed = 'suppressed';
    // Envoi interrompu en plein vol : jamais renvoyé automatiquement.
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Planned => 'Planifié',
            self::Sending => 'Envoi en cours',
            self::Sent => 'Envoyé',
            self::Failed => 'Échec',
            self::Skipped => 'Ignoré',
            self::Bounced => 'Rebond',
            self::DryRun => 'Simulé',
            self::Suppressed => 'Supprimé',
            self::Uncertain => 'À vérifier',
        };
    }

    /** La couleur de la pastille : `neutral`, `ok`, `warn`, `error` ou `info`. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Suppressed => 'neutral',
            self::Planned, self::Sending, self::DryRun => 'info',
            self::Sent => 'ok',
            self::Skipped, self::Uncertain => 'warn',
            self::Failed, self::Bounced => 'error',
        };
    }

    /**
     * Statuts d'une adresse qui a peut-être reçu un mail.
     *
     * @return list<self>
     */
    public static function contacted(): array
    {
        return [self::Sending, self::Sent, self::Uncertain];
    }
}
