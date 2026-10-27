// Générateur du fichier docker-compose.yml. Tout se passe dans cette page : rien n'est envoyé, rien n'est mémorisé.
// La page interdit d'ailleurs à son code toute requête réseau (balise Content-Security-Policy de generateur.html).
//
// Le fichier produit est le modèle de telecharger/docker-compose.yml dont on remplace les valeurs : il n'y a donc
// qu'une seule source. Les fonctions pures (validate, build) sont aussi chargées par scripts/check-generateur.mjs.

(function () {
  'use strict';

  var OVH = { host: 'ssl0.ovh.net', port: '465', scheme: 'smtps' };
  var DEFAULT_WEB_PORT = '8080';
  var MASK = '••••••••';

  var NOTICE =
    "# Fichier généré dans votre navigateur par le site de SlowMail : rien n'a été envoyé nulle part.\n" +
    "# Il contient vos mots de passe : gardez-le pour vous (ne l'envoyez à personne, ne le publiez pas).\n\n";

  // ---------------------------------------------------------------------------------------- Écriture du fichier

  // Un texte JSON est un scalaire YAML entre guillemets valide (\" \\ \n \uXXXX). Compose, lui, lit « $ » comme le
  // début d'une variable : on le double, comme le tutoriel le demande à la main.
  function quote(value) {
    return JSON.stringify(String(value).replace(/\$/g, function () { return '$$'; }));
  }

  function replaceOnce(text, from, to) {
    var index = text.indexOf(from);
    if (index < 0) throw new Error('Le modèle ne contient plus « ' + from + ' ».');
    return text.slice(0, index) + to + text.slice(index + from.length);
  }

  // Remplace la valeur (entre guillemets) d'une ligne « CLE: "..." » en gardant le commentaire qui suit.
  function setEnv(text, key, value) {
    var line = new RegExp('^([ \\t]+' + key + ': )"[^"\\n]*"', 'm');
    if (!line.test(text)) throw new Error('Le modèle ne contient plus la ligne ' + key + '.');
    return text.replace(line, function (all, head) { return head + quote(value); });
  }

  function clean(raw) {
    var v = {
      appPassword: raw.appPassword == null ? '' : String(raw.appPassword),
      smtpPassword: raw.smtpPassword == null ? '' : String(raw.smtpPassword),
      preset: raw.preset === 'other' ? 'other' : 'ovh',
      security: raw.security === 'smtp' ? 'smtp' : 'smtps',
    };
    ['host', 'port', 'username', 'fromAddress', 'fromName', 'replyTo', 'timezone', 'webPort'].forEach(function (key) {
      v[key] = raw[key] == null ? '' : String(raw[key]).trim();
    });
    return v;
  }

  function smtpSettings(v) {
    return v.preset === 'ovh' ? OVH : { host: v.host, port: v.port, scheme: v.security };
  }

  function webPortOf(v) {
    return /^\d{1,5}$/.test(v.webPort) ? String(parseInt(v.webPort, 10)) : DEFAULT_WEB_PORT;
  }

  // options.mask : pour l'aperçu, les mots de passe ne s'affichent pas.
  function build(template, raw, options) {
    var v = clean(raw);
    var mask = !!(options && options.mask);
    var smtp = smtpSettings(v);
    var secret = function (value) { return mask && value !== '' ? MASK : value; };
    var port = webPortOf(v);

    var text = template;
    text = setEnv(text, 'SLOWMAIL_PASSWORD', secret(v.appPassword));
    text = setEnv(text, 'MAIL_HOST', smtp.host);
    text = setEnv(text, 'MAIL_PORT', smtp.port);
    text = setEnv(text, 'MAIL_SCHEME', smtp.scheme);
    text = setEnv(text, 'MAIL_USERNAME', v.username);
    text = setEnv(text, 'MAIL_PASSWORD', secret(v.smtpPassword));
    text = setEnv(text, 'MAIL_FROM_ADDRESS', v.fromAddress);
    text = setEnv(text, 'MAIL_FROM_NAME', v.fromName);
    text = setEnv(text, 'SLOWMAIL_REPLY_TO', v.replyTo);
    text = setEnv(text, 'SLOWMAIL_TIMEZONE', v.timezone);
    text = replaceOnce(text, '"127.0.0.1:8080:8000"', '"127.0.0.1:' + port + ':8000"');
    text = replaceOnce(text, 'http://localhost:8080', 'http://localhost:' + port);
    return NOTICE + text;
  }

  // ---------------------------------------------------------------------------------------------- Vérifications

  function hasControl(text) { return /[\u0000-\u001f\u007f]/.test(text); }

  function validEmail(address) {
    if (!/^[^\s@"<>(),;:\\]+@[^\s@"<>(),;:\\]+\.[^\s@"<>(),;:\\]+$/.test(address)) return 'shape';
    if (!/^[\x21-\x7e]+$/.test(address.split('@')[0])) return 'accent';
    return null;
  }

  function validHost(host) {
    return /^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/.test(host);
  }

  function validTimezone(zone) {
    if (!/^[A-Za-z][A-Za-z0-9_+\-]*(\/[A-Za-z0-9_+\-]+)*$/.test(zone)) return false;
    if (typeof Intl === 'undefined' || !Intl.DateTimeFormat) return true;
    try { new Intl.DateTimeFormat('fr-FR', { timeZone: zone }); return true; } catch (e) { return false; }
  }

  function inRange(text, min, max) {
    return /^\d{1,5}$/.test(text) && parseInt(text, 10) >= min && parseInt(text, 10) <= max;
  }

  function domainOf(address) { return address.indexOf('@') > 0 ? address.split('@')[1].toLowerCase() : ''; }

  // errors : ce qui empêche de produire un fichier utilisable. warnings : à savoir, sans bloquer.
  function validate(raw) {
    var v = clean(raw);
    var errors = {};
    var warnings = {};

    if (v.appPassword === '') errors.appPassword = 'Choisissez un mot de passe pour entrer dans SlowMail : sans lui, personne ne peut se connecter.';
    else if (hasControl(v.appPassword)) errors.appPassword = 'Ce mot de passe contient un caractère invisible (saut de ligne, tabulation…). Retapez-le.';
    else {
      if (v.appPassword.length < 12) warnings.appPassword = 'Ce mot de passe est court. Une phrase de plusieurs mots est plus sûre.';
      if (v.appPassword !== v.appPassword.trim()) warnings.appPassword = 'Il commence ou se termine par une espace : est-ce voulu ?';
      if (v.smtpPassword !== '' && v.appPassword === v.smtpPassword) warnings.appPassword = 'C’est le mot de passe de votre messagerie : choisissez-en un autre, réservé à SlowMail.';
    }

    if (v.preset === 'other') {
      if (v.host === '') errors.host = 'Indiquez le nom du serveur d’envoi de votre messagerie.';
      else if (!validHost(v.host)) errors.host = 'Écrivez seulement le nom du serveur, par exemple smtp.exemple.fr (sans http://, sans port, sans espace).';
      if (v.port === '') errors.port = 'Indiquez le port (465 ou 587 le plus souvent).';
      else if (!inRange(v.port, 1, 65535)) errors.port = 'Le port est un nombre entre 1 et 65535.';
    }

    if (v.username === '') errors.username = 'Indiquez l’identifiant de votre boîte mail (le plus souvent son adresse complète).';
    else if (hasControl(v.username)) errors.username = 'Cet identifiant contient un caractère invisible. Retapez-le.';

    if (hasControl(v.smtpPassword)) errors.smtpPassword = 'Ce mot de passe contient un caractère invisible (saut de ligne, tabulation…). Retapez-le.';
    else if (v.smtpPassword !== '' && v.smtpPassword !== v.smtpPassword.trim()) warnings.smtpPassword = 'Il commence ou se termine par une espace : est-ce voulu ?';

    var from = validEmail(v.fromAddress);
    if (v.fromAddress === '') errors.fromAddress = 'Indiquez l’adresse qui apparaîtra comme expéditeur.';
    else if (from === 'shape') errors.fromAddress = 'Cela ne ressemble pas à une adresse mail (exemple : contact@votre-domaine.fr).';
    else if (from === 'accent') errors.fromAddress = 'La partie avant le @ ne doit contenir ni accent ni caractère spécial : SlowMail ne les gère pas.';
    else if (validEmail(v.username) === null && domainOf(v.username) !== domainOf(v.fromAddress)) {
      warnings.fromAddress = 'Le domaine n’est pas celui de votre identifiant : votre hébergeur peut refuser d’envoyer avec cette adresse.';
    }

    if (v.fromName === '') errors.fromName = 'Indiquez le nom qui s’affichera chez vos destinataires (le vôtre, ou celui de votre structure).';
    else if (hasControl(v.fromName)) errors.fromName = 'Ce nom contient un caractère invisible. Retapez-le.';

    if (v.replyTo !== '') {
      var reply = validEmail(v.replyTo);
      if (reply === 'shape') errors.replyTo = 'Cela ne ressemble pas à une adresse mail. Laissez vide pour recevoir les réponses à l’adresse d’expédition.';
      else if (reply === 'accent') errors.replyTo = 'La partie avant le @ ne doit contenir ni accent ni caractère spécial.';
    }

    if (v.timezone === '') errors.timezone = 'Indiquez un fuseau horaire (Europe/Paris, par exemple).';
    else if (!validTimezone(v.timezone)) errors.timezone = 'Fuseau inconnu. Choisissez-en un dans la liste (Europe/Paris, America/Montreal…).';

    if (!inRange(v.webPort, 1024, 65535)) errors.webPort = 'Le port est un nombre entre 1024 et 65535 (8080 si vous ne savez pas).';

    return { errors: errors, warnings: warnings };
  }

  // Mot de passe proposé : 20 signes sans caractère ambigu (ni 0/O, ni 1/l/I), par groupes de 4.
  function randomPassword(randomBytes) {
    var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    var limit = 256 - (256 % alphabet.length); // pas de biais : on écarte les octets au-delà
    var signs = [];
    while (signs.length < 20) {
      var bytes = randomBytes(32);
      for (var i = 0; i < bytes.length && signs.length < 20; i++) {
        if (bytes[i] < limit) signs.push(alphabet.charAt(bytes[i] % alphabet.length));
      }
    }
    return [signs.slice(0, 4), signs.slice(4, 8), signs.slice(8, 12), signs.slice(12, 16), signs.slice(16, 20)]
      .map(function (group) { return group.join(''); }).join('-');
  }

  var api = { build: build, validate: validate, quote: quote, randomPassword: randomPassword, MASK: MASK };

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;

  // --------------------------------------------------------------------------------------------------- Page

  var form = document.getElementById('gen-form');
  if (!form) return;

  var $ = function (id) { return document.getElementById(id); };
  var templateNode = $('modele-compose');
  var template = templateNode ? templateNode.textContent : '';

  // Le marqueur est coupé en deux pour que scripts/build-site.sh ne le prenne pas pour un oubli.
  if (!template || template.indexOf('__COMPOSE' + '_MODELE__') !== -1) {
    $('gen-broken').hidden = false;
    form.hidden = true;
    return;
  }

  var FIELDS = {
    appPassword: 'f-app-password', host: 'f-host', port: 'f-port', username: 'f-username',
    smtpPassword: 'f-smtp-password', fromAddress: 'f-from-address', fromName: 'f-from-name',
    replyTo: 'f-reply-to', timezone: 'f-timezone', webPort: 'f-web-port',
  };

  var LABELS = {
    appPassword: 'Mot de passe de SlowMail', host: 'Serveur d’envoi', port: 'Port du serveur d’envoi',
    username: 'Identifiant', smtpPassword: 'Mot de passe de la boîte', fromAddress: 'Adresse d’expédition',
    fromName: 'Nom d’expéditeur', replyTo: 'Adresse de réponse', timezone: 'Fuseau horaire',
    webPort: 'Port d’ouverture de SlowMail',
  };

  var attempted = false;      // tant qu'on n'a pas demandé le fichier, on ne gronde personne
  var fromEdited = false;     // l'adresse d'expédition suit l'identifiant tant qu'on n'y a pas touché

  function read() {
    return {
      appPassword: $('f-app-password').value,
      preset: $('f-preset').value,
      host: $('f-host').value,
      port: $('f-port').value,
      security: $('f-security').value,
      username: $('f-username').value,
      smtpPassword: $('f-smtp-password').value,
      fromAddress: $('f-from-address').value,
      fromName: $('f-from-name').value,
      replyTo: $('f-reply-to').value,
      timezone: $('f-timezone').value,
      webPort: $('f-web-port').value,
    };
  }

  function show(node, message) {
    node.textContent = message || '';
    node.hidden = !message;
  }

  function render() {
    var values = read();
    var result = validate(values);

    $('gen-other').hidden = values.preset !== 'other';

    Object.keys(FIELDS).forEach(function (key) {
      var input = $(FIELDS[key]);
      var error = attempted ? result.errors[key] : '';
      show($('e-' + FIELDS[key]), error);
      show($('w-' + FIELDS[key]), result.warnings[key]);
      if (error) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    });

    try {
      $('apercu').textContent = build(template, values, { mask: true });
    } catch (e) {
      $('apercu').textContent = 'Le modèle du site est illisible : ' + e.message;
    }

    var summary = $('gen-summary');
    var keys = Object.keys(FIELDS).filter(function (key) { return attempted && result.errors[key]; });
    summary.textContent = '';
    if (keys.length) {
      var title = document.createElement('strong');
      title.textContent = keys.length === 1 ? 'Un champ est à corriger :' : keys.length + ' champs sont à corriger :';
      var list = document.createElement('ul');
      keys.forEach(function (key) {
        var item = document.createElement('li');
        var link = document.createElement('a');
        link.href = '#' + FIELDS[key];
        link.textContent = LABELS[key] + ' : ' + result.errors[key];
        link.addEventListener('click', function (event) { event.preventDefault(); $(FIELDS[key]).focus(); });
        item.appendChild(link);
        list.appendChild(item);
      });
      summary.appendChild(title);
      summary.appendChild(list);
    }
    summary.hidden = keys.length === 0;

    return { values: values, result: result, invalid: keys };
  }

  function status(message) { $('gen-status').textContent = message; }

  function checked() {
    attempted = true;
    var state = render();
    if (state.invalid.length) {
      status('');
      $(FIELDS[state.invalid[0]]).focus();
      return null;
    }
    return state;
  }

  function download(text) {
    var blob = new Blob([text], { type: 'application/octet-stream' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'docker-compose.yml';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  function copy(text, done) {
    var fallback = function () {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try { if (document.execCommand('copy')) done(); } catch (e) { /* le texte reste sélectionnable dans l'aperçu */ }
      document.body.removeChild(area);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, fallback);
    else fallback();
  }

  // ---- Événements
  form.addEventListener('submit', function (event) { event.preventDefault(); });
  form.addEventListener('input', function (event) {
    if (event.target && event.target.id === 'f-from-address') fromEdited = true;
    if (event.target && event.target.id === 'f-username' && !fromEdited) {
      var guess = event.target.value.trim();
      $('f-from-address').value = guess.indexOf('@') > 0 ? guess : '';
    }
    render();
  });
  form.addEventListener('change', render);

  document.querySelectorAll('[data-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = $(button.getAttribute('data-toggle'));
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.textContent = visible ? 'Masquer' : 'Afficher';
      button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    });
  });

  $('gen-random').addEventListener('click', function () {
    var input = $('f-app-password');
    input.value = randomPassword(function (n) { return crypto.getRandomValues(new Uint8Array(n)); });
    input.type = 'text';
    var toggle = document.querySelector('[data-toggle="f-app-password"]');
    toggle.textContent = 'Masquer';
    toggle.setAttribute('aria-pressed', 'true');
    render();
    status('Mot de passe proposé. Notez-le : vous en aurez besoin pour vous connecter à SlowMail.');
  });

  $('gen-download').addEventListener('click', function () {
    var state = checked();
    if (!state) return;
    download(build(template, state.values));
    $('apres').hidden = false;
    $('apres-smtp').hidden = state.values.smtpPassword !== '';
    $('apres-port').hidden = webPortOf(clean(state.values)) === DEFAULT_WEB_PORT;
    $('apres-port-n').textContent = webPortOf(clean(state.values));
    status('Fichier téléchargé : docker-compose.yml.');
    $('apres').scrollIntoView({ block: 'nearest' });
  });

  $('gen-copy').addEventListener('click', function () {
    var state = checked();
    if (!state) return;
    copy(build(template, state.values), function () {
      status('Contenu copié. Collez-le dans un fichier nommé docker-compose.yml (il contient vos mots de passe).');
    });
  });

  render();
})();
