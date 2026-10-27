// Deux petits confort, rien d'obligatoire : sans JavaScript, tout le contenu reste lisible
// (tous les systèmes s'affichent, les commandes se copient à la main).

(function () {
  'use strict';

  var SYSTEMS = [
    { id: 'mac', label: 'Mac' },
    { id: 'windows', label: 'Windows' },
    { id: 'linux', label: 'Linux' },
  ];

  function detectSystem() {
    var ua = (navigator.userAgent || '') + ' ' + (navigator.platform || '');
    if (/Win/i.test(ua)) return 'windows';
    if (/Mac|iPhone|iPad/i.test(ua)) return 'mac';
    if (/Linux|X11|CrOS/i.test(ua)) return 'linux';
    return 'mac';
  }

  function remembered() {
    try {
      var value = localStorage.getItem('slowmail-os');
      return SYSTEMS.some(function (s) { return s.id === value; }) ? value : null;
    } catch (e) {
      return null;
    }
  }

  function remember(value) {
    try { localStorage.setItem('slowmail-os', value); } catch (e) { /* navigation privée : tant pis */ }
  }

  // --- Choix du système : un seul réglage pour toute la page --------------------------------
  var pickers = document.querySelectorAll('[data-os-picker]');
  var blocks = document.querySelectorAll('.os-block[data-os]');

  if (pickers.length && blocks.length) {
    var current = remembered() || detectSystem();

    var apply = function (id) {
      current = id;
      blocks.forEach(function (block) { block.hidden = block.getAttribute('data-os') !== id; });
      pickers.forEach(function (picker) {
        picker.querySelectorAll('button').forEach(function (button) {
          button.setAttribute('aria-pressed', button.getAttribute('data-os') === id ? 'true' : 'false');
        });
      });
    };

    pickers.forEach(function (picker) {
      SYSTEMS.forEach(function (system) {
        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('data-os', system.id);
        button.textContent = system.label;
        button.addEventListener('click', function () { remember(system.id); apply(system.id); });
        picker.appendChild(button);
      });
    });

    apply(current);
  }

  // --- Boutons « Copier » sur les blocs de commande -----------------------------------------
  document.querySelectorAll('.code[data-copy]').forEach(function (box) {
    var pre = box.querySelector('pre');
    if (!pre) return;

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy';
    button.textContent = 'Copier';
    button.setAttribute('aria-label', 'Copier la commande');

    button.addEventListener('click', function () {
      var text = pre.innerText.replace(/\s+$/, '');
      var done = function () {
        button.textContent = 'Copié';
        setTimeout(function () { button.textContent = 'Copier'; }, 1800);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
      } else {
        fallback(text, done);
      }
    });

    box.appendChild(button);
  });

  // --- Un lien vers une question du dépannage l'ouvre ---------------------------------------
  function openTarget() {
    var id = decodeURIComponent((location.hash || '').slice(1));
    var target = id ? document.getElementById(id) : null;
    if (target && target.tagName === 'DETAILS') {
      target.open = true;
      target.scrollIntoView();
    }
  }
  openTarget();
  window.addEventListener('hashchange', openTarget);

  function fallback(text, done) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* le texte reste sélectionnable à la main */ }
    document.body.removeChild(area);
  }
})();
