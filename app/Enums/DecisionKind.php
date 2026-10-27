<?php

namespace App\Enums;

enum DecisionKind: string
{
    /** Un mail est dû : à envoyer maintenant. */
    case Send = 'send';

    /** Rien à envoyer pour l'instant. */
    case Wait = 'wait';

    /** La campagne n'a plus rien à faire (fin, maximum atteint, plus de destinataires). */
    case Finished = 'finished';

    /** Réglages inutilisables : on n'envoie rien. */
    case Blocked = 'blocked';
}
