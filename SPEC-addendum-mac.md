# Addendum — l'application tourne sur un Mac (veille, extinction, OrbStack arrêté)

L'app n'est PAS sur un serveur toujours allumé. Elle doit se comporter correctement
quand le Mac dort, est éteint, ou quand OrbStack est arrêté puis relancé.
Principe : en cas de doute, **ne rien envoyer** plutôt que d'envoyer en double ou en rafale.

## 1. Architecture simplifiée
- Pas de worker de file ni de Redis : `slowmail:tick` envoie le mail **directement**
  (envoi synchrone, 4 mails par jour n'ont pas besoin de file).
- Deux services Docker seulement : `app` + `scheduler` (`php artisan schedule:work`),
  avec `restart: unless-stopped`.
- README : activer « Lancer OrbStack à l'ouverture de session » dans les réglages
  d'OrbStack, pour que tout redémarre après un reboot du Mac.

## 2. Aucun rattrapage en rafale
- Chaque mail planifié a une heure `planned_at`. Au passage du tick, il part
  seulement si `now - planned_at <= SLOWMAIL_GRACE_MINUTES` (défaut 30)
  ET si `now` est dans la plage horaire ET le jour est autorisé.
- Sinon (Mac réveillé trop tard) : l'envoi est **replanifié** sur un créneau aléatoire
  de la plage restante du jour, en respectant `min_gap_minutes` ; s'il n'y a plus de
  place, il passe au prochain jour autorisé. Jamais plusieurs envois dans le même tick.
- Un tick envoie **au maximum 1 mail**.
- Le quota d'un jour manqué est **perdu, pas cumulé** : après 3 jours de veille,
  on reprend à `daily_max` par jour, pas à 3 × `daily_max`.
- La vérification plage horaire / quota / jour autorisé se fait **au moment de l'envoi**,
  pas seulement à la planification.

## 3. Aucun doublon, même si la machine s'arrête en plein envoi
- Avant l'appel SMTP : la ligne passe en `sending`, avec le `Message-ID` généré
  et `sending_started_at`, **dans une transaction validée avant l'envoi**.
- Après le succès SMTP : `sent` + `sent_at`.
- Au démarrage et à chaque tick : toute ligne `sending` depuis plus de 10 minutes
  passe en **`uncertain`**. L'outil ne la renvoie JAMAIS automatiquement.
  Elle apparaît dans l'interface avec un bandeau « à vérifier dans vos envoyés »
  et deux boutons : « C'est parti » (→ `sent`) / « Pas parti » (→ `pending`).
- Test Pest : simuler un crash après le passage en `sending` et avant le retour SMTP.

## 4. Réseau indisponible au réveil
- Au réveil, le Wi-Fi n'est pas toujours revenu. Une erreur de connexion / DNS / timeout
  est un état « réseau indisponible » : elle **ne consomme pas de tentative**, ne compte
  pas dans le disjoncteur, et la ligne est retentée au tick suivant (dans la limite
  de la grace period, sinon replanifiée comme au §2).
- Seules les réponses d'erreur du serveur SMTP (refus d'authentification, adresse
  rejetée, etc.) comptent comme échecs.

## 5. Dates, durées et horloge
- Toutes les décisions utilisent l'horloge réelle (`now()` en UTC) et le fuseau de la
  campagne pour la plage horaire. Pas de compteur « ticks ».
- Les plannings du jour sont stockés en base ; au redémarrage on les relit,
  on ne les recalcule pas.
- La montée progressive compte les **jours où des mails sont réellement partis**,
  pas les jours calendaires : une semaine d'absence ne fait pas sauter de paliers.
- `end_at` et `max_total` restent respectés après une longue interruption.
- Si l'écart entre deux ticks dépasse 15 minutes, écrire une ligne
  « interruption détectée (X h) » dans le journal.

## 6. Visibilité
- Bandeau en haut de l'interface : « dernier tick il y a X min » (rouge si > 10 min),
  nombre de lignes `uncertain`, prochain envoi prévu, heure exacte de la dernière
  exécution du scheduler.
- `slowmail:status` affiche les mêmes informations.

## 7. Tests supplémentaires (Pest + `Carbon::setTestNow`)
- Mac « endormi » 6 h pendant la plage : aucun envoi en rafale au réveil,
  les mails sont replanifiés.
- Réveil en dehors de la plage (21 h, week-end, jour férié) : rien ne part.
- Arrêt de 3 jours : le quota du jour reste `daily_max`.
- Crash entre `sending` et la réponse SMTP : la ligne passe en `uncertain`, pas de renvoi.
- Erreur réseau : aucune tentative consommée, disjoncteur intact.
- Deux ticks lancés simultanément : un seul mail part.
