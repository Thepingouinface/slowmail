# SlowMail

Outil mono-utilisateur d'envoi de mails de prospection au compte-gouttes. Laravel 13, PHP 8.3, Blade + Tailwind (Vite), SQLite, Pest. Interface et messages en français.

## Sources de vérité

- `SPEC-slow-mail.md` : la spécification et le plan en 5 phases (une phase par session).
- `SPEC-addendum-mac.md` : l'app tourne sur un Mac (veille, extinction, OrbStack arrêté). **Il prime sur la spec** là où ils divergent : pas de worker de file, envoi synchrone dans `slowmail:tick`, statut `uncertain`, aucun rattrapage en rafale.
- Principe directeur : en cas de doute, **ne rien envoyer** plutôt que d'envoyer en double ou en rafale.

## Avancement

- Phase 1 Socle : faite (Docker, migrations, mot de passe, import CSV avec rapport).
- Phase 2 Modèle : faite (`app/Services/Template/`, onglet Modèle, `ProspectMail`, mail de test).
- Phase 3 Planification : faite (`app/Services/Planning/`, `slowmail:status`, simulation sur 3 semaines).
- Phase 4 Envoi : faite (`app/Services/Sending/`, `slowmail:tick`, `slowmail:suppress`, `slowmail:campaign`, `slowmail:purge`). Vérifiée avec un faux serveur SMTP (`scripts/smtp-sink.py`), des ticks simultanés et un `kill -9` en plein envoi. **Pas encore essayée avec un vrai SMTP OVH** : c'est le premier geste de l'utilisateur.
- Phase 5 Interface : faite (voir « Phase 5 : l'interface » plus bas). Vérifiée dans le navigateur avec `scripts/smtp-sink.py` : parcours en `dry_run`, puis envois réels vers le faux serveur (envoyé, incertain, rebond, 4xx, 5xx, disjoncteur). **Le parcours sur 3 vraies adresses via OVH reste à faire par l'utilisateur** : marche à suivre dans le `README.md` (« Premiers envois, pas à pas »).
- Phase 6 Distribution à la communauté : faite côté code (voir « Distribution : site, image publique, licence » plus bas). Publié le 27/10/2026 : dépôt public `Thepingouinface/slowmail`, site sur `https://thepingouinface.github.io/slowmail/` (workflow « Site » vert, pages et lien de téléchargement vérifiés en ligne). **Reste à faire par l'utilisateur** : étiquette `v1.0.0` (lance le workflow « Image », jamais exécuté), puis paquet GHCR en **public**, puis `docker logout ghcr.io && docker pull ghcr.io/thepingouinface/slowmail:latest` pour vérifier. Le commit est signé avec l'adresse `noreply` de GitHub (réglage local au dépôt, pas la configuration git globale).

## Phase 4 : comment l'envoi fonctionne

`TickRunner::run()` (appelé par `slowmail:tick`, chaque minute, via `routes/console.php`) : verrou de cache `slowmail:tick` → battement dans `scheduler_state` → « interruption détectée » si > 15 min → les lignes `sending` depuis > 10 min passent en `uncertain` → kill switch coupé : on s'arrête là → pour chaque campagne **active**, `Planner::tick()` puis, si `Send`, `DeliveryService::deliver()`. **Un mail au plus par tick**, toutes campagnes confondues ; une ligne écartée (supprimée, valeur vide) n'est pas un envoi, on regarde la suivante.

`DeliveryService::deliver()` revérifie juste avant l'envoi : kill switch, liste de suppression (`Suppressions::matches`), modèle (`canActivate`), variables vides (→ `skipped`, raison dans `last_error`, ne compte pas dans le quota), `dry_run` (statut `dry_run`, `sent_at` posé : compte comme un envoi pour quota/écart/cooldown/palier), adresse d'expédition. Puis `claim()` : `planned` → `sending` avec `Message-ID`, heure et rendu, en **une écriture conditionnelle validée avant l'appel SMTP**. Si elle ne touche aucune ligne, une autre exécution l'a prise (`Lost`) : on n'envoie pas.

`SendFailureClassifier` lit l'exception de Symfony Mailer : le code de réponse du serveur (une panne réseau n'en a pas) et le journal de conversation (`getDebug()`).
- Pas de réponse + « . » final **non** écrit → `Network` : la ligne redevient `planned`, aucune tentative consommée, disjoncteur intact, retentée au tick suivant (le planificateur la replanifie après la tolérance). Une ligne de journal `network` tant que la panne dure.
- Pas de réponse + « . » final écrit (ou erreur inconnue) → `Uncertain` tout de suite. Jamais renvoyé seul.
- 4xx → `Temporary` : 3 tentatives en tout, à une heure d'écart (`planned_at` = maintenant + 60 min). 5xx → `Permanent` : `failed`. Les deux comptent pour le **disjoncteur** (`campaigns.consecutive_failures`, 3 de suite → pause avec `pause_reason`) ; un envoi réussi le remet à zéro.
- 5xx en réponse à `RCPT TO` → `RecipientRejected` : `bounced` + adresse ajoutée à la liste de suppression (motif `bounce`), hors disjoncteur. Adresse mal formée → `failed`, hors disjoncteur.

Autres services (`app/Services/Sending/`) : `CampaignLifecycle` (activate/pause/resume/finish, refus motivés), `Suppressions` (matches/add, effet immédiat sur les lignes en attente de toutes les campagnes), `RecipientActions` (markReply, confirmSent « C'est parti », notSent « Pas parti »), `SchedulerStatus::snapshot()` (bandeau), `Purger`, `Journal` (écrire dans `send_logs`).

Événements du journal (libellés et couleurs dans `App\Enums\JournalEvent`) : `plan`, `replan`, `suppressed`, `skipped`, `dry_run`, `sent`, `retry`, `failed`, `bounced`, `uncertain`, `network`, `interruption`, `activated`, `paused`, `circuit_breaker`, `resumed`, `finished`, `resolved`, `reply`, `purge`, `test`, `test_failed`, `import`, et depuis la phase 5 `settings` (réglages modifiés) et `simulation_reset` (lignes simulées remises en attente). Un événement ajouté se déclare dans l'enum, sinon l'écran l'affiche tel quel.

Une campagne ne se termine pas tant qu'une ligne est `sending` ou `uncertain` (sinon « Pas parti » remettrait la ligne en attente dans une campagne finie).

## Phase 5 : l'interface

Les écrans sont une peau : un contrôleur par écran dans `app/Http/Controllers/`, qui appelle les services existants et ne réimplémente aucune règle. Tout est derrière `RequireSlowmailPassword` (`routes/web.php`).

| Écran / action | Contrôleur | Service appelé |
|---|---|---|
| Liste (statut, avancement, prochain envoi) | `CampaignController@index` | `PlanProjector::project()` (horizon 21 jours, premier jour prévu) |
| Destinataires (filtre statut, recherche, encadré `uncertain`) | `CampaignController@show` | — |
| Activer / Mettre en pause / Reprendre | `CampaignLifecycleController` | `CampaignLifecycle` (le refus rend la liste des raisons, flashée dans `lifecycle_problems`, affichée par l'en-tête) |
| Répondu / Rendez-vous / Stop, « C'est parti » / « Pas parti » | `RecipientController` (routes à liaison scopée : la ligne doit appartenir à la campagne de l'URL) | `RecipientActions` |
| Réglages | `CampaignSettingsController` | `CampaignSettings::fromArray()` / `violations()` / `toArray()` / `summary()`, `Planner::invalidate()`, `PlanProjector` (fin estimée) |
| Journal | `CampaignJournalController` | `send_logs` (tri par `id` décroissant, pas par date : plusieurs événements par seconde) |
| Export CSV | `CampaignExportController`, `slowmail:export` | `Services/Export/CsvExporter` |
| Liste de suppression | `SuppressionController` | `Suppressions::add()` |
| Bandeau d'état (toutes les pages, pas la connexion) | composant `App\View\Components\StatusBanner` | `SchedulerStatus::snapshot()` |

Décisions à connaître :
- **`dry_run`** : refusé tant que la campagne est **active** (un enregistrement ne doit ni lancer de vrais envois ni les arrêter) ; le formulaire d'une campagne active renvoie le mode actuel dans un champ caché. Les lignes `dry_run` **restent** `dry_run` quand on coupe la simulation (elles ne partent pas toutes seules) ; `RecipientActions::releaseSimulated()` (bouton « Remettre en attente », campagne en brouillon ou en pause seulement, `DomainException` sinon) les remet `pending` en effaçant rendu, dates et identifiant. L'avancement de la liste compte les lignes simulées à part.
- Réglages modifiés : rien n'est enregistré si `violations()` n'est pas vide ; sur une campagne active **ou en pause**, `Planner::invalidate()` remet en attente le plan du jour ; une campagne terminée est en lecture seule. `start_at` / `end_at` se saisissent à l'heure du fuseau du formulaire et se stockent en UTC (`->utc()` avant d'écrire).
- **Pas de retrait de la liste de suppression dans l'interface**, volontairement (un clic malheureux ferait repartir des mails). Retrait à la main en base.
- Les boutons qui ne se défont pas (Activer, Reprendre, « Pas parti », Stop, Remettre en attente) portent un `data-confirm`, géré par `resources/js/app.js` (pas d'Alpine : aucune dépendance front en plus). Après un bouton, `redirect()->back(fallback: …)` ramène sur l'onglet d'origine.
- Affichage des heures : jamais `->format()` sur une date UTC. `App\Support\LocalTime` (français, fuseau de la campagne, ou `SLOWMAIL_TIMEZONE` hors campagne, `Europe/Paris` par défaut).
- Ce qui vient de la base et peut contenir une erreur SMTP (`last_error`, `pause_reason`, détail du journal) repasse par `SecretScrubber::scrub()` **à l'affichage**, en plus de l'écriture. Jamais `rendered_body` dans une vue ; l'objet rendu et le `Message-ID` ne s'affichent que pour les lignes `uncertain` (pour les retrouver dans les envoyés).
- Export : `CsvExporter::protect()` préfixe d'une apostrophe toute cellule qui commence par `=`, `+`, `-`, `@`, tabulation ou retour chariot (en-têtes compris). L'adresse est exportée sous `EMAIL` (le nom de la colonne d'origine n'est pas gardé ; `ADRESSE` si une colonne `EMAIL` existe déjà). `DATE_ENVOI` seulement pour un envoi réel (`sent`), `fputcsv(..., escape: '')`.

Pour un nouvel écran, les briques à réutiliser :
- Rendre un message : `MessageComposer::compose($campaign, $recipient, $at)`. `$at` est le moment réel de l'envoi (il décide de `[DATE]`). Le résultat dit `missing` (variable vide sans défaut) et `problems` (modèle inutilisable). Il ne modifie rien.
- Valider un modèle : `TemplateValidator::validate($campaign)` : `canRender()`, `canActivate()`, `errors`, `blockers`, `warnings`.
- Erreurs destinées à l'interface, au journal ou à `last_error` : toujours `App\Support\SecretScrubber::scrub()`.
- Réglages d'une campagne : `CampaignSettings::for($campaign)`. `Campaign::timezone()` retombe sur Europe/Paris si le fuseau est inconnu.
- Composants Blade : `<x-pill tone="ok|warn|error|info|neutral">`, `<x-alert tone="…">`, `<x-campaign-header :campaign active="…">` (statut, boutons, alertes, onglets). `RecipientStatus::tone()` et `CampaignStatus::tone()` donnent la couleur.

## Distribution : site, image publique, licence

Choix de l'utilisateur : licence **AGPL-3.0-or-later** (`LICENSE`, `composer.json`, étiquette OCI de l'image) et **image sur un registre** (GHCR, `ghcr.io/<propriétaire>/<dépôt>`). SlowMail reste mono-utilisateur : le « site » est une vitrine avec un tutoriel, chacun installe sa copie, pas un service partagé.

- `site/` : pages statiques en français (`index`, `tutoriel`, `premiers-envois`, `bon-usage`, `depannage`), `assets/style.css` (clair/sombre, sans dépendance), `assets/site.js` (choix Mac/Windows/Linux mémorisé, boutons « Copier », ouverture d'une question du dépannage par son ancre ; tout reste lisible sans JavaScript). Liens **relatifs** et pages à plat : GitHub Pages sert un dépôt sous `/<dépôt>/`.
- Marqueurs `__REPO__`, `__REPO_LC__`, `__SITE_URL__` dans `site/` ; `scripts/build-site.sh propriétaire/dépôt` écrit `dist/` (ignoré par git) et échoue s'il en reste. Ne jamais les remplacer dans `site/`. Le fichier à télécharger doit passer par le build : `raw.githubusercontent.com` servirait les marqueurs tels quels.
- `site/telecharger/docker-compose.yml` : un seul fichier pour l'utilisateur, un seul service (`SLOWMAIL_ROLE=all`), `name: slowmail` (le volume s'appelle `slowmail_slowmail-data` quel que soit le dossier), port sur `127.0.0.1`, mots de passe **vides** (pas de mot de passe par défaut connu), `SLOWMAIL_ENABLED: "false"`. Testé avec l'image locale sur un autre port et un autre nom de projet (`COMPOSE_PROJECT_NAME`, jamais celui de l'utilisateur) : sain en ~1 min, connexion, sauvegarde, restauration.
- `.github/workflows/image.yml` (étiquette `v*` : tests puis image `linux/amd64` + `linux/arm64`, nom en minuscules ; une étiquette avec un tiret, `v0.0.1-test` ou `v1.1.0-rc1`, est une préversion qui ne reçoit ni `latest` ni `X.Y`) et `pages.yml` (publie `dist/`). `pages.yml` a tourné (vert, après activation de Pages avec la source « GitHub Actions » : sans cela `configure-pages` échoue) ; `image.yml` n'a **jamais été exécuté**. Les deux premières étapes du `Dockerfile` sont en `--platform=$BUILDPLATFORM` (fichiers seulement) : seule l'étape finale tourne sous émulation pour arm64.
- `.dockerignore` exclut `site`, `.github`, `dist` ; `LICENSE` reste dans l'image.
- Les messages de l'application ne parlent plus du « .env » mais de « la configuration » (un utilisateur du Compose n'a pas de `.env`).
- `slowmail:backup` (`DatabaseBackup`) : `VACUUM INTO` par une connexion PDO à part (VACUUM refuse une transaction), `/data/backups/slowmail-AAAAMMJJ-HHMMSS.sqlite` en `0600`, refuse d'écraser. Copier hors du conteneur : `docker compose cp slowmail:/data/backups/. ./sauvegardes` (le `/.` marche que le dossier existe ou non). **Restauration** : `docker cp` crée des fichiers root, donc il faut `chown www-data` (`run --user root --entrypoint sh`) et retirer `-wal`/`-shm` ; procédure dans le README et la page dépannage, essayée de bout en bout.
- Captures du site : l'application sur une base à part (`DB_DATABASE=…`, `MAIL_MAILER=array`, données fictives en `.example`), pages enregistrées avec `curl` (cookie de session) puis rendues par Brave en mode headless via le protocole DevTools (Node ≥ 22, `WebSocket` natif ; `Emulation.setScrollbarsHidden`, facteur 2). Firefox headless refuse le profil (« Could not find profile folder »), Brave sans port de débogage ne produit pas de fichier.
- Texte du site : ne rien promettre que l'application ne fait pas. Vérifié : en-tête `List-Unsubscribe: <mailto:expéditeur>` présent, pas de lien de désinscription, l'application ne lit pas la boîte, bandeau « Envois désactivés » en orange, limite de 5 essais par minute sur la connexion. Le cadre légal de `bon-usage.html` est un rappel général (France/UE), pas un avis juridique.

## Écarts assumés par rapport au §9 de la spec

- `recipients.position` : ordre d'envoi (ordre du CSV ou `PRIORITE`).
- `recipients.sending_started_at` : addendum §3.
- `RecipientStatus::Uncertain` : addendum §3.
- `send_logs.campaign_id` (obligatoire) et `recipient_id` nullable : pour les événements de campagne (import, « interruption détectée »).
- `campaigns.planned_for` (date) : dernier jour, en heure locale de la campagne, dont le plan est tiré. Réclamé par un `UPDATE … WHERE planned_for != jour` (atomique) : c'est ce qui empêche un second tick ou un redémarrage de retirer les heures.
- `campaigns.columns` (JSON) : colonnes des CSV importés, fusionnées à chaque import sans doublon de casse. Ce sont les variables que le modèle peut utiliser.
- `campaigns.consecutive_failures` (disjoncteur), `campaigns.pause_reason` (pour le bandeau), `campaigns.finished_at` (pour `slowmail:purge`).
- Table `scheduler_state` (clé/valeur) : `last_tick_at` et `next_send_at`, partagés entre le scheduler et l'interface. Écrite par upsert atomique.
- Pas de table `users` ni de file : `QUEUE_CONNECTION=sync`, la table `sessions` garde `user_id` car le gestionnaire de session l'écrit.

## Règles

- Aucun envoi réel dans les tests : `phpunit.xml` force `MAIL_MAILER=array`. Jamais d'identifiants SMTP dans le code, les journaux, les messages d'erreur ni l'interface.
- En cas de doute, **ne pas renvoyer** : une erreur inconnue pendant l'envoi, ou un « . » final parti sans réponse, rend la ligne `uncertain`, que seul l'utilisateur tranche. Jamais de retry automatique sur un envoi qui a pu aboutir.
- Pas de `report()` d'une exception SMTP : son message peut citer l'identifiant ou une adresse. L'erreur utile va au journal de la campagne, passée par `SecretScrubber`.
- Heures stockées en UTC (`now()`), plage horaire évaluée dans le fuseau de la campagne. Pas de compteur de ticks.
- Tout est derrière `RequireSlowmailPassword`, qui passe avant le binding de modèle (sinon un visiteur déconnecté distingue un 404 d'une redirection). Sans `SLOWMAIL_PASSWORD`, personne n'entre.
- `SLOWMAIL_ENABLED` vaut `false` par défaut. Il bloque aussi le mail de test (c'est un vrai envoi).
- Modèle : `[DATE]` et `[EMAIL]` l'emportent sur une colonne du CSV de même nom ; `[X|]` est un défaut explicitement vide (la ligne part) ; la mention de désinscription se cherche dans le texte fixe du corps seulement ; les valeurs du CSV ne sont jamais relues comme du modèle.
- `MAIL_FROM_ADDRESS` n'a plus de valeur par défaut (`config/mail.php`) : une adresse oubliée doit se voir, pas partir en `hello@example.com`.

## Pièges rencontrés

- PHP local en 8.5, image en 8.3 : `composer.json` fixe `config.platform.php` à 8.3.0. Avant de clore une phase, lancer les tests sur 8.3 : `docker run --rm -v "$PWD":/app -w /app php:8.3-cli-bookworm vendor/bin/pest`.
- `TestCase` appelle `withoutVite()` : pas de build front pendant les tests.
- Dans Docker, `php artisan serve` doit avoir `--no-reload`, sinon il masque les variables d'environnement du conteneur.
- `app` et `scheduler` partagent le même fichier SQLite (volume nommé, `/data`) : WAL et `busy_timeout` sont réglés dans `config/database.php`.
- **Image Docker (une seule, Alpine, ≈ 200 Mo)** : `docker/run.sh` lance ce que `SLOWMAIL_ROLE` demande : `all` (défaut : interface + scheduler dans le même conteneur, le premier qui tombe fait tomber le conteneur avec le code 1 pour que Docker le relance ; `docker stop` sort avec 0), `web` (migre puis sert), `scheduler`. `docker-compose.yml` garde les deux services de l'addendum (rôles `web` et `scheduler`). `docker/entrypoint.sh` prépare `/data` (base, clé d'application) et ne migre pas. `tini` est PID 1. `HEALTHCHECK` = `docker/healthcheck.php`, selon le rôle : `/up` répond, et/ou `scheduler_state.last_tick_at` a moins de 5 minutes. Jamais deux conteneurs `all`/`scheduler` sur la même base.
- **uid 33** : `www-data` a l'uid/gid 33 dans l'image Alpine (comme sous Debian, où l'image a été publiée d'abord ; Alpine met 82 et réserve 33 à `xfs`). Sans cela, un volume `/data` créé par une ancienne image donne « attempt to write a readonly database » et le conteneur redémarre en boucle. Ne pas changer cet uid, ni la base de l'image, sans y repenser. Les sondes et `docker exec` tournent en `www-data` (instruction `USER`) : un `docker exec -u root` qui ouvre la base crée des fichiers `-wal`/`-shm` appartenant à root et la casse pour les autres.
- Dans l'image : `view:cache` oui, **`config:cache` non** (il figerait l'environnement du conteneur, et les variables d'exécution ne compteraient plus). `MAIL_MAILER=smtp` par défaut : le transport `log` écrirait le corps des mails dans les journaux. `display_errors=Off`, `expose_php=Off` (`docker/php.ini`). Le serveur web (`artisan serve`) ne journalise que le chemin de chaque requête, jamais la chaîne de recherche (`?q=` peut contenir une adresse).
- L'image n'a pas besoin de la base de l'image Debian pour tourner, mais la **suite de tests a été validée sur `php:8.3-cli-alpine`** (musl, iconv différent) : `docker run --rm -v "$PWD":/app -w /app php:8.3-cli-alpine vendor/bin/pest`.
- Sauvegarde à chaud de la base : `VACUUM INTO` (pas de copie du fichier pendant que le WAL est actif). Commandes dans le README.
- Tailwind scanne les vues de pagination de `vendor/` : l'image construit le front après avoir installé les dépendances PHP.
- Tests de session : `withSession()` et `assertSessionHasErrors()` effacent les erreurs flashées par la requête précédente. Pour vérifier qu'une erreur s'affiche sur la page suivante, enchaîner `$this->post(...)` puis `$this->get(...)` sans l'un ni l'autre.
- Langue : `APP_LOCALE=fr`, `APP_FALLBACK_LOCALE=en`. `lang/fr/validation.php` ne traduit que les règles utilisées ; toute autre règle retombe sur l'anglais du framework. Sans cela, un message sans traduction s'affiche en clé brute (`validation.integer`). Ajouter la traduction française de toute nouvelle règle.
- Une nouvelle classe Tailwind exige `npm run build` pour apparaître en local (l'image Docker recompile toute seule).
- Le `Message-ID` n'est posé par Symfony qu'à la préparation du message : dans un test, lire `getPreparedHeaders()`.
- Dates en base : Eloquent et le query builder écrivent un `Carbon` avec **son propre fuseau**, sans le convertir. Toute date écrite ou comparée doit être passée en UTC d'abord (`->utc()`), sinon l'heure locale est stockée comme si elle était UTC. Le `Planner` passe par `sql()`.
- Les écarts en jours (cooldown de domaine, jours d'envoi de la montée progressive) ne se comptent ni en jours UTC ni en multiples de 24 h : `DispatchHistory::dayNumber()` numérote les jours du calendrier local, insensible aux changements d'heure.
- Tests lourds : les simulations sur plusieurs semaines sont dans le groupe `simulation` (`php artisan test --exclude-group=simulation` pour une boucle rapide).
- Tests d'interface : les fonctions de test sont **globales** et partagées entre fichiers : un même nom dans deux fichiers est une erreur fatale au chargement (`uncertainRow` existait déjà dans `RecipientActionsTest`). Les helpers d'interface sont dans `tests/InterfaceHelpers.php` (`uncertainSend`, `logEntry`, `settingsForm`, `parseCsv`).
- `assertSee()` échappe le texte attendu (`'` devient `&#039;`) mais pas le texte statique d'un gabarit : une phrase avec une apostrophe écrite dans le Blade échoue. Utiliser `->assertShows($texte)` (macro de `tests/Pest.php` : texte visible, entités décodées, espaces réduits) ou `assertSee($texte, false)`.
- Un exemple de saisie (`placeholder="jean@exemple.fr"`) peut faire échouer un `assertDontSee('jean@exemple.fr')` : garder les exemples des gabarits distincts des données de test.
- `tests/Unit` ne démarre pas l'application (pas de config, pas de locale Carbon) : un test qui en a besoin va dans `tests/Feature`.
- Erreurs de session dans un test : `$response->baseResponse->getSession()->get('errors')`, pas `session('errors')`.
- `where('col', 'like', …)` sur SQLite n'a pas de `ESCAPE` : échapper `%` ou `\\` n'a aucun effet, ne pas s'y fier.
- Tests de commandes : `expectsOutputToContain()` consomme une écriture par attente, donc deux attentes sur la même ligne se contredisent. Pour plusieurs vérifications sur une même sortie, appeler `Artisan::call()` puis lire `Artisan::output()`.
- Rapport JSON de Pest : les exceptions sont sous `error_details`, pas sous `failures`. Lire les deux, sinon un test en erreur passe pour réussi.
- Les helpers `recipient()` des tests numérotent leurs `position` globalement d'un test à l'autre : ne pas écrire de numéro de ligne en dur dans un test.
- Tests d'envoi : `sendingEnvironment()` branche un `ScriptedTransport` (comme le transport `array`, mais le test peut le faire échouer avec `failWith()` : `smtpReply()`, `connectionFailure()`, `smtpTranscript()` fabriquent les exceptions de Symfony avec leur journal de conversation). `tickAt('2026-10-19 10:01:00')` lance un vrai tick à cette heure. `sendingCampaign()` / `dueRow()` posent une campagne active et un créneau dû.
- `range(1, 0)` vaut `[1, 0]` en PHP : ne jamais boucler sur `range(1, $n)` quand `$n` peut être 0.
- Essayer l'interface avec des données réelles sans toucher à la vraie base : une base à part (`DB_DATABASE=/chemin/ui.sqlite php artisan migrate`), les variables d'environnement `SLOWMAIL_PASSWORD`, `SLOWMAIL_ENABLED=true`, `MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=2525 MAIL_SCHEME=smtp MAIL_USERNAME= MAIL_PASSWORD=` et `MAIL_FROM_ADDRESS`, puis `php artisan serve --no-reload --port=8090`, `php artisan schedule:work` (le vrai scheduler) et `python3 scripts/smtp-sink.py 2525 sink.jsonl`. Une campagne se crée depuis l'interface, son CSV avec `slowmail:import`. Pour obtenir des envois dans les minutes qui suivent, régler la plage d'envoi de l'onglet Réglages sur « maintenant → dans 20 min », 1 minute d'écart, 0 jour de délai de domaine. Parcours fait : simulation, remise en attente, envoi réel vers le faux serveur (envoyé, `dropafter` → incertain → « C'est parti », `bounce` → rebond et liste de suppression, `rejectdata` ×3 → disjoncteur → « Reprendre »), export, largeur mobile. Penser à arrêter ces processus ensuite.
- Le test le plus proche du réel est `scripts/smtp-sink.py` (faux serveur SMTP : adresse inconnue, « plus tard », refus après `DATA`, coupure après le « . », lenteur). Il a trouvé deux défauts que les tests unitaires ne voyaient pas : un `updateOrInsert` non atomique (deux premiers ticks simultanés) et une campagne qui se terminait avec un envoi incertain en attente. Relancer ces essais après toute modification de `DeliveryService` ou de `TickRunner`.
- Tester les tests : casser volontairement une règle (`if (false)`, condition retirée) et vérifier qu'un test échoue. Après avoir cassé une **vue Blade**, faire `php artisan view:clear` en la restaurant : la vue compilée du mutant peut survivre (mtime à la seconde près) et faire échouer, ou réussir, les tests suivants à tort. Elle est aussi partagée avec un `php artisan serve` qui tournerait en même temps. Ne regarder que `failures` dans le rapport de Pest cache les tests en erreur.
- La validation d'adresse refuse les accents dans la partie locale (SMTPUTF8 non pris en charge) : c'est voulu, la raison est affichée à l'import.

## Commandes

- `php artisan test` (Pest). `./vendor/bin/pint` pour le style.
- `docker compose up -d --build` : interface sur `127.0.0.1:8080` (deux services). Ou, en un seul conteneur : `docker build -t slowmail:latest .` puis `docker run -d --name slowmail -p 127.0.0.1:8080:8000 -v slowmail-data:/data --env-file .env slowmail:latest`.
- `php artisan slowmail:import {campagne} {fichier.csv} [--check] [--include-contacted] [--email-column=]`.
- `php artisan slowmail:export {campagne} {fichier.csv} [--force]` : CSV avec les colonnes d'origine puis `STATUT`, `DATE_ENVOI`, `REPONSE`, `NOTES` (fichier en `0600`, refuse d'écraser sans `--force`).
- `php artisan slowmail:status {campagne} [--days=14]` : battement du scheduler, avancement, plan, fin estimée.
- `php artisan slowmail:tick` : un passage (le scheduler l'appelle chaque minute). `slowmail:campaign {campagne} {activate|pause|resume}`. `slowmail:suppress {adresse|@domaine} [--reason=stop]`. `slowmail:purge [--days=90]`.
