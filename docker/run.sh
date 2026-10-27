#!/bin/bash
# Lance ce que le rôle demande (SLOWMAIL_ROLE) :
#   web        l'interface seule (migre la base d'abord)
#   scheduler  le scheduler seul
#   all        les deux dans ce conteneur (défaut)
#
# En mode « all », si l'un des deux processus s'arrête tout seul, c'est une panne : le conteneur s'arrête
# lui aussi avec un code d'erreur, pour que Docker le relance (restart: unless-stopped). Un arrêt demandé
# (docker stop) est propre et sort avec le code 0.
set -uo pipefail

role="${SLOWMAIL_ROLE:-all}"

serve() { php artisan serve --host=0.0.0.0 --port=8000 --no-reload; }
tick() { php artisan schedule:work; }
migrate() { php artisan migrate --force --no-interaction; }

case "$role" in
    web)
        migrate || exit 1
        exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    all)
        migrate || exit 1

        tick & scheduler_pid=$!
        serve & web_pid=$!

        stopping=0
        stop() { kill -TERM "$scheduler_pid" "$web_pid" 2>/dev/null || true; }
        trap 'stopping=1; stop' TERM INT

        wait -n "$scheduler_pid" "$web_pid"
        status=$?
        stop
        wait

        if [ "$stopping" = 1 ]; then
            exit 0
        fi

        echo "Un processus s'est arrêté (code $status) : le conteneur s'arrête pour être relancé." >&2
        exit 1
        ;;
    *)
        echo "SLOWMAIL_ROLE inconnu : « $role ». Valeurs possibles : web, scheduler, all." >&2
        exit 64
        ;;
esac
