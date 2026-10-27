# syntax=docker/dockerfile:1

# --- Dépendances PHP (sans les paquets de dev) -----------------------------------
# Ces deux premières étapes ne produisent que des fichiers (PHP, CSS, JS) : elles tournent sur la machine
# qui construit, jamais sous émulation, même pour une image arm64 construite depuis un serveur amd64.
FROM --platform=$BUILDPLATFORM php:8.3-cli-bookworm AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

# --- Assets (Tailwind via Vite) ----------------------------------------------------
# Tailwind scanne aussi les vues de pagination de Laravel : il lui faut vendor/.
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources ./vendor/laravel/framework/src/Illuminate/Pagination/resources
RUN npm run build

# --- Image finale : SlowMail complet (interface web + scheduler) -------------------
# Une seule image pour tout. SLOWMAIL_ROLE choisit ce qu'un conteneur lance :
#   all        (défaut) l'interface et le scheduler dans le même conteneur
#   web        l'interface seule
#   scheduler  le scheduler seul (slowmail:tick chaque minute)
FROM php:8.3-cli-alpine

LABEL org.opencontainers.image.title="SlowMail" \
      org.opencontainers.image.description="Envoi de mails de prospection au compte-gouttes : interface web et scheduler dans une seule image." \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

# bash : le superviseur attend le premier processus qui s'arrête (wait -n). tini : PID 1, signaux et zombies.
RUN apk add --no-cache bash tini

# www-data prend l'uid/gid 33, comme sous Debian (Alpine lui donne 82 et réserve 33 à un utilisateur inutile, xfs).
# Sans cela, un volume /data créé par une ancienne image Debian devient illisible : « attempt to write a readonly database ».
RUN apk add --no-cache --virtual .idtools shadow \
    && (deluser xfs || true) && (delgroup xfs || true) \
    && groupmod -g 33 www-data && usermod -u 33 www-data \
    && apk del .idtools

# Valeurs sûres par défaut ; un --env-file, un -e ou le env_file de Compose les remplace.
# MAIL_MAILER=smtp : jamais le transport « log », qui écrirait le corps des mails dans les journaux.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    QUEUE_CONNECTION=sync \
    MAIL_MAILER=smtp \
    PHP_CLI_SERVER_WORKERS=4 \
    SLOWMAIL_ROLE=all

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /build/public/build ./public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-slowmail.ini
COPY docker/entrypoint.sh /usr/local/bin/slowmail-entrypoint
COPY docker/run.sh /usr/local/bin/slowmail-run
COPY docker/healthcheck.php /usr/local/bin/slowmail-healthcheck.php

RUN chmod +x /usr/local/bin/slowmail-entrypoint /usr/local/bin/slowmail-run \
    && mkdir -p /data storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /data storage bootstrap/cache

USER www-data
# Les vues sont compilées dès la construction. Pas de config:cache : l'environnement du conteneur doit rester maître.
RUN php artisan package:discover --ansi && php artisan view:cache --ansi

# La base SQLite, la clé d'application et les sauvegardes vivent ici : à monter sur un volume.
VOLUME /data
EXPOSE 8000

# Le rôle décide de ce qui est vérifié : l'interface répond, et/ou le scheduler a battu dans les 5 dernières minutes.
HEALTHCHECK --interval=15s --timeout=8s --start-period=90s --retries=3 \
    CMD ["php", "/usr/local/bin/slowmail-healthcheck.php"]

ENTRYPOINT ["/sbin/tini", "--", "slowmail-entrypoint"]
CMD ["slowmail-run"]
