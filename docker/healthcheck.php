<?php

// Santé du conteneur, sans démarrer Laravel (la sonde passe toutes les 15 secondes).
//   - rôle web ou all : l'interface répond 200 sur /up ;
//   - rôle scheduler ou all : slowmail:tick a battu dans les 5 dernières minutes (table scheduler_state).
// Le kill switch coupé n'y change rien : le tick passe et note son battement même quand rien n'est envoyé.

$role = getenv('SLOWMAIL_ROLE') ?: 'all';
$maxAgeSeconds = 300;

function unhealthy(string $why): never
{
    fwrite(STDERR, "unhealthy: {$why}\n");
    exit(1);
}

if ($role !== 'scheduler') {
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:8000/up', false, $context);

    if ($body === false || ! str_contains($http_response_header[0] ?? '', ' 200')) {
        unhealthy('l\'interface ne répond pas sur /up');
    }
}

// Avec une autre base que SQLite, on ne sait pas lire le battement d'ici : on s'en tient à l'interface.
if ($role !== 'web' && (getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite') {
    try {
        $pdo = new PDO('sqlite:'.(getenv('DB_DATABASE') ?: '/data/database.sqlite'), null, null, [PDO::ATTR_TIMEOUT => 3]);
        $last = $pdo->query("SELECT value FROM scheduler_state WHERE key = 'last_tick_at'")->fetchColumn();
    } catch (Throwable) {
        $last = false;
    }

    if (! is_string($last)) {
        unhealthy('le scheduler n\'a encore jamais battu');
    }

    $age = time() - (int) strtotime($last.' UTC');

    if ($age > $maxAgeSeconds) {
        unhealthy("le scheduler n'a pas battu depuis {$age} s");
    }
}

exit(0);
