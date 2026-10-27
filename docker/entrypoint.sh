#!/bin/sh
# Prépare la base SQLite (volume /data) et la clé d'application, puis lance la commande.
# Les migrations se font dans slowmail-run, selon le rôle : ici, rien ne touche au schéma.
set -eu

DB="${DB_DATABASE:-/data/database.sqlite}"

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p "$(dirname "$DB")"
    [ -f "$DB" ] || touch "$DB"
fi

# Sans APP_KEY dans l'environnement, on en génère une une seule fois et on la garde à côté de la base.
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE="$(dirname "$DB")/app.key"
    [ -s "$KEY_FILE" ] || php artisan key:generate --show --no-ansi > "$KEY_FILE"
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

exec "$@"
