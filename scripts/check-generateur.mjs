#!/usr/bin/env node
// Vérifie le générateur de fichier du site (site/assets/generateur.js) contre le vrai Docker Compose :
// chaque fichier produit doit être lu par Compose avec exactement les valeurs saisies, quels que soient les
// caractères (guillemets, antislash, #, $, accents…). Il faut Docker ; rien n'est démarré, sauf avec --container.
//
//   node scripts/check-generateur.mjs               # règles de saisie + lecture par `docker compose config`
//   node scripts/check-generateur.mjs --container   # + lit vraiment les variables dans le conteneur (image à jour tirée)
//   node scripts/check-generateur.mjs --dist        # + vérifie que dist/generateur.html embarque le fichier servi

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const withContainer = process.argv.includes('--container');
const withDist = process.argv.includes('--dist');
const noDocker = process.argv.includes('--no-docker'); // saisie et modèle seulement : Compose n'est PAS interrogé
const PROJECT = 'slowmail-gencheck'; // jamais le projet de l'utilisateur

let failures = 0;
const check = (ok, label, detail = '') => {
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}${!ok && detail ? '\n       ' + detail : ''}`);
  if (!ok) failures++;
};

// Le script du site est un script de navigateur : on l'évalue avec un `module` et sans `document`.
const sandbox = { module: { exports: {} }, console };
vm.runInNewContext(readFileSync(join(root, 'site/assets/generateur.js'), 'utf8'), sandbox);
const gen = sandbox.module.exports;

const template = readFileSync(join(root, 'site/telecharger/docker-compose.yml'), 'utf8')
  .replaceAll('__REPO_LC__', 'thepingouinface/slowmail') // l'image publiée : `run --container` la lit sur le disque
  .replaceAll('__REPO__', 'Thepingouinface/slowmail');

const base = {
  appPassword: 'une phrase assez longue',
  preset: 'ovh',
  host: '', port: '465', security: 'smtps',
  username: 'contact@exemple.example',
  smtpPassword: 'secret-de-la-boite',
  fromAddress: 'contact@exemple.example',
  fromName: 'Camille Roche',
  replyTo: '',
  timezone: 'Europe/Paris',
  webPort: '8080',
};
const withValues = (patch) => ({ ...base, ...patch });

// ------------------------------------------------------------------------------------------------ Saisie
const errorsOf = (patch) => Object.keys(gen.validate(withValues(patch)).errors).sort().join(',');
const warningsOf = (patch) => Object.keys(gen.validate(withValues(patch)).warnings).sort().join(',');

check(errorsOf({}) === '', 'une saisie complète ne produit aucune erreur', errorsOf({}));
check(errorsOf({ appPassword: '' }) === 'appPassword', 'le mot de passe de SlowMail est obligatoire');
check(errorsOf({ appPassword: 'a\nb' }) === 'appPassword', 'un saut de ligne dans un mot de passe est refusé');
check(errorsOf({ smtpPassword: '' }) === '', 'le mot de passe de la boîte est facultatif');
check(errorsOf({ username: '' }) === 'username', "l'identifiant est obligatoire");
check(errorsOf({ fromAddress: 'pas une adresse' }) === 'fromAddress', "une adresse d'expédition mal formée est refusée");
check(errorsOf({ fromAddress: 'élodie@exemple.example' }) === 'fromAddress', 'un accent avant le @ est refusé (SMTPUTF8 non pris en charge)');
check(errorsOf({ fromName: '' }) === 'fromName', "le nom d'expéditeur est obligatoire");
check(errorsOf({ replyTo: 'x' }) === 'replyTo', "une adresse de réponse mal formée est refusée");
check(errorsOf({ replyTo: 'reponses@exemple.example' }) === '', "l'adresse de réponse est acceptée");
check(errorsOf({ timezone: 'Mars/Olympus' }) === 'timezone', 'un fuseau inconnu est refusé');
check(errorsOf({ timezone: 'America/Montreal' }) === '', 'America/Montreal est accepté');
check(errorsOf({ webPort: '80' }) === 'webPort', 'un port inférieur à 1024 est refusé');
check(errorsOf({ webPort: '70000' }) === 'webPort', 'un port supérieur à 65535 est refusé');
check(errorsOf({ webPort: '8081' }) === '', 'un autre port est accepté');
check(errorsOf({ preset: 'other', host: 'smtp.exemple.example', port: '587', security: 'smtp' }) === '', 'un autre hébergeur complet est accepté');
check(errorsOf({ preset: 'other', host: '', port: '' }) === 'host,port', 'un autre hébergeur sans serveur ni port est refusé');
check(errorsOf({ preset: 'other', host: 'smtp://exemple.example', port: '587' }) === 'host', 'un serveur écrit avec http:// ou smtp:// est refusé');
check(errorsOf({ preset: 'other', host: 'smtp.exemple.example:587', port: '587' }) === 'host', 'un serveur écrit avec son port est refusé');
check(errorsOf({ preset: 'other', host: '', port: '' }) !== '' && errorsOf({ host: '', port: '' }) === '', "les champs de l'autre hébergeur ne comptent pas avec OVH");
check(warningsOf({ appPassword: 'court' }) === 'appPassword', 'un mot de passe court est signalé sans bloquer');
check(warningsOf({ appPassword: base.smtpPassword }) === 'appPassword', 'le mot de passe de SlowMail identique à celui de la boîte est signalé');
check(warningsOf({ fromAddress: 'contact@autre.example' }) === 'fromAddress', "un domaine d'expédition différent de l'identifiant est signalé");

// Mot de passe proposé : longueur, alphabet, pas de biais grossier.
const bytesAlea = (n) => Uint8Array.from({ length: n }, () => Math.floor(Math.random() * 256));
const proposed = Array.from({ length: 200 }, () => gen.randomPassword(bytesAlea));
check(proposed.every((p) => /^[A-HJ-NP-Za-km-np-z2-9]{4}(-[A-HJ-NP-Za-km-np-z2-9]{4}){4}$/.test(p)), 'le mot de passe proposé a 5 groupes de 4 signes sans caractère ambigu');
check(new Set(proposed).size === proposed.length, 'les mots de passe proposés diffèrent');
let calls = 0;
const stingy = (n) => (calls++ === 0 ? new Uint8Array(n).fill(255) : bytesAlea(n)); // un premier tirage entièrement écarté
const afterRejects = gen.randomPassword(stingy);
check(calls >= 2 && /^[A-HJ-NP-Za-km-np-z2-9-]{24}$/.test(afterRejects), 'les octets hors limite sont écartés puis on retire (pas de biais, pas de boucle)');

// ------------------------------------------------------------------------------------- Modèle inchangé
const defaults = gen.build(template, withValues({ appPassword: '', smtpPassword: '', username: '', fromAddress: '', fromName: '' })).split('\n');
const original = template.split('\n');
const notice = 3; // lignes ajoutées en tête
const changed = original.filter((line, i) => defaults[i + notice] !== line);
check(changed.length === 0, 'avec des champs vides, le fichier est le modèle (plus l\'en-tête)', changed.join(' | '));

// -------------------------------------------------------------------------- Lecture par Docker Compose
const tmp = mkdtempSync(join(tmpdir(), 'slowmail-gencheck-'));
const compose = (file, ...args) => execFileSync('docker', ['compose', '-p', PROJECT, '-f', file, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

// Sans Docker joignable, on le dit : une vérification sautée ne doit pas passer pour réussie.
let dockerReady = !noDocker;
if (dockerReady) {
  try { execFileSync('docker', ['info'], { stdio: 'ignore' }); } catch {
    console.error('Docker est injoignable (OrbStack ou Docker Desktop lancé ?). Relancez, ou ajoutez --no-docker pour ne vérifier que la saisie.');
    rmSync(tmp, { recursive: true, force: true });
    process.exit(2);
  }
}

const tricky = 'a"b\\c$d#e\'f é 😀 `x` $$y ${Z} %w &amp; <>';
const cases = [
  ['saisie ordinaire (OVH)', base],
  ['caractères piégeux dans tous les champs', withValues({
    appPassword: tricky, smtpPassword: tricky, username: 'contact+tag@exemple.example',
    fromName: 'Camille "Cam" Roche, O\'Brien $ #1 é', replyTo: 'reponses@exemple.example',
  })],
  ['autre hébergeur en STARTTLS, autre port', withValues({ preset: 'other', host: 'smtp.exemple.example', port: '587', security: 'smtp', webPort: '18081', timezone: 'America/Montreal' })],
  ['mots de passe vides', withValues({ smtpPassword: '', replyTo: '' })],
];

const expectedEnv = (v) => {
  const smtp = v.preset === 'ovh' ? { host: 'ssl0.ovh.net', port: '465', scheme: 'smtps' } : { host: v.host, port: v.port, scheme: v.security };
  return {
    SLOWMAIL_PASSWORD: v.appPassword, MAIL_HOST: smtp.host, MAIL_PORT: smtp.port, MAIL_SCHEME: smtp.scheme,
    MAIL_USERNAME: v.username, MAIL_PASSWORD: v.smtpPassword, MAIL_FROM_ADDRESS: v.fromAddress, MAIL_FROM_NAME: v.fromName,
    SLOWMAIL_REPLY_TO: v.replyTo, SLOWMAIL_TIMEZONE: v.timezone, SLOWMAIL_ENABLED: 'false', SLOWMAIL_OPTOUT_TEXT: 'stop',
  };
};

try {
  cases.forEach(([label, values], index) => {
    check(Object.keys(gen.validate(values).errors).length === 0, `${label} : la saisie est valable`, JSON.stringify(gen.validate(values).errors));
    if (!dockerReady) return;
    const file = join(tmp, `case-${index}.yml`);
    writeFileSync(file, gen.build(template, values));
    let model;
    try {
      model = JSON.parse(compose(file, 'config', '--format', 'json'));
    } catch (error) {
      check(false, `${label} : Compose lit le fichier`, String(error.stderr || error.message).trim());
      return;
    }
    const env = model.services.slowmail.environment;
    const expected = expectedEnv(values);
    // `config` réécrit chaque « $ » en « $$ » pour que sa sortie reste un fichier Compose valable.
    const wrong = Object.entries(expected).filter(([key, value]) => env[key] !== value.replaceAll('$', '$$$$'));
    check(wrong.length === 0, `${label} : Compose lit les valeurs saisies`, wrong.map(([k, v]) => `${k}: attendu ${JSON.stringify(v)}, lu ${JSON.stringify(env[k])}`).join('\n       '));
    const port = model.services.slowmail.ports[0];
    check(port.host_ip === '127.0.0.1' && port.published === values.webPort && port.target === 8000, `${label} : le port reste sur 127.0.0.1:${values.webPort}`, JSON.stringify(port));
    check(Object.keys(env).length === Object.keys(expected).length, `${label} : aucune variable en trop ni en moins`, Object.keys(env).join(','));

    if (withContainer && index === 1) {
      // La preuve ultime : les valeurs vues par le programme dans le conteneur. Aucun service ne démarre.
      const read = (key) => compose(file, 'run', '--rm', '--no-deps', '-T', '--entrypoint', 'printenv', 'slowmail', key).replace(/\n$/, '');
      const bad = Object.entries(expected).filter(([key, value]) => read(key) !== value);
      check(bad.length === 0, `${label} : le conteneur reçoit exactement les valeurs saisies`, bad.map(([k]) => k).join(', '));
    }
  });
} finally {
  if (withContainer) {
    try { compose(join(tmp, 'case-0.yml'), 'down', '-v', '--remove-orphans'); } catch { /* rien à nettoyer */ }
  }
  rmSync(tmp, { recursive: true, force: true });
}

// ------------------------------------------------------------------------------------------ Site construit
if (withDist) {
  const page = join(root, 'dist/generateur.html');
  const served = join(root, 'dist/telecharger/docker-compose.yml');
  check(existsSync(page) && existsSync(served), 'dist/ existe (scripts/build-site.sh d\'abord)');
  if (existsSync(page) && existsSync(served)) {
    const embedded = readFileSync(page, 'utf8').match(/<script type="text\/plain" id="modele-compose">([\s\S]*?)<\/script>/);
    check(embedded && embedded[1] === readFileSync(served, 'utf8'), 'dist/generateur.html embarque exactement le fichier servi');
  }
}

if (!dockerReady) console.log('\nNON VÉRIFIÉ : la lecture des fichiers par Docker Compose (--no-docker).');
console.log(failures ? `\n${failures} vérification(s) en échec.` : dockerReady ? '\nTout est bon.' : '\nSaisie et modèle : bons.');
process.exit(failures ? 1 : 0);
