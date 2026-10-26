/*
 * order.js — the address-first order flow on starlink-kits.html (moved there from the homepage in
 * October 2026, when the homepage became the consultancy-led design; the behaviour is unchanged).
 *
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
    return located(t);
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

  function reveal(e) {
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
    if (!toOrder(e)) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (geo) geo.addEventListener('click', function (ev) {
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
      reveal(ev);
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
  if (input) input.addEventListener('keydown', function (e) { if (e.key === 'Enter') reveal(e); });
  if (input) input.addEventListener('input', showWhere);

  // ── What counts as a location ──
  // typed() passes its text through here last. Two kinds of box are empty:
  //  - one with no letter or digit in it. trim() removes spaces but not a
  //    pasted zero-width space, a soft hyphen or a direction mark, and a box
  //    holding only those, or only punctuation, looks empty and is not a
  //    location. So the panel does not open on it, does not name it, and no
  //    order carries it. A browser without Unicode property escapes keeps
  //    the trim() test alone;
  //  - the Detect text cut short from its end (one Backspace too many). It
  //    is still the pin, which the order carries anyway. Text that holds
  //    anything more than the Detect text wrote is kept as typed.
  // Invisible characters at either end of a real location are left out too.
  // Nothing in the box is changed; only what the order and the panel use.
  function located(t) {
    if (auto && t.indexOf('My location pin (') === 0 && auto.indexOf(t) === 0) return '';
    var word, edge;
    try { word = new RegExp('[\\p{L}\\p{N}]', 'u'); edge = new RegExp('^[\\s\\p{Cf}]+|[\\s\\p{Cf}]+$', 'gu'); } catch (err) { return t; }
    return word.test(t) ? t.replace(edge, '') : '';
  }

  // ── The order flow's checkout needs a location ──
  // The order is never sent without one. The panel opens only with a
  // location, but the box can be emptied afterwards. prices.js's "Complete
  // order on WhatsApp" (data-of-wa) puts DN_ADDR() in the order; its handler
  // sits on the flow and runs in the bubbling phase. This one runs first, in
  // the capture phase on document: with no location left it stops the click,
  // so nothing is sent, says so in the status line and puts the visitor back
  // in the box. Enter and Space on the button arrive as this same click.
  // With a location it does nothing. The own-kit option is an enquiry, not an order (data-of-kind="enquiry"), and the
  // prompt says so.
  var NEED = 'Type your town or address, or tap Detect, to complete your order.';
  var NEED_ENQ = 'Type your town or address, or tap Detect, to send your enquiry.';
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-of-wa]') : null;
    if (!b || window.DN_ADDR()) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    say(b.getAttribute('data-of-kind') === 'enquiry' ? NEED_ENQ : NEED);
    if (input) input.focus();
  }, true);
  // That prompt goes as soon as a location is typed, rather than staying under the box.
  if (input) input.addEventListener('input', function () { if (msg && (msg.textContent === NEED || msg.textContent === NEED_ENQ) && typed()) say(''); });

  // Back to the order. A keyboard user put back in the box would otherwise be
  // left there once the location is in, dozens of Tab stops from the button.
  // So when reveal() comes from the keyboard (Enter in the box, or Check My
  // Options or Detect pressed with a key; a mouse or touch click has
  // e.detail of 1 or more) and the kit and plan are already chosen, focus
  // goes to "Complete order on WhatsApp", which is scrolled to the middle of
  // the screen with the order summary above it. Only while focus is still
  // where the visitor acted. The Enter that got here is cancelled, or the
  // button would take it as a press and send the order at once.
  function toOrder(e) {
    var b = document.querySelector('#orderFlow [data-of-wa]');
    var keys = !!e && (e.type === 'keydown' || (e.type === 'click' && e.detail === 0));
    if (!keys || !b || b.disabled || document.activeElement !== e.target) return false;
    if (e.type === 'keydown') e.preventDefault();
    b.focus({ preventScroll: true });
    b.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
  }

  // Holding Enter on that button would press it again with every key
  // repeat, and open WhatsApp once for each. Only the first press counts.
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.repeat && e.key === 'Enter' && t && t.closest && t.closest('[data-of-wa]')) e.preventDefault();
  }, true);

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
