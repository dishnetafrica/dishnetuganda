/*
 * shop.js — the accessories shop, rendered from the plugin's live catalogue.
 *
 * This file contains NO prices and NO product list. It fetches both from the
 * endpoint on the script tag, so uCRM stays the single source of truth: the
 * website, the WhatsApp assistant and a quotation all read the same product
 * by the same name. Photos come from the same plugin, by slug, so the picture
 * and the price can never drift apart.
 *
 *   <script src="assets/js/shop.js" defer
 *           data-endpoint="https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=shop&format=json"
 *           data-whatsapp="256705993348"></script>
 *
 * Renders into <div data-dishnet-shop></div>. If the feed cannot be reached
 * the fallback already in the page stays exactly as it is — an "ask us on
 * WhatsApp" card. Nothing here ever blanks the page or shows a stale number.
 *
 * Ordering is a WhatsApp message naming the product and the price the visitor
 * saw. A person confirms stock and delivery before any money moves, which is
 * the whole of part 1; a cart and online payment are part 2.
 */
(function () {
  'use strict';
  var script = document.currentScript || document.querySelector('script[src*="shop.js"]');
  if (!script) return;
  var ENDPOINT = script.getAttribute('data-endpoint') || '';
  var WA = (script.getAttribute('data-whatsapp') || '').replace(/\D/g, '');
  if (!ENDPOINT) return;

  // The photo route lives beside the feed on the same plugin, so one address
  // configures both: strip the query, ask for shop_img.
  var BASE = ENDPOINT.split('?')[0];
  function imgUrl(slug, w) { return BASE + '?page=shop_img&s=' + encodeURIComponent(slug) + '&w=' + w; }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(cur, n) {
    var whole = Math.round(n) === n;
    return cur + ' ' + Number(n).toLocaleString('en-US', { maximumFractionDigits: whole ? 0 : 2 });
  }

  // Kits first, then the parts, in the order somebody shops for them.
  var ORDER = ['Kit', 'Router', 'Mount', 'Adapter', 'Cable', 'Power', 'Case'];
  var HEADING = {
    Kit:     ['Starlink kits',      'The dish, its router and everything needed to get connected.'],
    Router:  ['Routers and WiFi',   'Cover a bigger house or office, or add a mesh node to the router you already have.'],
    Mount:   ['Mounts',             'Wall, roof, pole and vehicle mounts. An item marked Mini fits the Starlink Mini; one marked Standard 4 or 4 X fits the Standard dish.'],
    Adapter: ['Adapters',           'Fit a dish to a pole you already have, or reuse an older mount.'],
    Cable:   ['Cables',             'Longer runs and replacements, with the weatherproof connector the dish needs.'],
    Power:   ['Power',              'Run a Starlink Mini from a vehicle or a power bank.'],
    Case:    ['Cases',              'Protect the Mini when it travels.']
  };

  function card(it, cur) {
    var name  = it.name || '';
    var price = fmt(cur, it.price);
    var msg   = 'Hello DishNet, I would like to order: ' + name + ' — ' + price +
                '. Please confirm availability and delivery.';
    var order = WA ? 'https://wa.me/' + WA + '?text=' + encodeURIComponent(msg) : '';
    return '<article class="shop-card">' +
      (it.slug ? '<div class="shop-shot"><img src="' + esc(imgUrl(it.slug, 480)) + '"' +
                 ' srcset="' + esc(imgUrl(it.slug, 240)) + ' 240w, ' + esc(imgUrl(it.slug, 480)) + ' 480w"' +
                 ' sizes="(max-width: 600px) 46vw, 260px" width="260" height="260" loading="lazy"' +
                 ' alt="' + esc(name) + '"></div>' : '') +
      '<div class="shop-body">' +
        '<h3>' + esc(name) + '</h3>' +
        (it.fits ? '<p class="shop-fits">Fits: ' + esc(it.fits) + '</p>' : '') +
        (it.specs ? '<details><summary>Details</summary><p>' + esc(it.specs) + '</p></details>' : '') +
        '<div class="shop-price">' + esc(price) + '<small>one-off &middot; VAT inclusive</small></div>' +
        (order ? '<a class="btn btn-primary" href="' + esc(order) + '" target="_blank" rel="noopener">Order on WhatsApp</a>' : '') +
      '</div></article>';
  }

  function render(data) {
    var host = document.querySelector('[data-dishnet-shop]');
    var items = (data && data.items) || [];
    // Nothing to show is not an improvement on the fallback already here.
    if (!host || !items.length) return;

    var cur = data.currency || 'UGX';
    var groups = {};
    items.forEach(function (it) {
      var g = it.kind === 'kit' ? 'Kit' : (it.category || 'Other');
      (groups[g] = groups[g] || []).push(it);
    });
    var names = Object.keys(groups).sort(function (a, b) {
      var ia = ORDER.indexOf(a), ib = ORDER.indexOf(b);
      ia = ia < 0 ? 99 : ia; ib = ib < 0 ? 99 : ib;
      return ia - ib || a.localeCompare(b);
    });

    host.innerHTML = names.map(function (g) {
      var h = HEADING[g] || [g, ''];
      return '<div class="shop-group"><h2>' + esc(h[0]) + '</h2>' +
             (h[1] ? '<p>' + esc(h[1]) + '</p>' : '') + '</div>' +
             '<div class="shop-grid">' + groups[g].map(function (i) { return card(i, cur); }).join('') + '</div>';
    }).join('');
    host.setAttribute('data-rendered', String(items.length));
  }

  var ctrl = ('AbortController' in window) ? new AbortController() : null;
  if (ctrl) setTimeout(function () { ctrl.abort(); }, 8000);
  fetch(ENDPOINT, { signal: ctrl && ctrl.signal })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(render)
    .catch(function () { /* the page's own fallback stays */ });
})();
