/*
 * prices.js — live pricing from uCRM, via the plugin's public price feed.
 *
 * This file contains NO prices. It fetches them from the endpoint on the
 * script tag, so uCRM stays the single source of truth: change a price there
 * once and the website, the WhatsApp AI and the sales screens all agree
 * within minutes. If the feed is unreachable, every pricing area falls back
 * to the WhatsApp call-to-action already present in the page — the section
 * degrades to "ask us", never to a blank or a stale number.
 *
 *   <script src="assets/js/prices.js" defer
 *           data-endpoint="https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/prices.php"
 *           data-whatsapp="256705993348"></script>
 *
 * Renders into:
 *   <div data-dishnet-plans></div>       the monthly plans grid
 *   <span data-live-price="NAME"></span> one product's price, by uCRM name
 */
(function () {
  'use strict';
  var script = document.currentScript || document.querySelector('script[src*="prices.js"]');
  if (!script) return;
  var ENDPOINT = script.getAttribute('data-endpoint') || '';
  var WA = (script.getAttribute('data-whatsapp') || '').replace(/\D/g, '');
  if (!ENDPOINT) return;

  function fmt(cur, n) {
    var whole = Math.round(n) === n;
    return cur + ' ' + n.toLocaleString('en-US', { maximumFractionDigits: whole ? 0 : 2 });
  }

  function render(data) {
    var cur = data.currency || 'UGX';

    // Individual price slots (kit cards etc.)
    var byName = {};
    (data.plans || []).concat(data.hardware || []).forEach(function (i) {
      byName[i.name.toLowerCase()] = i;
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-live-price]'), function (el) {
      var item = byName[(el.getAttribute('data-live-price') || '').toLowerCase()];
      if (item) {
        el.innerHTML = '<span class="lp-amount">' + fmt(cur, item.price) + '</span>' +
                       '<span class="lp-note">VAT inclusive</span>';
      }
    });

    // Monthly plans grid
    var grid = document.querySelector('[data-dishnet-plans]');
    if (grid && (data.plans || []).length) {
      grid.innerHTML = data.plans.map(function (p) {
        var flex = /flex/i.test(p.name);
        var best = /flex\s*24/i.test(p.name);
        return '<article class="price-card' + (best ? ' price-card-best' : '') + '">' +
          (best ? '<span class="price-pill">Best value</span>' : (flex ? '<span class="price-pill price-pill-soft">UGX 0 upfront</span>' : '')) +
          '<h3>' + esc(p.name) + '</h3>' +
          '<div class="price-amount">' + fmt(cur, p.price) + '<small>/' + (p.period || 'month') + '</small></div>' +
          (flex ? '<p class="price-desc">UGX 0 hardware upfront — Starlink Mini + connectivity + professional installation + DishNet support.</p>'
                : '<p class="price-desc">Unlimited data · professional installation available · DishNet local support.</p>') +
          (p.speed ? '<p class="price-speed">Up to ' + esc(String(p.speed)) + ' Mbps</p>' : '') +
          '<a class="btn btn-primary" href="https://wa.me/' + WA +
            '?text=' + encodeURIComponent('Hello DishNet, I would like to sign up for ' + p.name) +
          '">Get ' + esc(p.name.replace(/^DishNet\s*/i, '')) + '</a>' +
          '</article>';
      }).join('') + '<p class="price-vat">' + esc(data.vat_note || 'All prices VAT inclusive') + '</p>';
    }
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var ctrl = ('AbortController' in window) ? new AbortController() : null;
  if (ctrl) setTimeout(function () { ctrl.abort(); }, 8000);
  fetch(ENDPOINT, { signal: ctrl && ctrl.signal })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(render)
    .catch(function () { /* fallback content already in the page stays */ });
})();
