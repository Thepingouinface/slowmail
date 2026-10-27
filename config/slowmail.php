<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | Quand il vaut false, aucun mail ne part, quelle que soit la campagne.
    | Désactivé par défaut : en cas de doute, on n'envoie rien.
    |
    */

    'enabled' => (bool) env('SLOWMAIL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Fuseau d'affichage
    |--------------------------------------------------------------------------
    |
    | Fuseau des heures affichées hors d'une campagne (bandeau d'état, liste de
    | suppression). Une campagne affiche les siennes dans son propre fuseau.
    |
    */

    'timezone' => env('SLOWMAIL_TIMEZONE', 'Europe/Paris'),

    /*
    |--------------------------------------------------------------------------
    | Mot de passe de l'interface
    |--------------------------------------------------------------------------
    |
    | Mot de passe unique. Sans valeur, personne ne peut se connecter.
    |
    */

    'password' => env('SLOWMAIL_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Garde-fou opt-out
    |--------------------------------------------------------------------------
    |
    | Chaîne que le corps d'un mail doit contenir pour activer une campagne.
    |
    */

    'optout_text' => env('SLOWMAIL_OPTOUT_TEXT', 'stop'),

    /*
    |--------------------------------------------------------------------------
    | Délai de grâce
    |--------------------------------------------------------------------------
    |
    | Un mail planifié part seulement s'il est en retard de moins de N minutes.
    | Au-delà (Mac réveillé trop tard), il est replanifié, jamais rattrapé.
    |
    */

    'grace_minutes' => (int) env('SLOWMAIL_GRACE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Expédition
    |--------------------------------------------------------------------------
    |
    | L'adresse d'expédition est MAIL_FROM_ADDRESS. Reply-To prend la même
    | adresse par défaut.
    |
    */

    'reply_to' => env('SLOWMAIL_REPLY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Import CSV
    |--------------------------------------------------------------------------
    |
    | Le fichier envoyé n'est gardé que le temps de la confirmation, dans ce
    | dossier du disque local, puis supprimé (à l'import, à l'annulation, ou
    | après 24 h).
    |
    */

    'import' => [
        'directory' => 'imports',
        'max_kilobytes' => 10240,
        'stale_after_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Envoi
    |--------------------------------------------------------------------------
    |
    | Les valeurs de la spec (§6) et de l'addendum (§3, §5, §6).
    |
    */

    'sending' => [
        // Échec SMTP temporaire : 3 tentatives au total (l'envoi, puis 2 relances), espacées d'une heure.
        'max_attempts' => 3,
        'retry_minutes' => 60,

        // Disjoncteur : ce nombre d'échecs d'envoi consécutifs met la campagne en pause.
        'breaker_threshold' => 3,

        // Une ligne « sending » depuis plus longtemps passe en « uncertain » et n'est jamais renvoyée seule.
        'sending_timeout_minutes' => 10,

        // Plus d'écart que cela entre deux ticks : « interruption détectée » dans le journal.
        'interruption_minutes' => 15,

        // Bandeau d'état : le dernier tick est « en retard » (rouge) au-delà de cette durée.
        'stale_tick_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Purge
    |--------------------------------------------------------------------------
    |
    | `slowmail:purge` efface les rendus et les données des campagnes terminées
    | depuis plus de ce nombre de jours (modifiable avec --days).
    |
    */

    'purge_days' => 90,

];
