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
 *   <div data-dishnet-order-flow></div>   address-first order flow (homepage):
 *                                         step 1 hardware, step 2 plan, then a
 *                                         WhatsApp checkout carrying the
 *                                         visitor's address/pin (window.DN_ADDR)
 *   <span data-live-price="NAME"></span>  one product's price, by uCRM name
 *
 * data-name-map on the script tag maps uCRM plan names to the display names
 * customers researched on starlink.com (e.g. "DishNet Home" -> "Residential").
 * uCRM names stay the billing truth; the label changes, the invoice doesn't.
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

  // Headline plans first (data-name-map order), then everything else in feed
  // order — so adding plans in uCRM (e.g. Business tiers) never reshuffles
  // the familiar trio at the front.
  var NAMEMAP = {};
  try { NAMEMAP = JSON.parse(script.getAttribute('data-name-map') || '{}'); } catch (e) {}
  // "DishNet Business 500" -> "500 GB", "DishNet Business 1TB" -> "1 TB".
  // Business tiers are Starlink Local Priority: a priority-data block plus a
  // public IP, with unlimited standard data after — never call them unlimited.
  function bizQty(name) {
    var m = name.match(/business\s*(\d+\s*tb|\d+)/i);
    if (!m) return '';
    return /tb/i.test(m[1]) ? m[1].replace(/\s*tb/i, ' TB') : m[1] + ' GB';
  }

  function headlineFirst(plans) {
    var keys = Object.keys(NAMEMAP);
    if (!keys.length) return plans;
    var head = [], rest = plans.slice();
    keys.forEach(function (k) {
      for (var i = 0; i < rest.length; i++) {
        if (rest[i].name === k) { head.push(rest.splice(i, 1)[0]); break; }
      }
    });
    return head.concat(rest);
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
      grid.innerHTML = headlineFirst(data.plans).map(function (p) {
        var flex = /flex/i.test(p.name);
        var biz  = /business/i.test(p.name);
        var best = p.name.toLowerCase() === FEATURED;
        return '<article class="price-card' + (best ? ' price-card-best' : '') + '">' +
          (best ? '<span class="price-pill">Best value</span>' : '') +
          (!best && flex ? '<span class="price-pill price-pill-soft">UGX 0 upfront</span>' : '') +
          (!best && biz ? '<span class="price-pill price-pill-soft">Public IP</span>' : '') +
          '<h3>' + esc(p.name) + '</h3>' +
          '<div class="price-amount">' + fmt(cur, p.price) + '<small>/' + (p.period || 'month') + '</small></div>' +
          (flex ? '<p class="price-desc">UGX 0 hardware upfront — Starlink Mini + connectivity + professional installation + DishNet support.</p>'
           : biz ? '<p class="price-desc">' + esc(bizQty(p.name)) + ' priority data + public IP — unlimited standard data after · for offices, CCTV &amp; heavy users.</p>'
                 : '<p class="price-desc">Unlimited data · professional installation available · DishNet local support.</p>') +
          (p.speed ? '<p class="price-speed">Up to ' + esc(String(p.speed)) + ' Mbps</p>' : '') +
          '<a class="btn btn-primary" href="https://wa.me/' + WA +
            '?text=' + encodeURIComponent('Hello DishNet, I would like to sign up for ' + p.name) +
          '">Get ' + esc(p.name.replace(/^DishNet\s*/i, '')) + '</a>' +
          '</article>';
      }).join('') + '<p class="price-vat">' + esc(data.vat_note || 'All prices VAT inclusive') + '</p>';
    }

    renderOrderFlow(data, cur);
  }

  // ── Address-first order flow (Starlink-style: hardware, then plan) ──
  function renderOrderFlow(data, cur) {
    var host = document.querySelector('[data-dishnet-order-flow]');
    if (!host || !(data.plans || []).length) return;
    var MAP = {};
    try { MAP = JSON.parse(script.getAttribute('data-name-map') || '{}'); } catch (e) {}

    var kits = (data.hardware || []).filter(function (h) { return /package/i.test(h.name); });
    var flexPlans = data.plans.filter(function (p) { return /flex/i.test(p.name); });
    var stdPlans  = headlineFirst(data.plans.filter(function (p) { return !/flex/i.test(p.name); }));
    var state = { hw: null, plan: null };

    function kitImg(name) {
      if (/mini/i.test(name)) return 'assets/img/products/mini-kit.webp';
      if (/standard/i.test(name)) return 'assets/img/products/standard-kit.webp';
      return '';
    }
    function disp(p) { return MAP[p.name] || p.name; }

    function hwCards() {
      var h = kits.map(function (k) {
        return '<button type="button" class="of-card" data-hw="' + esc(k.name) + '">' +
          (kitImg(k.name) ? '<img src="' + kitImg(k.name) + '" alt="' + esc(k.name) + '" loading="lazy">' : '') +
          '<h4>' + esc(k.name) + '</h4>' +
          '<div class="of-sub">' + esc(k.description || 'Kit + delivery + professional installation + first month of internet included.') + '</div>' +
          '<div class="of-price">' + esc(fmt(cur, k.price)) + ' <small>one-time, VAT inclusive</small></div>' +
          '</button>';
      }).join('');
      if (flexPlans.length) {
        h += '<button type="button" class="of-card" data-hw="__flex">' +
          '<span class="of-tag">' + esc(cur) + ' 0 upfront</span>' +
          '<h4>Rent with Flex</h4>' +
          '<div class="of-sub">No kit to buy — Starlink Mini, installation and internet in one monthly price.</div>' +
          '<div class="of-price">Pay monthly <small>choose your plan next</small></div>' +
          '</button>';
      }
      h += '<button type="button" class="of-card" data-hw="__own">' +
        '<h4>I already have a kit</h4>' +
        '<div class="of-sub">Bring your own Starlink — we connect it to a DishNet plan with local support and mobile-money billing.</div>' +
        '<div class="of-price">No hardware needed</div>' +
        '</button>';
      return h;
    }

    function planCards() {
      var list = state.hw === '__flex' ? flexPlans : stdPlans;
      return list.map(function (p) {
        var d = disp(p);
        var biz = /business/i.test(p.name);
        return '<button type="button" class="of-card" data-plan="' + esc(p.name) + '">' +
          '<h4>' + esc(d) + '</h4>' +
          (d !== p.name ? '<div class="of-sub">' + esc(p.name) + ' plan</div>'
           : biz ? '<div class="of-sub">' + esc(bizQty(p.name)) + ' priority data · public IP included</div>'
                 : '<div class="of-sub">Monthly, cancel anytime</div>') +
          '<div class="of-price">' + esc(fmt(cur, p.price)) + ' <small>/' + esc(p.period || 'month') + '</small></div>' +
          (biz ? '<div class="of-sub" style="margin-top:6px;">Unlimited standard data after priority data</div>'
               : p.speed ? '<div class="of-sub" style="margin-top:6px;">Unlimited data — up to ' + esc(String(p.speed)) + ' Mbps</div>' : '') +
          '</button>';
      }).join('');
    }

    function hwLabel() {
      if (state.hw === '__flex') return 'Flex (kit included, ' + cur + ' 0 upfront)';
      if (state.hw === '__own') return 'Using my own Starlink kit';
      return state.hw;
    }

    // Starlink-receipt-style order lines: [label, amount-or-"Included"], plus totals.
    function receipt() {
      var kit = kits.filter(function (k) { return k.name === state.hw; })[0] || null;
      var rows = [], today = 0, monthly = state.plan.price, recurNote;
      if (kit) {
        rows.push([kit.name, fmt(cur, kit.price)]);
        rows.push(['Delivery & professional installation', 'Included']);
        rows.push(['First month of internet', 'Included']);
        today = kit.price;
        recurNote = 'Then ' + fmt(cur, monthly) + '/month from month 2';
      } else if (state.hw === '__flex') {
        rows.push([disp(state.plan) + ' — Starlink Mini + installation + internet', fmt(cur, monthly) + '/month']);
        rows.push(['Hardware upfront', fmt(cur, 0)]);
        today = monthly;
        recurNote = 'Then ' + fmt(cur, monthly) + ' every month';
      } else {
        rows.push([disp(state.plan) + ' — first month', fmt(cur, monthly)]);
        rows.push(['Connecting your own Starlink kit', 'Our team confirms']);
        today = monthly;
        recurNote = 'Then ' + fmt(cur, monthly) + ' every month';
      }
      return '<div class="of-receipt">' +
        rows.map(function (r) {
          return '<div class="of-rrow' + (r[1] === 'Included' || r[1] === 'Our team confirms' ? ' inc' : '') + '">' +
            '<span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
        }).join('') +
        '<div class="of-rrow total"><span>Total today</span><span>' + esc(fmt(cur, today)) + '</span></div>' +
        '<div class="of-rnote">' + esc(recurNote) + ' · ' + esc(data.vat_note || 'All prices VAT inclusive') + '</div>' +
        '</div>';
    }

    function paint() {
      var html =
        '<div class="of-step">Step 1</div><div class="of-title">Choose your hardware</div>' +
        '<div class="of-grid" data-of-hw>' + hwCards() + '</div>';
      if (state.hw) {
        html +=
          '<div class="of-step">Step 2</div><div class="of-title">Choose your plan</div>' +
          '<div class="of-grid" data-of-plan>' + planCards() + '</div>';
      }
      html +=
        '<div class="of-summary">' +
          '<div class="of-sumtext" data-of-sum>' +
            (state.hw && state.plan ? receipt() :
              state.hw ? '<b>' + esc(hwLabel()) + '</b> — now choose a plan' :
              'Pick your hardware to get started — prices are live from our billing system.') +
          '</div>' +
          '<button type="button" class="of-wa" data-of-wa' + (state.hw && state.plan ? '' : ' disabled') + '>Complete order on WhatsApp</button>' +
        '</div>' +
        '<p class="of-vat">A team member confirms everything on WhatsApp before you pay — the total shown is the total invoiced.</p>';
      host.innerHTML = html;
      Array.prototype.forEach.call(host.querySelectorAll('[data-hw]'), function (el) {
        if (state.hw && el.getAttribute('data-hw') === state.hw) el.classList.add('sel');
      });
      Array.prototype.forEach.call(host.querySelectorAll('[data-plan]'), function (el) {
        if (state.plan && el.getAttribute('data-plan') === state.plan.name) el.classList.add('sel');
      });
    }

    host.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target : null;
      if (!t) return;
      var hw = t.closest('[data-hw]');
      if (hw) {
        state.hw = hw.getAttribute('data-hw');
        state.plan = null;
        paint();
        var p2 = host.querySelector('[data-of-plan]');
        if (p2) p2.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
      }
      var pl = t.closest('[data-plan]');
      if (pl) {
        var name = pl.getAttribute('data-plan');
        state.plan = (state.hw === '__flex' ? flexPlans : stdPlans).filter(function (p) { return p.name === name; })[0] || null;
        paint();
        return;
      }
      var wa = t.closest('[data-of-wa]');
      if (wa && state.hw && state.plan) {
        var kit = kits.filter(function (k) { return k.name === state.hw; })[0] || null;
        var today = kit ? kit.price : state.plan.price;
        var extra = (typeof window.DN_ADDR === 'function') ? window.DN_ADDR() : '';
        var txt = 'Hello DishNet, I would like to order Starlink.' +
          '\nHardware: ' + hwLabel() +
          '\nPlan: ' + disp(state.plan) + (disp(state.plan) !== state.plan.name ? ' (' + state.plan.name + ')' : '') +
          '\nTotal today: ' + fmt(cur, today) + ' · then ' + fmt(cur, state.plan.price) + '/month' +
          (extra ? '\n' + extra : '');
        window.open('https://wa.me/' + WA + '?text=' + encodeURIComponent(txt), '_blank');
      }
    });

    paint();
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
