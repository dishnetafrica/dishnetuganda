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
 *   <div data-dishnet-plans></div>        the monthly plans grid
 *   <div data-dishnet-hero-plans></div>   the homepage hero cards (dark theme)
 *   <span data-live-price="NAME"></span>  one product's price, by uCRM name
 *
 * data-name-map on the script tag maps uCRM plan names to the display names
 * customers researched on starlink.com (e.g. "DishNet Home" -> "Residential").
 * uCRM names stay the billing truth; only the label changes. Hero order
 * buttons include the visitor's address/pin when window.DN_ADDR is present.
 */
(function () {
  'use strict';
  var script = document.currentScript || document.querySelector('script[src*="prices.js"]');
  if (!script) return;
  var ENDPOINT = script.getAttribute('data-endpoint') || '';
  var WA = (script.getAttribute('data-whatsapp') || '').replace(/\D/g, '');
  var FEATURED = (script.getAttribute('data-featured') || 'DishNet Home').toLowerCase();
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
        var best = p.name.toLowerCase() === FEATURED;
        return '<article class="price-card' + (best ? ' price-card-best' : '') + '">' +
          (best ? '<span class="price-pill">Best value</span>' : '') +
          (!best && flex ? '<span class="price-pill price-pill-soft">UGX 0 upfront</span>' : '') +
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

    // Homepage hero cards (Starlink-style, price-first)
    var hero = document.querySelector('[data-dishnet-hero-plans]');
    if (hero && (data.plans || []).length) {
      var MAP = {};
      try { MAP = JSON.parse(script.getAttribute('data-name-map') || '{}'); } catch (e) {}
      var flex12 = null;
      data.plans.forEach(function (p) { if (/flex/i.test(p.name) && /12/.test(p.name)) flex12 = p; });
      var list = data.plans.filter(function (p) { return p !== flex12; }).slice(0, 3);

      hero.innerHTML = list.map(function (p) {
        var flex = /flex/i.test(p.name);
        var best = p.name.toLowerCase() === FEATURED;
        var disp = MAP[p.name] || p.name;
        var sub  = MAP[p.name] && MAP[p.name] !== p.name ? esc(p.name) + ' plan'
                 : (flex ? 'Kit + internet + installation, monthly' : '');
        var feats = flex ? [
            'No kit to buy — Starlink Mini included',
            'Professional installation included',
            flex12 ? '24-month plan · 12-month at ' + esc(fmt(cur, flex12.price)) : '24-month plan'
          ] : [
            'Unlimited data' + (p.speed ? ' — up to ' + esc(String(p.speed)) + ' Mbps' : ''),
            best ? 'Busy homes, offices, many devices' : 'Everyday streaming & WhatsApp',
            (best ? 'Priority local support' : 'Local support') + ', VAT inclusive'
          ];
        var short = disp.replace(/^Residential\s*/i, '');
        return '<div class="sl-plan' + (best ? ' best' : '') + '">' +
          (best ? '<span class="sl-pill">Best value</span>'
                : (flex ? '<span class="sl-pill soft">' + esc(cur) + ' 0 upfront</span>' : '')) +
          '<div class="sl-pname">' + esc(disp) + '</div>' +
          '<div class="sl-pmap">' + sub + '</div>' +
          '<div class="sl-pprice"><span class="c">' + esc(cur) + '</span>' +
            '<span class="n">' + Math.round(p.price).toLocaleString('en-US') + '</span>' +
            '<span class="p">/' + esc(p.period || 'month') + '</span></div>' +
          '<ul class="sl-feat"><li>' + feats.join('</li><li>') + '</li></ul>' +
          '<a class="sl-cta' + (best ? '' : ' ghost') + '" data-order="' + esc(disp) +
            (disp === p.name ? '' : ' (' + esc(p.name) + ')') + '" href="https://wa.me/' + WA + '">' +
            (flex ? 'Check availability' : 'Order ' + esc(short || disp)) + '</a>' +
          '</div>';
      }).join('');

      hero.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-order]') : null;
        if (!a) return;
        var extra = (typeof window.DN_ADDR === 'function') ? window.DN_ADDR() : '';
        var txt = 'Hello DishNet, I would like to order ' + a.getAttribute('data-order') + '.' +
                  (extra ? '\n' + extra : '');
        a.href = 'https://wa.me/' + WA + '?text=' + encodeURIComponent(txt);
      });
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
