<?php

namespace App\Enums;

enum DeliveryOutcome: string
{
    case Sent = 'sent';
    case DryRun = 'dry_run';
    case Skipped = 'skipped';
    case Suppressed = 'suppressed';
    case Retry = 'retry';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case NetworkUnavailable = 'network';
    case Uncertain = 'uncertain';

    /** Une autre exécution a réclamé la ligne avant nous : rien n'est parti de notre côté. */
    case Lost = 'lost';

    /** Le kill switch est coupé : rien n'est parti, la campagne n'est pas en cause. */
    case Disabled = 'disabled';

    /** La campagne ne peut plus envoyer (modèle devenu invalide, adresse d'expédition) : elle est mise en pause. */
    case Halted = 'halted';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'envoyé',
            self::DryRun => 'simulé, aucun mail envoyé',
            self::Skipped => 'ignoré (valeur vide)',
            self::Suppressed => 'adresse supprimée',
            self::Retry => 'échec temporaire du serveur, nouvelle tentative plus tard',
            self::Failed => 'échec',
            self::Bounced => 'adresse refusée par le serveur (rebond)',
            self::NetworkUnavailable => 'réseau indisponible, nouvelle tentative au prochain passage',
            self::Uncertain => 'envoi incertain, à vérifier dans vos envoyés',
            self::Lost => 'ligne déjà prise par un autre passage',
            self::Disabled => 'envois désactivés',
            self::Halted => 'campagne mise en pause',
        };
    }

    /** Une tentative d'envoi a eu lieu (ou a été réclamée) : le tick s'arrête là, un mail au plus par tick. */
    public function consumesTick(): bool
    {
        return ! in_array($this, [self::Skipped, self::Suppressed], true);
    }
}
