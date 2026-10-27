<?php

namespace App\Enums;

/**
 * Les événements écrits dans `send_logs` (colonne `event`, une chaîne). La liste est celle de CLAUDE.md :
 * l'interface les nomme en français et les colore. Un événement inconnu s'affiche tel quel.
 */
enum JournalEvent: string
{
    case Plan = 'plan';
    case Replan = 'replan';
    case Suppressed = 'suppressed';
    case Skipped = 'skipped';
    case DryRun = 'dry_run';
    case Sent = 'sent';
    case Retry = 'retry';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Uncertain = 'uncertain';
    case Network = 'network';
    case Interruption = 'interruption';
    case Activated = 'activated';
    case Paused = 'paused';
    case CircuitBreaker = 'circuit_breaker';
    case Resumed = 'resumed';
    case Finished = 'finished';
    case Resolved = 'resolved';
    case Reply = 'reply';
    case Purge = 'purge';
    case Test = 'test';
    case TestFailed = 'test_failed';
    case Import = 'import';
    case Settings = 'settings';
    case SimulationReset = 'simulation_reset';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Plan du jour',
            self::Replan => 'Replanification',
            self::Suppressed => 'Supprimé',
            self::Skipped => 'Ignoré',
            self::DryRun => 'Simulation',
            self::Sent => 'Envoyé',
            self::Retry => 'Nouvelle tentative',
            self::Failed => 'Échec',
            self::Bounced => 'Rebond',
            self::Uncertain => 'Envoi incertain',
            self::Network => 'Réseau indisponible',
            self::Interruption => 'Interruption',
            self::Activated => 'Activation',
            self::Paused => 'Pause',
            self::CircuitBreaker => 'Disjoncteur',
            self::Resumed => 'Reprise',
            self::Finished => 'Fin',
            self::Resolved => 'Envoi tranché',
            self::Reply => 'Réponse',
            self::Purge => 'Purge',
            self::Test => 'Mail de test',
            self::TestFailed => 'Test refusé',
            self::Import => 'Import',
            self::Settings => 'Réglages',
            self::SimulationReset => 'Simulation remise à zéro',
        };
    }

    /** `ok`, `warn`, `error` ou `neutral` : la couleur de la pastille. */
    public function tone(): string
    {
        return match ($this) {
            self::Sent, self::Activated, self::Resumed => 'ok',
            self::Failed, self::Bounced, self::CircuitBreaker, self::TestFailed => 'error',
            self::Retry, self::Uncertain, self::Network, self::Interruption, self::Paused, self::Skipped, self::Suppressed => 'warn',
            default => 'neutral',
        };
    }

    /** Le libellé d'un événement du journal, qu'il soit connu ou non. */
    public static function labelFor(string $event): string
    {
        return self::tryFrom($event)?->label() ?? $event;
    }

    public static function toneFor(string $event): string
    {
        return self::tryFrom($event)?->tone() ?? 'neutral';
    }
}
