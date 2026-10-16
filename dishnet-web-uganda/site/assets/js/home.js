/*
 * home.js — the homepage's own behaviour (index.html only).
 *
 * Moved out of an inline <script>, so the page carries no inline handlers.
 *
 *  - The header gains .scrolled once the page moves.
 *  - The address box: window.DN_ADDR() returns what the visitor typed and/or
 *    the GPS pin, for prices.js's order flow ("Complete order on WhatsApp");
 *    "Check My Options" opens the order panel only when there is a location
 *    to act on, and the panel names only the location the order will carry;
 *    a saved pin and every failure are said in the status line under the box
 *    (#ofGeoMsg, role=status), never in an alert().
 *  - Every WhatsApp link whose message ends "My location is ", and every
 *    plan button in the plans grid (the live "Get <plan>" buttons included),
 *    gets the typed address and pin appended when it is clicked. The link's
 *    original href is kept in data-wa-base, so a later click after the
 *    address changes carries the new one. With nothing typed the link is
 *    left as it is and the visitor finishes the sentence in WhatsApp. The
 *    click is never blocked.
 */
(function () {
  'use strict';
  var WA = 'https://wa.me/256705993348';
  var TAIL = 'My location is ';

  window.addEventListener('scroll', function () {
    var h = document.getElementById('header');
    if (h) h.classList.toggle('scrolled', window.scrollY > 10);
  }, { passive: true });

  var pin = '';
  var auto = '';  // the exact text the Detect button wrote into the box, if it did
  var input = document.getElementById('ofAddr');
  var msg = document.getElementById('ofGeoMsg');
  var geo = document.getElementById('ofGeo');
  var geoLabel = document.getElementById('ofGeoLabel');
  var whereBox = document.getElementById('ofWhere');
  var whereLine = document.getElementById('ofWhereText');
  var whereFirst = whereBox ? whereBox.textContent : '';

  // What the visitor typed. The "My location pin (…)" text the Detect button
  // wrote is not an address and is left out, but anything the visitor added
  // after it (a landmark, directions) is kept.
  function typed() {
    var t = (input && input.value || '').trim();
    if (auto && t.indexOf(auto) === 0) t = t.slice(auto.length).replace(/^[\s,;:.\u2013\u2014-]+/, '');
    return t;
  }

  window.DN_ADDR = function () {
    var l = [], t = typed();
    if (t) l.push('My address: ' + t);
    if (pin) l.push('My pin: https://maps.google.com/?q=' + pin);
    return l.join('\n');
  };

  function say(text) { if (msg) msg.textContent = text || ''; }
  function geoText(text) { if (geoLabel) geoLabel.textContent = text; }

  // The order panel names only the location the order will carry (DN_ADDR):
  // it follows the box as it is edited, and goes back to its first wording
  // when there is no location left.
  function showWhere() {
    var t = typed(), has = !!(t || pin);
    if (whereBox) whereBox.textContent = has ? 'Location captured' : whereFirst;
    if (whereLine) whereLine.textContent = has ? (t || ('GPS pin ' + pin)) : '';
  }

  function reveal() {
    var t = typed();
    showWhere();
    if (!t && !pin) {
      say('Type your town or address, or tap Detect.');
      if (input) input.focus();
      return;
    }
    say('');
    var panel = document.getElementById('orderFlow');
    if (!panel) return;
    panel.hidden = false;
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (geo) geo.addEventListener('click', function () {
    if (!navigator.geolocation) {
      geoText('GPS unavailable');
      say('This browser cannot share your location — please type your town or address instead.');
      if (input) input.focus();
      return;
    }
    geoText('Locating…');
    say('');
    navigator.geolocation.getCurrentPosition(function (pos) {
      pin = pos.coords.latitude.toFixed(6) + ',' + pos.coords.longitude.toFixed(6);
      geoText('Pin saved');
      if (input && (!input.value || input.value === auto)) { auto = 'My location pin (' + pin + ')'; input.value = auto; }
      reveal();
      say('Location pin saved.');
    }, function (err) {
      geoText('Detect');
      say(err && err.code === 1
        ? 'Location access was not allowed — please type your town or address instead.'
        : 'Could not read your location — please type your town or address instead.');
      if (input) input.focus();
    }, { enableHighAccuracy: true, timeout: 12000 });
  });

  var go = document.getElementById('ofGo');
  if (go) go.addEventListener('click', reveal);
  if (input) input.addEventListener('keydown', function (e) { if (e.key === 'Enter') reveal(); });
  if (input) input.addEventListener('input', showWhere);

  // ── WhatsApp links that carry the visitor's location ──
  // Two kinds: a link whose message ends "My location is " (the tail is
  // replaced by the location), and a plan button in the plans grid. Once the
  // feed loads, prices.js writes those buttons with a fixed "sign up for
  // <plan>" message; the location goes on a new line after it. A message
  // that already holds the location is left as it is.
  function messageOf(href) {
    try { return new URL(href, window.location.href).searchParams.get('text'); }
    catch (e) { return null; }
  }

  function fillLocation(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href^="' + WA + '"]') : null;
    if (!a) return;
    var base = a.getAttribute('data-wa-base') || a.getAttribute('href');
    var text = messageOf(base);
    if (!text) return;
    var tail = text.slice(-TAIL.length) === TAIL;
    if (!tail && !a.closest('[data-dishnet-plans]')) return;
    a.setAttribute('data-wa-base', base);
    var where = window.DN_ADDR();
    if (!where || text.indexOf(where) !== -1) { a.setAttribute('href', base); return; }
    var lead = (tail ? text.slice(0, -TAIL.length) : text).replace(/\s+$/, '');
    a.setAttribute('href', WA + '?text=' + encodeURIComponent(lead + '\n' + where));
  }
  document.addEventListener('click', fillLocation);
  document.addEventListener('auxclick', fillLocation);
})();
