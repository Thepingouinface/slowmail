# SlowMail

Envoi de mails de prospection **au compte-gouttes** : quelques-uns par jour, à des heures variables, à partir d'un CSV et d'un modèle de texte. Outil mono-utilisateur, sans suivi d'ouverture ni de clics.

- Spécification : [`SPEC-slow-mail.md`](SPEC-slow-mail.md)
- Addendum (l'app tourne sur un Mac) : [`SPEC-addendum-mac.md`](SPEC-addendum-mac.md) — **prime sur la spec** là où ils divergent.

## État d'avancement

| Phase | Contenu | État |
|---|---|---|
| 1. Socle | Docker, migrations, mot de passe, import CSV avec rapport | fait |
| 2. Modèle | variables `[X]`, `[X\|défaut]`, aperçu, mail de test | fait |
| 3. Planification | tirage des heures, plage, fériés, montée progressive, `slowmail:status` | fait |
| 4. Envoi | `slowmail:tick`, `uncertain`, disjoncteur, suppression, `dry_run` | fait |
| 5. Interface | écrans complets, bandeau d'état, export CSV, liste de suppression | fait |
| 6. Distribution | licence AGPL, site et tutoriel Docker, image publique, `slowmail:backup` | fait ; site publié, **image à publier** (étiquette `v1.0.0`, voir « Site et image publique ») |

**Rien ne part tant que vous ne le décidez pas** : `SLOWMAIL_ENABLED` vaut `false` par défaut (kill switch), et une campagne ne part que si vous l'activez. La marche à suivre prudente, de la simulation aux trois premières adresses réelles, est décrite dans [« Premiers envois »](#premiers-envois-pas-à-pas) ; les écrans, dans [« Interface »](#interface).

## Installation (Docker / OrbStack)

Prérequis : OrbStack (ou Docker Desktop).

```bash
cp .env.example .env
```

Éditez `.env` : au minimum `SLOWMAIL_PASSWORD`. Les identifiants SMTP (OVH) ne vivent que dans ce fichier, jamais dans le code ni dans l'image.

```bash
docker compose up -d --build
```

L'interface est sur <http://127.0.0.1:8080> (le port n'est lié qu'à `127.0.0.1`). Changez `SLOWMAIL_PORT` dans `.env` si 8080 est pris.

Deux services seulement, tirés de la même image :

- `app` (`SLOWMAIL_ROLE=web`) : l'interface, et les migrations au démarrage ;
- `scheduler` (`SLOWMAIL_ROLE=scheduler`) : `php artisan schedule:work`, qui lance `slowmail:tick` chaque minute.

Pas de worker de file ni de Redis. La base SQLite est dans le volume Docker `slowmail-data` : elle survit à `docker compose down` (mais pas à `docker compose down -v`).

### Redémarrage automatique après un reboot du Mac

Les deux services ont `restart: unless-stopped`. Pour que tout redémarre après un redémarrage du Mac, activez dans les réglages d'OrbStack **« Lancer OrbStack à l'ouverture de session »** (Settings → General → Start at login).

## Image Docker autonome

L'image `slowmail` contient **tout** : l'interface web, le scheduler, les assets compilés et les migrations. Un seul conteneur suffit, sans Compose :

```bash
docker build -t slowmail:latest .

docker run -d --name slowmail --restart unless-stopped \
  -p 127.0.0.1:8080:8000 \
  -v slowmail-data:/data \
  --env-file .env \
  slowmail:latest
```

- `-p 127.0.0.1:8080:8000` : l'interface n'est joignable que depuis ce Mac. N'ouvrez pas ce port au réseau sans raison : il n'y a qu'un mot de passe.
- `-v slowmail-data:/data` : **à ne pas oublier**, c'est là que vivent la base SQLite, la clé d'application et vos campagnes. Sans volume, tout disparaît avec le conteneur.
- `--env-file .env` : mot de passe, SMTP, kill switch (voir `.env.example`). Écrivez les valeurs **sans guillemets** : Docker les garderait telles quelles. Seul `SLOWMAIL_PASSWORD` est indispensable pour démarrer ; sans lui, personne ne peut se connecter.

Au démarrage, l'image génère la clé d'application si elle manque (gardée dans le volume), migre la base, puis lance l'interface **et** le scheduler. Si l'un des deux s'arrête tout seul, le conteneur s'arrête avec une erreur et Docker le relance ; un `docker stop` est propre.

**Rôle d'un conteneur** (`-e SLOWMAIL_ROLE=…`) : `all` (défaut, les deux), `web` (interface seule) ou `scheduler` (scheduler seul). C'est ce que fait `docker-compose.yml` pour séparer les deux, comme le demande l'addendum. Ne lancez **pas** deux conteneurs `all` ou `scheduler` sur le même volume : un seul scheduler par base.

**Santé** : `docker ps` affiche `healthy` quand l'interface répond sur `/up` et que le scheduler a battu dans les 5 dernières minutes (une minute environ après le démarrage, le temps du premier passage).

**Commandes** dans le conteneur :

```bash
docker exec slowmail php artisan slowmail:status "Ma campagne"
docker exec slowmail php artisan slowmail:campaign "Ma campagne" activate
```

**Sauvegarde** (à chaud, sans arrêter le conteneur) : `slowmail:backup` écrit une copie cohérente (`VACUUM INTO`, jamais une copie du fichier pendant que le WAL est actif) dans `/data/backups/slowmail-AAAAMMJJ-HHMMSS.sqlite`, en `0600`, et refuse d'écraser un fichier existant. Un chemin en argument remplace le dossier par défaut.

```bash
docker exec slowmail php artisan slowmail:backup
docker cp slowmail:/data/backups/. ./sauvegardes
```

**Restauration** : arrêter le conteneur, remettre la copie à la place de `/data/database.sqlite` **sans les fichiers `-wal` et `-shm`**, et rendre le fichier à `www-data` (`docker cp` crée des fichiers appartenant à root). Avec Compose (service `slowmail`, voir « Site et image publique ») :

```bash
docker compose stop
docker compose cp sauvegardes/slowmail-AAAAMMJJ-HHMMSS.sqlite slowmail:/data/restauration.sqlite
docker compose run --rm --no-deps --user root --entrypoint sh slowmail -c "rm -f /data/database.sqlite-wal /data/database.sqlite-shm && mv /data/restauration.sqlite /data/database.sqlite && chown www-data:www-data /data/database.sqlite && chmod 600 /data/database.sqlite"
docker compose start
```

**Déplacer l'image** sur une autre machine, sans passer par un registre :

```bash
docker save slowmail:latest | gzip > slowmail-image.tar.gz        # sur ce Mac (≈ 45 Mo)
gunzip -c slowmail-image.tar.gz | docker load                       # sur l'autre machine
```

L'image est construite pour l'architecture de la machine qui la construit (arm64 sur un Mac Apple). Pour un serveur Intel/AMD : `docker buildx build --platform linux/amd64 -t slowmail:latest --load .`.

**Mise à jour** : reconstruisez l'image, supprimez le conteneur (`docker rm -f slowmail`) et relancez la même commande `docker run` : le volume `/data` garde tout. Les volumes créés par les versions précédentes de l'image restent lisibles.

L'image tourne sans droits root, n'embarque jamais votre `.env` ni vos identifiants (ils viennent de l'environnement à l'exécution), envoie ses journaux sur la sortie du conteneur (`docker logs`) et n'y écrit ni le corps des mails ni les paramètres de recherche des URL.

## Site et image publique

SlowMail est proposé gratuitement à la communauté sous licence **AGPL-3.0-or-later** ([`LICENSE`](LICENSE)). Trois éléments le distribuent :

| Élément | Où | Rôle |
|---|---|---|
| Le site | `site/` (pages statiques, en français) | Présentation, tutoriel Docker pour débutants (`tutoriel.html`), premiers envois, bon usage, dépannage |
| Le fichier de l'utilisateur | `site/telecharger/docker-compose.yml` | **Un seul fichier** : l'image publique, un volume, le port sur `127.0.0.1`, les réglages à remplir (mot de passe, SMTP). Un seul service (`SLOWMAIL_ROLE=all`) |
| L'image | `ghcr.io/<propriétaire>/<dépôt>` | Construite pour `linux/amd64` et `linux/arm64` par `.github/workflows/image.yml` à chaque étiquette `v*` |

Le site contient des marqueurs que `scripts/build-site.sh` remplace dans `dist/` (les sources ne sont jamais modifiées) : `__REPO__` (propriétaire/dépôt), `__REPO_LC__` (en minuscules, nom de l'image), `__SITE_URL__` (adresse du site, celle de GitHub Pages par défaut). Le script échoue s'il en reste un.

```bash
scripts/build-site.sh propriétaire/dépôt          # écrit dist/ ; aperçu : python3 -m http.server -d dist 8099
```

**Première publication** (à faire une fois, dans cet ordre) :

1. Créer le dépôt GitHub public et y pousser le code.
2. Réglages du dépôt > Pages > Source : **GitHub Actions**. Le workflow `.github/workflows/pages.yml` publie `site/` à chaque poussée sur `main`.
3. Pousser une étiquette : `git tag v1.0.0 && git push origin v1.0.0`. `.github/workflows/image.yml` lance les tests, construit l'image et la publie (`1.0.0`, `1.0` et `latest`).
4. Page du paquet (GitHub > Packages > le paquet) > Package settings > **Change visibility > Public**. Sans cela, `docker compose up -d` demande un identifiant. Vérifier hors connexion : `docker logout ghcr.io && docker pull ghcr.io/<propriétaire>/<dépôt>:latest`.

Les captures du site (`site/assets/img/app-*.png`) montrent l'application avec des **données fictives** (adresses en `.example`) ; à refaire si l'interface change.

**AGPL, ce que cela change pour vous** : le texte de la licence accompagne l'image (`LICENSE`). Si vous modifiez SlowMail et que des tiers l'utilisent par le réseau, vous devez leur proposer votre code modifié. Pour la version non modifiée, le dépôt public suffit.

## Format du CSV

- Séparateur `;` ou `,` (détecté), UTF-8 avec ou sans BOM. Un fichier Windows-1252 est lu avec un avertissement ; l'UTF-16 est refusé.
- Colonne `EMAIL` obligatoire (le nom est modifiable à l'import). Les autres colonnes deviennent des variables du modèle.
- Colonne `PRIORITE` facultative : plus petit = plus tôt. Les lignes sans priorité passent après, dans l'ordre du fichier. Sans cette colonne, c'est l'ordre du fichier.
- Colonnes `STATUT`, `DATE_ENVOI`, `REPONSE`, `NOTES` : réservées à l'export, ignorées à l'import.
- Un exemple : [`database/examples/prospects-exemple.csv`](database/examples/prospects-exemple.csv).

Le rapport d'import liste les lignes rejetées avec la raison : adresse vide ou invalide, adresse avec accents (non prise en charge), doublon du fichier, déjà présente dans la campagne, dans la liste de suppression. Les adresses déjà contactées dans une autre campagne ne sont importées que sur confirmation.

Le fichier n'est pas conservé : il reste sur le disque du conteneur le temps de la confirmation, puis il est supprimé (à l'import, à l'annulation, ou après 24 h).

## Modèle du mail

Onglet **Modèle** d'une campagne : un objet et un corps en texte brut (pas de HTML).

| Écriture | Effet |
|---|---|
| `[STRUCTURE]` | remplacé par la colonne `STRUCTURE` du CSV |
| `[STRUCTURE\|votre structure]` | valeur par défaut si la colonne est vide |
| `[TITRE\|]` | défaut explicitement vide : la ligne part quand même, sans rien à cet endroit |
| `\[texte\]` | crochets littéraux, non remplacés |

- Le nom d'une variable ignore la casse et les espaces autour (`[ structure ]`).
- `[DATE]` (jour de l'envoi, `21 octobre 2026`, dans le fuseau de la campagne) et `[EMAIL]` sont toujours disponibles. Ils l'emportent sur une colonne du CSV de même nom (un avertissement le signale).
- Une valeur du CSV n'est jamais relue comme du modèle : une cellule qui contient `[EMAIL]` reste littéralement `[EMAIL]`.
- Syntaxe stricte : un crochet jamais fermé, imbriqué ou fermé sans être ouvert est une erreur, pour qu'un `[PRENOM` ne parte jamais tel quel.
- **Variable inconnue** (colonne absente du CSV) : erreur, avec la liste des variables en cause.
- **Variable vide sans valeur par défaut** : la ligne ne doit pas partir (elle sera `skipped` avec la raison, à l'envoi). L'aperçu le signale.
- **Garde-fou opt-out** : le texte fixe du corps doit contenir `SLOWMAIL_OPTOUT_TEXT` (`stop` par défaut, sans tenir compte de la casse), sinon la campagne ne pourra pas être activée. Une valeur par défaut ou un nom de variable ne comptent pas.
- L'objet tient sur une seule ligne : une cellule du CSV qui contient un saut de ligne ne peut pas injecter d'en-tête.

L'aperçu et le test utilisent le modèle **enregistré**. Choisissez une ligne du CSV (le filtre par adresse aide quand il y en a beaucoup).

**« M'envoyer un test »** : un vrai mail, envoyé à `MAIL_FROM_ADDRESS` avec les données de la ligne choisie. La ligne n'est pas modifiée (ni statut, ni tentative, ni rendu stocké) ; une entrée « test » est ajoutée au journal. Il est refusé si `SLOWMAIL_ENABLED` n'est pas `true`, si `MAIL_FROM_ADDRESS` est vide, si le modèle est incorrect ou si la ligne ne partirait pas. Les erreurs SMTP sont affichées sans les identifiants. L'absence de mention de désinscription n'empêche pas le test : seule l'activation l'exige.

Le mail est en texte brut, sans pièce jointe, avec `From` et `Reply-To` du `.env` (`SLOWMAIL_REPLY_TO`, par défaut l'adresse d'expédition) et `List-Unsubscribe: <mailto:…>` vers l'adresse d'expédition.

## Planification

Le planificateur décide quand un mail peut partir. Il n'envoie rien lui-même : l'envoi est la phase 4.

**Réglages d'une campagne** (stockés dans `campaigns.settings`, `start_at` et `end_at` sont des colonnes ; ils s'éditent dans l'onglet **Réglages**, voir [« Interface »](#interface)) :

| Clé | Défaut | Détail |
|---|---|---|
| `daily_max` | 4 | mails par jour, au plus |
| `window_start` / `window_end` | 09:30 / 17:30 | plage d'envoi, heure locale de la campagne |
| `timezone` | Europe/Paris | fuseau de la plage et de `[DATE]` |
| `days` | 1–5 | jours autorisés, 1 = lundi … 7 = dimanche |
| `skip_holidays` | oui | pas d'envoi les jours fériés français (calculés, sans réseau ; hors Alsace-Moselle) |
| `min_gap_minutes` | 25 | écart minimum entre deux mails |
| `jitter` | oui | heures aléatoires à la seconde, jamais pile sur l'heure ronde ; sans : heures régulières |
| `max_total` | aucun | arrêt après N mails au total |
| `ramp_up`, `ramp_start`, `ramp_step`, `ramp_every_days` | non, 2, 1, 3 | montée progressive, plafonnée à `daily_max` |
| `domain_cooldown_days` | 7 | jamais deux mails au même domaine (dans la campagne) à moins de N jours |
| `dry_run` | non | tout fonctionne, aucun mail ne part (voir [« Mode simulation »](#mode-simulation-dry_run-et-changement-de-réglages)) |

`CampaignSettings::violations()` liste ce qui rend des réglages inutilisables ; avec des réglages invalides, rien n'est planifié ni envoyé (l'onglet Réglages refuse de les enregistrer et affiche ces raisons telles quelles).

**Ce qui est garanti** (addendum « Mac », §2 et §5) :

- Au premier passage de chaque jour, les heures du jour sont tirées une seule fois et stockées (`recipients.planned_at`). Un redémarrage les relit sans les recalculer, même si deux processus passent en même temps.
- Un créneau passé de plus de `SLOWMAIL_GRACE_MINUTES` (30) n'est jamais rattrapé en rafale : il est replanifié sur la plage restante du jour (écart minimum respecté), sinon remis en attente pour le prochain jour autorisé. Les créneaux d'un jour passé sont remis en attente.
- Le quota d'un jour manqué est perdu, pas cumulé : après trois jours d'arrêt, on reprend à `daily_max`.
- Plage horaire, jour autorisé, quota et écart minimum sont revérifiés à l'instant de l'envoi, pas seulement au tirage.
- La montée progressive compte les jours où des mails sont réellement partis : une semaine d'absence ne fait sauter aucun palier.
- `end_at` et `max_total` sont respectés après une longue interruption.
- Un mail qui a pu partir (envoyé, simulé, en cours ou incertain) compte dans le quota, l'écart et le cooldown : dans le doute, on compte.
- Une ligne dont le domaine est en cooldown est sautée, pas perdue : elle part dès que le délai est écoulé, et les suivantes passent devant.

Ces garanties sont vérifiées par des tests, dont une simulation minute par minute sur trois semaines (`php artisan test --group=simulation`, une vingtaine de secondes) qui contrôle aussi que le plan annoncé par `slowmail:status` est celui qui se réalise.

## Envoi

Le scheduler (service `scheduler`) appelle `slowmail:tick` **chaque minute**. Pas de file ni de worker : le tick envoie directement, au plus **un mail par passage**, toutes campagnes confondues. Un seul passage à la fois (verrou en base), et la ligne à envoyer est réclamée par une écriture atomique avant l'appel SMTP : même deux processus lancés ensemble n'envoient jamais deux fois.

**Juste avant chaque envoi**, tout est revérifié : kill switch, liste de suppression, modèle (mention de désinscription comprise), valeurs vides, plage horaire, quota, écart. Une règle qui ne tient plus met la campagne en pause, avec la raison, plutôt que d'envoyer.

| Ce qui arrive | Ce que fait l'appli |
|---|---|
| Variable vide sans valeur par défaut | la ligne passe en `skipped`, avec la raison ; elle ne compte pas dans le quota |
| Adresse dans la liste de suppression (même ajoutée après l'activation) | la ligne passe en `suppressed`, rien ne part |
| `dry_run` | tout fonctionne, aucun mail ne part : statut `dry_run`, rendu gardé, compte comme un envoi pour le quota et l'écart |
| Réseau indisponible (Wi-Fi pas revenu au réveil) | la ligne est retentée au tick suivant, **sans consommer de tentative ni toucher au disjoncteur** ; au-delà de la tolérance, elle est replanifiée. Une seule ligne de journal tant que la panne dure |
| Le serveur répond « plus tard » (4xx) | 2 nouvelles tentatives, à 1 h d'intervalle, puis `failed` |
| Le serveur refuse (5xx : authentification, politique…) | `failed` tout de suite |
| Le serveur refuse l'adresse du destinataire | `bounced`, et l'adresse est ajoutée à la liste de suppression (motif `bounce`) |
| **3 échecs d'envoi consécutifs** (4xx et 5xx ; ni le réseau, ni les rebonds) | la campagne est mise en pause (disjoncteur), avec la raison dans `slowmail:status` |
| La machine s'arrête **en plein envoi**, ou la connexion tombe après la fin du message sans réponse | la ligne passe en `uncertain` (au bout de 10 min pour un arrêt brutal). **Elle n'est jamais renvoyée toute seule** : vérifiez dans vos envoyés, puis « C'est parti » ou « Pas parti » |
| Le scheduler n'a pas tourné pendant plus de 15 minutes (Mac en veille ou éteint) | « interruption détectée (X h) » dans le journal de chaque campagne active ; rien n'est rattrapé en rafale |

Une campagne ne se termine pas tant qu'une ligne est incertaine. L'appli ne regarde jamais l'intérieur de votre boîte mail : c'est à vous de trancher.

Les erreurs SMTP sont écrites dans le journal sans l'identifiant de connexion, et jamais dans les journaux de Laravel. Le corps des mails n'y figure jamais.

## Interface

Tout est derrière `SLOWMAIL_PASSWORD`. Les écrans ne sont qu'une peau : chaque bouton appelle un service déjà testé (`CampaignLifecycle`, `RecipientActions`, `Suppressions`, `CampaignSettings`, `SchedulerStatus`). Rien dans l'interface n'affiche d'identifiant SMTP ni le corps d'un mail envoyé.

**Bandeau d'état**, en haut de chaque page : l'âge et l'heure exacte du dernier passage du scheduler (en rouge au-delà de 10 minutes, ou s'il n'a jamais tourné : le Mac dormait, OrbStack est arrêté), le nombre d'envois à vérifier dans vos envoyés, les campagnes en pause, le prochain envoi prévu, et un avertissement quand `SLOWMAIL_ENABLED` n'est pas `true`. Il est lu au chargement de la page : rechargez-la pour le rafraîchir. Les heures sont affichées dans le fuseau `SLOWMAIL_TIMEZONE` (Europe/Paris par défaut) ; à l'intérieur d'une campagne, dans le fuseau de la campagne.

**Campagnes** : le statut, l'avancement (envoyés / total ; les lignes simulées sont comptées à part), le prochain envoi prévu (l'heure exacte quand le plan du jour est tiré, sinon le jour), les lignes à vérifier, la raison d'une pause.

**Une campagne** : l'en-tête montre le statut, une pastille « Simulation » si le mode est actif, le récapitulatif des réglages en clair, **Exporter en CSV** et le bouton qui convient à l'état : **Activer** (brouillon), **Mettre en pause** (active), **Reprendre** (en pause). Activer et Reprendre demandent confirmation et disent si de vrais mails vont partir. Un refus affiche ses raisons telles quelles (mention de désinscription absente, modèle invalide, réglages invalides, aucun destinataire, pas d'adresse d'expédition). Quand le disjoncteur a mis la campagne en pause, l'en-tête affiche la raison et le nombre d'échecs consécutifs. Quatre onglets :

- **Destinataires** : le tableau filtrable par statut et par adresse, avec la date, la réponse, et la raison (`last_error`) des lignes ignorées, en échec, rebondies ou incertaines. Pour chaque ligne, « Réponse et détails » ouvre les notes et les boutons **Répondu**, **Rendez-vous** et **Stop** (Stop ajoute l'adresse à la liste de suppression, dans toutes les campagnes), les données du CSV et le journal de la ligne. Les lignes `uncertain` ont un encadré « À vérifier dans vos envoyés » en tête de l'onglet : l'objet, l'heure et l'identifiant du message pour les retrouver, puis **C'est parti** (la ligne devient envoyée) ou **Pas parti** (elle retourne en attente et partira à un prochain créneau ; confirmation demandée). SlowMail ne tranche jamais à votre place. L'import d'un CSV est aussi dans cet onglet.
- **Modèle** : l'objet, le corps, les contrôles, l'aperçu, « M'envoyer un test ».
- **Réglages** : tous les réglages de la spec (§5), le récapitulatif en clair des réglages enregistrés avec la fin estimée et les prochains jours d'envoi. Les réglages invalides ne sont pas enregistrés ; les raisons s'affichent et votre saisie est gardée.
- **Journal** : les événements de la campagne, les plus récents d'abord, par pages de 50, filtrables par type et par ligne. Jamais de corps de mail ni d'identifiant.

**Liste de suppression** (menu principal) : les adresses et domaines, avec motif et date, une recherche et un filtre par motif, et un formulaire d'ajout (`jean@exemple.fr` ou `@exemple.fr`). L'ajout retire aussitôt les lignes en attente ou planifiées correspondantes, dans toutes les campagnes. Il n'y a **volontairement pas de bouton de retrait** : une adresse retirée par un clic malheureux recevrait de nouveau des mails. Pour en retirer une, supprimez sa ligne dans la table `suppressions` (`docker compose exec app php artisan tinker`, puis `App\Models\Suppression::where('value', 'jean@exemple.fr')->delete()`).

### Mode simulation (`dry_run`) et changement de réglages

- Les réglages d'une campagne **active** s'appliquent tout de suite : le plan du jour (créneaux tirés mais pas encore envoyés) est remis en attente et le passage suivant en tire un nouveau avec les nouveaux réglages (`Planner::invalidate()`). Même chose pour une campagne en pause. Les lignes déjà parties ne bougent pas. Une campagne **terminée** a ses réglages figés.
- **Le mode simulation ne se change pas sur une campagne active** : l'enregistrement est refusé avec la raison. Un clic sur « Enregistrer » ne doit jamais lancer de vrais envois (ni en arrêter) : on met la campagne en pause, on change le mode, puis « Reprendre », dont la confirmation dit si de vrais mails vont partir. Le changement est écrit dans le journal.
- **Les lignes déjà simulées restent « Simulé » quand on coupe la simulation** : elles ne partent pas toutes seules. Elles ont été « consommées » (elles comptent pour le quota, l'écart et le cooldown) et rien n'est parti. Sur une campagne en pause ou en brouillon, le bouton **Remettre en attente** (onglet Réglages) les rend envoyables : rendu, date et identifiant de la simulation sont effacés, et c'est écrit dans le journal. Il est refusé sur une campagne active ou terminée.
- Dans l'autre sens (réel vers simulation), rien de particulier : les lignes déjà envoyées restent envoyées et comptent.

### Export CSV

Le bouton **Exporter en CSV** (en-tête de chaque onglet) et la commande `slowmail:export {campagne} {fichier.csv}` écrivent, dans l'ordre d'envoi : la colonne des adresses (`EMAIL`), les colonnes d'origine des CSV importés (dans leur ordre), puis `STATUT` (en français : « Envoyé », « En attente », « Simulé »…), `DATE_ENVOI` (heure de la campagne ; seulement pour un envoi réel), `REPONSE` (« Répondu », « Stop », « Rendez-vous ») et `NOTES`, réécrites depuis la base. Séparateur `;`, UTF-8 avec BOM : un tableur français l'ouvre sans assistant, et SlowMail le relit (les colonnes de suivi sont ignorées à l'import). Le rendu des mails n'est jamais exporté.

Une cellule qui commence par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot est précédée d'une apostrophe : un tableur ne l'exécute pas comme une formule. Un numéro `+33 6…` s'affiche donc avec son apostrophe dans le fichier, c'est le prix de la protection.

La commande refuse d'écraser un fichier existant sans `--force` et l'écrit en lecture seule pour vous (`0600`). Dans Docker :

```bash
docker compose exec app php artisan slowmail:export "Ma campagne" /tmp/export.csv
docker compose cp app:/tmp/export.csv ./export.csv
```

### Premiers envois, pas à pas

À faire par vous, sur **vos propres adresses** (3 suffisent), avant toute vraie campagne.

1. Renseignez `SLOWMAIL_PASSWORD` et les identifiants OVH dans `.env` (`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`), puis `SLOWMAIL_ENABLED=true`, puis `docker compose up -d --build`. Ouvrez <http://127.0.0.1:8080> : le bandeau doit dire « dernier passage du scheduler : il y a 0 min » en vert au bout d'une minute.
2. **Campagnes** : créez une campagne, puis importez dans l'onglet Destinataires un CSV de vos trois adresses (colonnes `EMAIL`, `PRENOM`, `STRUCTURE`…). Un exemple : `database/examples/prospects-exemple.csv`.
3. Onglet **Modèle** : écrivez l'objet et le corps, avec la mention `stop` (« Pour ne plus recevoir de mails, répondez STOP »). Cliquez sur **« M'envoyer un test »** : c'est le premier vrai envoi, vers votre adresse d'expédition. Vérifiez la réception, l'expéditeur, le `Reply-To`.
4. Onglet **Réglages**, en **simulation** : cochez « Mode simulation », réglez 3 mails par jour, 1 minute d'écart, 0 jour de délai de domaine, une plage qui commence dans quelques minutes (par exemple de l'heure actuelle à une heure plus tard, tous les jours utiles cochés), et enregistrez. Le récapitulatif doit annoncer ce que vous attendez.
5. **Activer** (la confirmation dit « en simulation »). Suivez le bandeau (prochain envoi prévu) et l'onglet **Journal** : en quelques minutes, les trois lignes passent à « Simulé », avec le rendu gardé et rien d'envoyé. Essayez l'**export CSV**, un « Répondu » avec une note sur une ligne, et la liste de suppression.
6. Passez en réel : **Mettre en pause**, onglet Réglages, décochez « Mode simulation », enregistrez, puis **Remettre en attente** (les trois lignes simulées redeviennent « En attente »). Corrigez la plage si elle est passée.
7. **Reprendre** : la confirmation annonce que de vrais mails vont partir. Les trois mails arrivent chez vous, à des heures différentes, un par passage au plus. Vérifiez la boîte de réception (texte brut, `Reply-To`, mention de désinscription) et vos envoyés ; le Journal doit dire « Envoyé » trois fois.
8. Quand tout est bon, créez la vraie campagne : par défaut 4 mails par jour, 9 h 30 à 17 h 30, du lundi au vendredi, montée progressive si vous le souhaitez.

À savoir pendant ces essais :

- **Pour tout arrêter tout de suite** : « Mettre en pause » (la campagne), ou `SLOWMAIL_ENABLED=false` dans `.env` puis `docker compose up -d` (toute campagne, le mail de test compris).
- **« À vérifier dans vos envoyés »** : si le Mac s'éteint pendant un envoi, la ligne devient incertaine et n'est jamais renvoyée seule. Cherchez le mail dans vos envoyés (destinataire, objet, ou l'identifiant affiché), puis « C'est parti » ou « Pas parti ».
- **Le bandeau est rouge** : le scheduler n'a pas tourné depuis plus de 10 minutes (Mac en veille, OrbStack arrêté). Rien ne part pendant ce temps et rien n'est rattrapé en rafale au réveil : les créneaux dépassés sont replanifiés.
- **Une pause « Disjoncteur »** : trois échecs d'envoi de suite (identifiants OVH refusés, par exemple). La raison est affichée en tête de la campagne et dans le Journal ; corrigez, puis « Reprendre ».

`slowmail:campaign` fait la même chose que les boutons Activer, Mettre en pause et Reprendre, en ligne de commande, avec les mêmes refus motivés.

## Commandes

```bash
docker compose cp prospects.csv app:/tmp/prospects.csv
docker compose exec app php artisan slowmail:import "Ma campagne" /tmp/prospects.csv --check   # rapport seul
docker compose exec app php artisan slowmail:import "Ma campagne" /tmp/prospects.csv
docker compose exec app php artisan slowmail:status "Ma campagne"
docker compose exec app php artisan slowmail:campaign "Ma campagne" activate   # ou pause, resume
docker compose exec app php artisan slowmail:suppress jean@exemple.fr
docker compose exec app php artisan slowmail:suppress @exemple.fr --reason=manuel
docker compose exec app php artisan slowmail:purge --days=90
docker compose exec app php artisan slowmail:export "Ma campagne" /tmp/export.csv   # puis docker compose cp app:/tmp/export.csv .
docker compose exec app php artisan slowmail:backup   # copie à chaud de la base dans /data/backups, puis docker compose cp app:/data/backups/. ./sauvegardes
```

Options de `slowmail:import` : `--email-column=`, `--include-contacted`, `--check`.

`slowmail:status` affiche le battement du scheduler (dernier passage, en rouge au-delà de 10 min), les envois désactivés, les lignes incertaines, le prochain envoi prévu, la raison d'une pause, puis l'avancement, le récapitulatif des réglages, le plan d'envoi jour après jour (les heures du jour sont exactes une fois tirées, celles des jours suivants sont tirées le jour même) et la fin estimée au rythme actuel. `--days=` règle le nombre de jours affichés (14 par défaut). Il n'écrit rien.

`slowmail:suppress` ajoute une adresse ou un domaine entier (`@exemple.fr`) à la liste de suppression (motif `stop` par défaut, ou `bounce`, `manuel`) et retire aussitôt de l'envoi les lignes en attente ou planifiées qui correspondent, dans toutes les campagnes.

`slowmail:export` écrit le CSV d'une campagne (colonnes d'origine, puis `STATUT`, `DATE_ENVOI`, `REPONSE`, `NOTES`) ; `--force` écrase un fichier existant. Voir [« Export CSV »](#export-csv).

`slowmail:purge` efface les rendus et les données du CSV des campagnes terminées depuis plus de N jours (90 par défaut). Restent l'adresse, le statut, les dates et les notes : de quoi savoir qui a déjà été contacté. À lancer à la main.

`slowmail:tick` est celui du scheduler ; le lancer à la main est sans danger (même verrou, mêmes règles).

## Développement

```bash
composer install && npm install && npm run build
php artisan migrate
php artisan test          # Pest, sans aucun envoi réel (MAIL_MAILER=array forcé)
```

PHP 8.3 minimum. Pour tester sur la version de l'image :

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli-bookworm vendor/bin/pest
```

### Critères d'acceptation (§14 de la spec)

| Critère | Couvert par |
|---|---|
| `php artisan test` passe sans aucun envoi réel | `NoRealMailTest` (`MAIL_MAILER=array` forcé, kill switch fermé par défaut) ; les tests d'envoi passent par un transport de test (`ScriptedTransport`) |
| Avec `daily_max = 4`, jamais plus de 4 mails par jour, dans la plage, jamais deux à moins de `min_gap_minutes` | `SimulationTest` (trois semaines minute par minute), `PlannerTest` |
| Un redémarrage ne produit ni doublon ni envoi en rafale | `PlannerTest` (« ne retire pas le plan du même jour, même après un redémarrage »), `TickRunnerTest` (un mail par tick, deux exécutions simultanées, crash en plein envoi), `SimulationTest` (veilles aléatoires, arrêt de trois jours) ; essai à la main avec `scripts/smtp-sink.py` et un `kill -9` en plein envoi (phase 4) |
| Une adresse en liste de suppression ne reçoit jamais de mail, même ajoutée après l'activation | `DeliveryTest` (« retire au moment de l'envoi une adresse ajoutée à la liste de suppression après la planification »), `SuppressionsTest`, et par l'interface `SuppressionWebTest`, `RecipientsWebTest` (Stop) |
| Une campagne sans la mention de désinscription ne s'active pas | `CampaignLifecycleTest`, et par le bouton `CampaignLifecycleWebTest` (le refus s'affiche tel quel) |
| `SLOWMAIL_ENABLED=false` bloque tout envoi immédiatement | `TickRunnerTest` (« le kill switch bloque l'envoi aussitôt, même pour une ligne déjà due »), `StatusBannerTest` (l'avertissement s'affiche sur chaque page) |
| Aucun identifiant SMTP dans le code, les logs ou l'interface | `SecretScrubberTest`, `DeliveryTest` (refus d'authentification), `InterfaceSecretsTest` (aucun écran n'affiche l'identifiant, ni le corps d'un mail, même si une erreur ancienne l'a gardé) |

Les écrans ont leurs propres tests de fonctionnalité, mot de passe compris : `InterfaceAccessTest` parcourt **toutes** les routes de l'application et vérifie qu'aucune n'est accessible sans connexion.

**Répétition générale sans OVH** : `scripts/smtp-sink.py` est un faux serveur SMTP local qui répond comme un vrai (accepté, adresse inconnue, « plus tard », refus après `DATA`, connexion coupée après la fin du message, lenteur). Une adresse qui commence par `bounce`, `temp`, `rejectdata`, `dropafter` ou `slow` déclenche le comportement correspondant.

```bash
python3 scripts/smtp-sink.py 2525 /tmp/sink.jsonl
# .env : MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=2525 MAIL_SCHEME=smtp MAIL_USERNAME= MAIL_PASSWORD=
```

## Confidentialité

- Données minimales : adresses, colonnes du CSV, statuts.
- Aucun corps de mail ni identifiant SMTP dans les journaux ; `APP_DEBUG=false` pour qu'une page d'erreur n'affiche jamais l'environnement.
- L'interface exige `SLOWMAIL_PASSWORD` ; s'il est vide, personne ne peut se connecter.
