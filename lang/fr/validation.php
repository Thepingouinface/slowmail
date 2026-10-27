<?php

// Les règles utilisées par l'application. Toute autre règle retombe sur l'anglais du framework
// (APP_FALLBACK_LOCALE=en) : un message s'affiche toujours, jamais une clé brute.
return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être du texte.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'array' => 'Le champ :attribute doit être une liste.',
    'date_format' => 'Le champ :attribute doit être une date au format :format.',
    'enum' => 'La valeur choisie pour le champ :attribute n\'est pas valable.',
    'extensions' => 'Le champ :attribute doit avoir l\'extension : :values.',
    'max' => [
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
        'file' => 'Le champ :attribute ne doit pas dépasser :max Ko.',
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'array' => 'Le champ :attribute ne doit pas avoir plus de :max éléments.',
    ],

    'attributes' => [
        'name' => 'nom',
        'password' => 'mot de passe',
        'file' => 'fichier',
        'email_column' => 'colonne des adresses',
        'subject' => 'objet',
        'body' => 'corps',
        'recipient' => 'ligne',
        'recipient_id' => 'ligne',
        'status' => 'statut',
        'notes' => 'notes',
        'value' => 'adresse ou domaine',
        'reason' => 'motif',
        'daily_max' => 'nombre de mails par jour',
        'window_start' => 'début de la plage',
        'window_end' => 'fin de la plage',
        'timezone' => 'fuseau horaire',
        'days' => 'jours autorisés',
        'days.*' => 'jour autorisé',
        'min_gap_minutes' => 'écart minimum',
        'start_at' => 'date de début',
        'end_at' => 'date de fin',
        'max_total' => 'nombre total de mails',
        'ramp_start' => 'départ de la montée progressive',
        'ramp_step' => 'palier de la montée progressive',
        'ramp_every_days' => 'intervalle de la montée progressive',
        'domain_cooldown_days' => 'délai entre deux mails à un même domaine',
    ],
];
