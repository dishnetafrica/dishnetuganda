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
 *   <div data-dishnet-hardware></div>     every kit in uCRM, priced, as cards
 *   <span data-dishnet-from></span>       "from UGX X/month", cheapest live plan
 *
 * A price with no unit is the thing customers actually complained about: a
 * kit card read "UGX 1,850,000 / VAT inclusive" and nothing on the page said
 * whether that was once or every month, or that a monthly plan is due as well.
 * Hardware now always carries "one-off" and the monthly it sits alongside.
 *
 * Nothing here is ever blank. A slot whose product is missing from the feed
 * keeps whatever the page already had inside it — so every slot in the HTML
 * ships with a real "ask us on WhatsApp" link, and a feed outage degrades to
 * that instead of to a hole where the price should be.
 *
 * One naming system: the DishNet name from uCRM IS the display name on every
 * channel (site, chat, WhatsApp, invoice). data-service-map on the script tag
 * maps each plan to its underlying Starlink service (e.g. "DishNet Home" ->
 * "Residential"), shown as a small transparency line — never as the headline,
 * which would invite a straight price comparison with starlink.com instead of
 * a comparison of the DishNet bundle.
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

  // Headline plans first (data-service-map order), then everything else in
  // feed order — so adding plans in uCRM (e.g. Business tiers) never
  // reshuffles the familiar trio at the front.
  var SVCMAP = {};
  try { SVCMAP = JSON.parse(script.getAttribute('data-service-map') || '{}'); } catch (e) {}
  // "DishNet Business 500" -> "500 GB", "DishNet Business 1TB" -> "1 TB".
  // Business tiers are Starlink Local Priority: a priority-data block plus a
  // public IP, with unlimited standard data after — never call them unlimited.
  function bizQty(name) {
    var m = name.match(/business\s*(\d+\s*tb|\d+)/i);
    if (!m) return '';
    return /tb/i.test(m[1]) ? m[1].replace(/\s*tb/i, ' TB') : m[1] + ' GB';
  }

  // Home or business, decided once.
  //
  // Two independent signals, because either alone goes stale: the DishNet
  // name (what we sell), and the Starlink service it maps to (Local Priority
  // is the business tier — a priority-data block plus a public IP). A tier
  // added in uCRM under a different name still lands in the right group as
  // long as the service map is updated, and vice versa.
  //
  // Neither signal present means home, which is the safer default: a
  // household shown a business card sees a price that is not for them, while
  // a business shown the home cards still has a "Business internet" heading
  // above the ones that are.
  function isBusiness(p) {
    return /business/i.test(p.name) || /priority/i.test(SVCMAP[p.name] || '');
  }

  function headlineFirst(plans) {
    var keys = Object.keys(SVCMAP);
    if (!keys.length) return plans;
    var head = [], rest = plans.slice();
    keys.forEach(function (k) {
      for (var i = 0; i < rest.length; i++) {
        if (rest[i].name === k) { head.push(rest.splice(i, 1)[0]); break; }
      }
    });
    return head.concat(rest);
  }

  // Product photography by name. A kit with no picture still renders — the
  // card just has no image, rather than a broken one.
  function kitImage(name) {
    if (/mini/i.test(name))                 return 'assets/img/products/mini-kit.webp';
    if (/high\s*performance|performance/i.test(name)) return 'assets/img/products/hp-kit.webp';
    if (/standard/i.test(name))             return 'assets/img/products/standard-kit.webp';
    return '';
  }

  // One shape for every price on the site, so "one-off" never has to be
  // inferred from context by the person reading it.
  function priceBlock(cur, amount, kind, fromMonthly) {
    if (kind === 'month') {
      return '<span class="lp-amount">' + fmt(cur, amount) + '<small>/month</small></span>' +
             '<span class="lp-note">VAT inclusive</span>';
    }
    return '<span class="lp-amount">' + fmt(cur, amount) + '</span>' +
           '<span class="lp-kind">one-off payment</span>' +
           (fromMonthly ? '<span class="lp-then">then internet from <strong>' + fromMonthly + '</strong></span>' : '') +
           '<span class="lp-note">VAT inclusive &middot; includes delivery, installation and your first month</span>';
  }

  function render(data) {
    var cur = data.currency || 'UGX';

    // Cheapest live plan — the "and then what?" every hardware price needs.
    var cheapest = (data.plans || []).filter(function (p) { return !isBusiness(p); })
                                     .sort(function (a, b) { return a.price - b.price; })[0]
                || (data.plans || [])[0] || null;
    var fromMonthly = cheapest ? fmt(cur, cheapest.price) + '/month' : '';

    Array.prototype.forEach.call(document.querySelectorAll('[data-dishnet-from]'), function (el) {
      if (fromMonthly) el.textContent = fromMonthly;
    });

    // Individual price slots (kit cards etc.)
    var byName = {}, kindOf = {};
    (data.plans || []).forEach(function (i) { byName[i.name.toLowerCase()] = i; kindOf[i.name.toLowerCase()] = 'month'; });
    (data.hardware || []).forEach(function (i) { byName[i.name.toLowerCase()] = i; kindOf[i.name.toLowerCase()] = 'once'; });

    Array.prototype.forEach.call(document.querySelectorAll('[data-live-price]'), function (el) {
      var key  = (el.getAttribute('data-live-price') || '').toLowerCase();
      var item = byName[key];
      // No match: leave the page's own fallback standing. Never blank it.
      if (!item) return;
      var kind = el.getAttribute('data-live-price-kind') || kindOf[key] || 'once';
      el.innerHTML = priceBlock(cur, item.price, kind, fromMonthly);
    });

    // Every kit uCRM sells, priced, in feed order.
    //
    // The page used to hand-list three kits and hard-code which two had a
    // price slot — so the third card showed features and no price at all, and
    // a customer comparing them could not. Adding a product in uCRM now makes
    // it appear here with its price; removing it takes the card away.
    var hwGrid = document.querySelector('[data-dishnet-hardware]');
    if (hwGrid && (data.hardware || []).length) {
      var hwCard = function (h) {
        var img = kitImage(h.name);
        var label = h.name.replace(/^Starlink\s*/i, '').replace(/\s*Package$/i, '');
        return '<article class="hw-card">' +
          (img ? '<div class="hw-shot"><img src="' + img + '" alt="' + esc(h.name) + '" loading="lazy"></div>' : '') +
          '<h3>' + esc(label) + '</h3>' +
          '<div class="live-price">' + priceBlock(cur, h.price, 'once', fromMonthly) + '</div>' +
          (h.description ? '<p class="hw-desc">' + esc(h.description) + '</p>' : '') +
          '<a class="btn btn-primary hw-cta" href="https://wa.me/' + WA + '?text=' +
            encodeURIComponent('Hello DishNet, I would like the ' + h.name + '. Please confirm the total and book installation.') +
          '">Order ' + esc(label) + '</a>' +
          '</article>';
      };
      // Cards marked data-keep survive. A product we genuinely sell but do
      // not list a fixed price for — High Performance is quoted per site —
      // must not vanish just because the feed has no number for it. Replacing
      // the whole grid removed it from the page entirely, which is a worse
      // answer to "what does it cost" than "quoted on request".
      var keep = [];
      Array.prototype.forEach.call(hwGrid.querySelectorAll('[data-keep]'), function (el) {
        keep.push(el.outerHTML);
      });
      hwGrid.innerHTML = (data.hardware || []).map(hwCard).join('') + keep.join('');
      hwGrid.setAttribute('data-rendered', '1');
    }

    // Monthly plans grid, split into home and business.
    //
    // One flat row of seven asked a household to read past three tiers priced
    // for an office with a public IP, and asked an office to guess which of
    // the seven was theirs. The two products answer different questions and
    // now sit under their own headings.
    var grid = document.querySelector('[data-dishnet-plans]');
    if (grid && (data.plans || []).length) {
      var card = function (p) {
        var flex = /flex/i.test(p.name);
        var biz  = isBusiness(p);
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
          (SVCMAP[p.name] ? '<p class="price-map">Starlink service: ' + esc(SVCMAP[p.name]) + '</p>' : '') +
          '<a class="btn btn-primary" href="https://wa.me/' + WA +
            '?text=' + encodeURIComponent('Hello DishNet, I would like to sign up for ' + p.name) +
          '">Get ' + esc(p.name.replace(/^DishNet\s*/i, '')) + '</a>' +
          '</article>';
      };
      var head = function (title, sub) {
        return '<div class="plan-group"><h3>' + esc(title) + '</h3><p>' + esc(sub) + '</p></div>';
      };

      var ordered = headlineFirst(data.plans);
      var homeP = ordered.filter(function (p) { return !isBusiness(p); });
      var bizP  = ordered.filter(isBusiness);

      // Headings only when there is something on both sides of them. A feed
      // carrying one kind of plan renders exactly as it did before, rather
      // than growing a lone heading over the whole grid.
      var body = (homeP.length && bizP.length)
        ? head('Home internet', 'Unlimited data for households — professional installation and local support.')
          + homeP.map(card).join('')
          + head('Business internet', 'Priority data with a public IP, for offices, CCTV and heavy users. Unlimited standard data after the priority block.')
          + bizP.map(card).join('')
        : ordered.map(card).join('');

      grid.innerHTML = body + '<p class="price-vat">' + esc(data.vat_note || 'All prices VAT inclusive') + '</p>';
    }

    renderOrderFlow(data, cur);
  }

  // ── Address-first order flow (Starlink-style: hardware, then plan) ──
  function renderOrderFlow(data, cur) {
    var host = document.querySelector('[data-dishnet-order-flow]');
    if (!host || !(data.plans || []).length) return;

    var kits = (data.hardware || []).filter(function (h) { return /package/i.test(h.name); });
    var flexPlans = data.plans.filter(function (p) { return /flex/i.test(p.name); });
    var stdPlans  = headlineFirst(data.plans.filter(function (p) { return !/flex/i.test(p.name); }));
    var state = { hw: null, plan: null };

    function kitImg(name) {
      if (/mini/i.test(name)) return 'assets/img/products/mini-kit.webp';
      if (/standard/i.test(name)) return 'assets/img/products/standard-kit.webp';
      return '';
    }
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
      var one = function (p) {
        var biz = isBusiness(p);
        return '<button type="button" class="of-card" data-plan="' + esc(p.name) + '">' +
          '<h4>' + esc(p.name) + '</h4>' +
          (SVCMAP[p.name] ? '<div class="of-sub">Starlink service: ' + esc(SVCMAP[p.name]) + '</div>'
           : biz ? '<div class="of-sub">' + esc(bizQty(p.name)) + ' priority data · public IP included</div>'
                 : '<div class="of-sub">Monthly, cancel anytime</div>') +
          '<div class="of-price">' + esc(fmt(cur, p.price)) + ' <small>/' + esc(p.period || 'month') + '</small></div>' +
          (biz ? '<div class="of-sub" style="margin-top:6px;">Unlimited standard data after priority data</div>'
               : p.speed ? '<div class="of-sub" style="margin-top:6px;">Unlimited data — up to ' + esc(String(p.speed)) + ' Mbps</div>' : '') +
          '</button>';
      };
      // Grouped here too. Someone part-way through an order is the last
      // person who should have to work out which three of seven are priced
      // for an office.
      var homeP = list.filter(function (p) { return !isBusiness(p); });
      var bizP  = list.filter(isBusiness);
      if (!homeP.length || !bizP.length) return list.map(one).join('');
      return '<div class="of-group">For home</div>' + homeP.map(one).join('') +
             '<div class="of-group">For business — priority data + public IP</div>' + bizP.map(one).join('');
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
        rows.push([state.plan.name + ' — Starlink Mini + installation + internet', fmt(cur, monthly) + '/month']);
        rows.push(['Hardware upfront', fmt(cur, 0)]);
        today = monthly;
        recurNote = 'Then ' + fmt(cur, monthly) + ' every month';
      } else {
        rows.push([state.plan.name + ' — first month', fmt(cur, monthly)]);
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
          '\nPlan: ' + state.plan.name +
          (SVCMAP[state.plan.name] ? ' (Starlink ' + SVCMAP[state.plan.name] + ')' : '') +
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
