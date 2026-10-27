<?php

namespace App\Enums;

/** Ce que veut dire une erreur survenue pendant l'envoi, et donc ce qu'on en fait. */
enum SendFailureKind: string
{
    /** Pas de réseau, et rien n'est parti : la ligne est retentée au prochain tick, sans consommer de tentative. */
    case Network = 'network';

    /** Le message a peut-être été remis : jamais renvoyé automatiquement. */
    case Uncertain = 'uncertain';

    /** Le serveur a répondu « plus tard » (4xx) : relance une heure après, deux fois au plus. */
    case Temporary = 'temporary';

    /** Le serveur a refusé (5xx : authentification, politique…) : la ligne échoue. */
    case Permanent = 'permanent';

    /** Le serveur refuse l'adresse du destinataire : rebond, l'adresse est ajoutée à la liste de suppression. */
    case RecipientRejected = 'recipient_rejected';

    /** L'adresse n'est pas envoyable du tout (syntaxe) : la ligne échoue, hors disjoncteur. */
    case InvalidAddress = 'invalid_address';
}
