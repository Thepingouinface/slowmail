#!/usr/bin/env bash
# Construit le site (dossier site/) dans dist/ en remplaçant les trois marqueurs du dépôt :
#   __REPO__      propriétaire/dépôt GitHub, tel qu'écrit  (liens vers le code source)
#   __REPO_LC__   le même en minuscules                      (nom de l'image : ghcr.io/__REPO_LC__)
#   __SITE_URL__  adresse publique du site                   (commande de téléchargement du tutoriel)
# Et un quatrième, __COMPOSE_MODELE__, remplacé dans generateur.html par le fichier telecharger/docker-compose.yml.
#
# Usage :  scripts/build-site.sh propriétaire/dépôt [adresse-du-site]
# Sans adresse, on prend celle de GitHub Pages : https://propriétaire.github.io/dépôt
# Les fichiers de site/ ne sont jamais modifiés : on peut changer de dépôt et reconstruire.

set -euo pipefail
cd "$(dirname "$0")/.."

repo="${1:-${SITE_REPO:-}}"

if [[ ! "$repo" =~ ^[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+$ ]]; then
    echo "Usage : $0 propriétaire/dépôt [adresse-du-site]" >&2
    exit 64
fi

owner="${repo%%/*}"
name="${repo##*/}"
repo_lc="$(printf '%s' "$repo" | tr '[:upper:]' '[:lower:]')"
owner_lc="$(printf '%s' "$owner" | tr '[:upper:]' '[:lower:]')"
name_lc="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"

if [[ -n "${2:-${SITE_URL:-}}" ]]; then
    site_url="${2:-$SITE_URL}"
elif [[ "$name_lc" == "$owner_lc.github.io" ]]; then
    site_url="https://$owner_lc.github.io"
else
    site_url="https://$owner_lc.github.io/$name"
fi
site_url="${site_url%/}"

rm -rf dist
mkdir dist
cp -R site/. dist/

export REPO="$repo" REPO_LC="$repo_lc" SITE_URL_VALUE="$site_url"

find dist -type f \( -name '*.html' -o -name '*.yml' -o -name '*.css' -o -name '*.js' -o -name '*.svg' \) -print0 |
    xargs -0 perl -pi -e 's/__REPO_LC__/$ENV{REPO_LC}/g; s/__REPO__/$ENV{REPO}/g; s/__SITE_URL__/$ENV{SITE_URL_VALUE}/g'

# Le générateur de fichier embarque le modèle déjà complété (une seule source : telecharger/docker-compose.yml).
# Ses valeurs sont remplacées dans le navigateur, sans aucune requête (la page l'interdit).
perl -0777 -pi -e 'BEGIN { local $/; open my $fh, "<", "dist/telecharger/docker-compose.yml" or die "modèle introuvable : $!"; $modele = <$fh>; close $fh } s/__COMPOSE_MODELE__/$modele/g' dist/generateur.html

touch dist/.nojekyll

# Un marqueur oublié ferait publier un tutoriel cassé : on préfère échouer ici.
if grep -rEl '__(REPO|REPO_LC|SITE_URL|COMPOSE_MODELE)__' dist; then
    echo "Des marqueurs n'ont pas été remplacés (fichiers ci-dessus)." >&2
    exit 1
fi

echo "Site construit dans dist/ pour $repo ($site_url)."
