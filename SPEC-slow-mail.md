# SPEC — Slow Mail (envoi de mails de prospection au compte-gouttes)

## 1. Objectif

Outil **mono-utilisateur** qui envoie des mails personnalisés **lentement** (quelques-uns par jour), à partir d'un CSV et d'un modèle de texte contenant des variables entre crochets.

- L'utilisateur importe un CSV, écrit (ou colle) son modèle, règle le rythme, active la campagne.
- L'outil envoie les mails un par un, à des heures variables, dans une plage horaire, et s'arrête tout seul quand c'est fini.
- Il ne fait **ni suivi d'ouverture, ni suivi de clics** (pas de pixel, pas de lien réécrit).

## 2. Contraintes techniques

- Laravel 12/13, PHP 8.3, Docker Compose (app, worker, scheduler, base). SQLite ou Postgres au choix, via `.env`.
- Blade + Tailwind pour l'interface (pas de SPA). Alpine.js autorisé pour les petites interactions.
- Envoi par le **mailer SMTP standard de Laravel** (OVH), configuré uniquement dans `.env`. Aucun identifiant dans le code, les logs ou les messages d'erreur.
- Tests : Pest, `Mail::fake()`, `Carbon::setTestNow()`. Aucun envoi réel dans les tests (`phpunit.xml` avec `MAIL_MAILER=array` en `force="true"`).
- Interface protégée par un mot de passe unique (`SLOWMAIL_PASSWORD` dans `.env`), jamais exposée sans lui. Écoute sur `127.0.0.1` par défaut.
- Kill switch global : `SLOWMAIL_ENABLED=false` bloque tout envoi.

## 3. Modèle de texte

Deux champs : **objet** et **corps** (texte brut, pas de HTML).

Syntaxe :

| Écriture | Effet |
|---|---|
| `[STRUCTURE]` | remplacé par la valeur de la colonne `STRUCTURE` du CSV |
| `[STRUCTURE\|votre structure]` | valeur par défaut si la colonne est vide |
| `\[texte\]` | crochets littéraux, non remplacés |

Règles :
- Le nom de variable est insensible à la casse et aux espaces autour.
- Une variable **inconnue** (colonne absente du CSV) bloque l'activation de la campagne, avec la liste des variables en cause.
- Une variable **vide sans valeur par défaut** met la ligne en statut `skipped` avec la raison. Rien n'est envoyé.
- Variables toujours disponibles, en plus des colonnes du CSV : `[DATE]` (jour de l'envoi, format `21 octobre 2026`) et `[EMAIL]`.
- **Garde-fou opt-out** : l'activation est refusée si le corps ne contient pas la chaîne configurée `SLOWMAIL_OPTOUT_TEXT` (défaut : `stop`), avec un message clair.
- Aperçu : on choisit une ligne du CSV et on voit objet + corps rendus. Bouton « m'envoyer un test » (envoi réel à l'adresse de l'expéditeur, avec les données de la ligne choisie, sans toucher au statut de la ligne).

## 4. CSV

- Import par envoi de fichier. Détection du séparateur (`;` ou `,`), UTF-8 avec ou sans BOM.
- Colonne **`EMAIL` obligatoire** (le nom de la colonne est configurable à l'import). Les autres colonnes deviennent des variables.
- Validation à l'import, avec un rapport (lignes importées, rejetées, raisons) :
  - adresse invalide ;
  - **doublon** dans le fichier ;
  - adresse présente dans la liste de suppression (voir §7) ;
  - adresse déjà contactée dans une autre campagne (avertissement, import possible sur confirmation).
- L'ordre du CSV est l'ordre d'envoi, sauf si une colonne `PRIORITE` (nombre, plus petit = plus tôt) est présente.
- Colonnes de suivi optionnelles réécrites à l'export : `STATUT`, `DATE_ENVOI`, `REPONSE`, `NOTES`.

## 5. Options de planification (par campagne)

| Option | Défaut | Détail |
|---|---|---|
| `daily_max` | 4 | nombre maximum de mails par jour |
| `window_start` / `window_end` | 09:30 / 17:30 | plage horaire d'envoi |
| `timezone` | Europe/Paris | fuseau de la plage |
| `days` | lun–ven | jours autorisés |
| `skip_holidays` | oui | pas d'envoi les jours fériés français |
| `min_gap_minutes` | 25 | écart minimum entre deux mails |
| `jitter` | oui | heures aléatoires dans la plage, jamais pile sur l'heure ronde |
| `start_at` | maintenant | date de début |
| `end_at` | aucune | date de fin (la campagne s'arrête même s'il reste des lignes) |
| `max_total` | aucun | arrêt après N mails au total |
| `ramp_up` | non | montée progressive : départ à `ramp_start` mails/jour (défaut 2), +`ramp_step` (défaut 1) tous les `ramp_every_days` jours (défaut 3), plafonné à `daily_max` |
| `domain_cooldown_days` | 7 | jamais deux mails au **même domaine** à moins de N jours d'écart (évite d'écrire à dix agences d'un même réseau le même jour) |
| `dry_run` | non | tout fonctionne mais aucun mail ne part (statut `dry_run`, rendu stocké) |

Planification :
- **Au premier passage de la journée**, l'outil tire les heures d'envoi du jour (`daily_max` ou la valeur de la montée progressive) : aléatoires dans la plage, triées, en respectant `min_gap_minutes`. Elles sont stockées, donc un redémarrage ne les recalcule pas.
- Un passage du scheduler **chaque minute** envoie la prochaine ligne dont l'heure planifiée est dépassée, si la campagne est active, dans la plage et sous le quota.
- Si le worker est arrêté pendant la plage, les mails **non envoyés ne sont pas rattrapés en rafale** : ceux dont l'heure est passée de plus de 2 h sont replanifiés sur la fin de plage ou le lendemain.

## 6. Envoi

- Un mail par job en file, `ShouldBeUnique` par ligne, verrou en base : **un même destinataire ne reçoit jamais deux fois le même mail**, même en cas de double exécution du scheduler.
- En-têtes : `From` et `Reply-To` configurables dans `.env` (même adresse par défaut), `List-Unsubscribe: <mailto:...>` pointant vers l'adresse d'expédition. `Message-ID` stocké.
- Texte brut uniquement, sans pièce jointe.
- Échec SMTP temporaire : 2 nouvelles tentatives à 1 h d'intervalle, puis statut `failed` avec le message d'erreur (sans identifiants).
- **Disjoncteur** : 3 échecs consécutifs (hors adresse invalide) mettent la campagne en pause, avec un bandeau dans l'interface.
- Rebond immédiat (adresse refusée par le serveur) : statut `bounced`, adresse ajoutée à la liste de suppression.

## 7. Liste de suppression et réponses

- Table `suppressions` globale (adresse ou domaine entier), avec motif (`stop`, `bounce`, `manuel`) et date.
- Vérifiée **juste avant chaque envoi**, pas seulement à l'import.
- Interface et commande `slowmail:suppress {adresse|@domaine} {--reason=stop}`.
- Sur chaque ligne, boutons manuels : `répondu`, `stop`, `rendez-vous`, avec un champ de notes. `stop` ajoute l'adresse à la liste de suppression. Pas de lecture automatique de la boîte mail en v1.

## 8. Interface (Blade)

1. **Campagnes** : liste, statut, avancement (envoyés / total), prochain envoi prévu.
2. **Campagne** : onglets
   - *Modèle* (objet, corps, aperçu, test) ;
   - *Réglages* (§5, avec un récapitulatif en clair du type « 4 mails par jour, du lundi au vendredi, entre 9 h 30 et 17 h 30, fin estimée le 24 novembre ») ;
   - *Destinataires* (tableau filtrable par statut, actions §7) ;
   - *Journal* (envois, erreurs, replanifications).
3. Boutons : **Activer / Mettre en pause / Reprendre**, export CSV avec les colonnes de suivi.
4. Bandeau d'état global : kill switch, dernière exécution du scheduler, prochain envoi.

## 9. Données

```
campaigns   (id, name, subject, body, settings json, status, start_at, end_at, created_at…)
recipients  (id, campaign_id, email, domain, data json, status, planned_at, sent_at, attempts,
             last_error, message_id, reply_status, notes, rendered_subject, rendered_body)
suppressions(id, value, type [email|domain], reason, created_at)
send_logs   (id, recipient_id, event, detail, created_at)
```

Statuts d'une ligne : `pending`, `planned`, `sending`, `sent`, `failed`, `skipped`, `bounced`, `dry_run`, `suppressed`.

Le rendu (objet + corps) est **stocké à l'envoi**, pour garder la trace exacte de ce qui est parti même si le modèle change ensuite.

## 10. Commandes Artisan

- `slowmail:tick` : appelé chaque minute par le scheduler.
- `slowmail:import {campagne} {fichier.csv}`
- `slowmail:status {campagne}` : avancement, prochains envois planifiés, fin estimée.
- `slowmail:export {campagne} {fichier.csv}`
- `slowmail:suppress` (voir §7)
- `slowmail:purge {--days=}` : supprime les rendus et données des campagnes terminées depuis plus de N jours.

## 11. Confidentialité

- Données minimales : adresse, colonnes du CSV, statuts. Pas de contenu des réponses.
- Rien de sensible dans les logs Laravel : jamais le corps des mails, jamais les identifiants SMTP.
- Le CSV importé n'est pas conservé sur disque après import (les données sont en base).

## 12. Plan d'exécution (une phase par session)

1. **Socle** : Docker, migrations, auth par mot de passe, import CSV avec rapport, tests. *Arrêt : un CSV réel s'importe, les doublons sont signalés.*
2. **Modèle** : moteur de variables (`[X]`, `[X|défaut]`, échappement), validation, aperçu, test d'envoi. *Arrêt : tests Pest du moteur, aperçu vérifié à la main.*
3. **Planification** : tirage des heures, plage, jours, fériés, écart minimum, montée progressive, cooldown de domaine, tests avec `setTestNow()`. *Arrêt : simulation sur 3 semaines sans envoi réel, plan affiché par `slowmail:status`.*
4. **Envoi** : jobs uniques, relances, disjoncteur, suppression, `dry_run`. *Arrêt : un vrai mail de test envoyé via OVH, puis deux exécutions simultanées du scheduler sans doublon.*
5. **Interface** : écrans §8, récapitulatif des réglages, export. *Arrêt : parcours complet sur 3 adresses à toi, en `dry_run` puis en réel.*

## 13. Hors périmètre (v1)

- Suivi d'ouverture, de clics, pixels.
- Lecture automatique des réponses (IMAP).
- Relances automatiques (« J+7 »).
- Plusieurs expéditeurs, plusieurs utilisateurs.
- Mails HTML, pièces jointes.

## 14. Critères d'acceptation

- `php artisan test` passe sans aucun envoi réel.
- Avec `daily_max = 4`, jamais plus de 4 mails par jour, tous dans la plage, jamais deux dans un intervalle inférieur à `min_gap_minutes`.
- Un redémarrage du worker ou du scheduler ne produit aucun doublon et aucun envoi en rafale.
- Une adresse en liste de suppression ne reçoit jamais de mail, y compris ajoutée après l'activation de la campagne.
- Une campagne sans la mention de désinscription dans le corps ne peut pas être activée.
- `SLOWMAIL_ENABLED=false` bloque tout envoi immédiatement.
- Aucun identifiant SMTP dans le code, les logs ou l'interface.
