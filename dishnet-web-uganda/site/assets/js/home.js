/* home.js — the homepage only (index.html): menu, header, reveal, industry tabs, kit guide, and the
   consultation form, which prepares a WhatsApp message and sends nothing itself. No inline handlers. */
(function () {
  'use strict';
  document.body.classList.remove('no-js');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var WA = '256705993348';   // the one WhatsApp number every enquiry goes to (verify-site.sh asserts it)

  /* Header shadow once the page scrolls */
  var header = document.querySelector('.site-header');
  var onScroll = function () { header.classList.toggle('scrolled', window.scrollY > 8); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  /* Mobile menu */
  var menu = document.getElementById('mobile-menu');
  var openBtn = document.querySelector('.nav-cta .menu-btn');
  var closeBtn = menu.querySelector('.menu-close');
  // Closed, the menu is inert (it is only faded out, so Tab would otherwise still reach its links); open, everything
  // beside it, at every level up to <body>, is inert instead. Only what this script made inert is released (as nav.js).
  var held = [];
  function background(off) {
    if (!off) { held.forEach(function (el) { el.inert = false; }); held = []; return; }
    for (var node = menu; node.parentElement && node !== document.body; node = node.parentElement) {
      Array.prototype.forEach.call(node.parentElement.children, function (el) {
        if (el === node || el.inert || /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(el.tagName)) return;
        el.inert = true; held.push(el);
      });
    }
  }
  function setMenu(open, keepFocus) {
    menu.classList.toggle('open', open);
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    menu.inert = !open;
    background(open);
    openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
    if (keepFocus) return;
    if (open) { closeBtn.focus(); } else { openBtn.focus(); }
  }
  menu.inert = true;
  openBtn.addEventListener('click', function () { setMenu(true); });
  closeBtn.addEventListener('click', function () { setMenu(false); });
  menu.addEventListener('click', function (e) { if (e.target === menu) setMenu(false); });
  menu.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setMenu(false, true); }); });
  // Widened past the phone layout (home.css hides the menu above 1140px) with the menu open: close it, or the page
  // would stay inert behind a menu nobody can see.
  var narrow = window.matchMedia ? window.matchMedia('(max-width: 1140px)') : null;
  var onWide = function (e) { if (!e.matches && menu.classList.contains('open')) setMenu(false, true); };
  if (narrow && narrow.addEventListener) narrow.addEventListener('change', onWide);
  else if (narrow && narrow.addListener) narrow.addListener(onWide);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu.classList.contains('open')) setMenu(false); });

  /* Reveal on scroll */
  var items = document.querySelectorAll('.reveal');
  if (reduce || !('IntersectionObserver' in window)) { items.forEach(function (el) { el.classList.add('in'); }); }
  else {
    var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }); }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach(function (el) { io.observe(el); });
  }

  /* Industry tabs (ARIA tabs: click, or the arrow / Home / End keys) */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
  function select(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
  }
  if (tabs.length) select(tabs[0], false);   // without JavaScript every panel stays visible, one under the other
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { select(t, false); });
    t.addEventListener('keydown', function (e) {
      var k = e.key, n = null;
      if (k === 'ArrowDown' || k === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
      else if (k === 'ArrowUp' || k === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
      else if (k === 'Home') n = tabs[0]; else if (k === 'End') n = tabs[tabs.length - 1];
      if (n) { e.preventDefault(); select(n, true); }
    });
  });

  /* Kit guide: the suggestions and their wording */
  var guide = document.getElementById('kit-guide');
  function kitGuide() {
    var v = function (n) { var c = guide.querySelector('input[name="' + n + '"]:checked'); return c ? c.value : ''; };
    var where = v('where'), users = v('users'), power = v('power'), pick, why;
    if (where === 'multi' || users === 'critical') {
      pick = 'High Performance, or a planned network, may suit you';
      why = 'Several sites, many users or a connection that cannot go down call for planning: we look at each site, its users and the right plan before recommending equipment.';
    } else if (where === 'move') {
      pick = users === 'many' ? 'Mini or Standard — let us check' : 'Starlink Mini may suit you';
      why = users === 'many' ? 'Mini is built for portability; for a larger group at a temporary site, Standard may serve you better. An adviser can compare the two for your use.' : 'Mini fits in a backpack and powers from USB-C or a solar bank — made for field teams, travel and temporary locations.';
    } else {
      pick = 'Starlink Standard may suit you';
      why = users === 'many' ? 'For a fixed site with a bigger group, Standard is the usual starting point — and Wi-Fi coverage planned for every room or building that needs it.' : 'For a fixed home or office with a handful of users, Standard is the usual choice — mounted, aligned and connected to your Wi-Fi by our technicians.';
    }
    document.getElementById('guide-pick').textContent = pick;
    document.getElementById('guide-why').textContent = why;
    document.getElementById('guide-power').textContent = power === 'solar' ? 'Off-grid or unreliable power? We can advise on solar-plus-battery setups for your site.' : (power === 'unsure' ? 'Not sure about power? We check it as part of the consultation.' : '');
  }
  guide.addEventListener('change', kitGuide); kitGuide();
  var guideForm = guide.querySelector('form'); if (guideForm) guideForm.addEventListener('submit', function (e) { e.preventDefault(); });

  /* Consultation form: it validates, then prepares the WhatsApp message. The page sends and stores nothing;
     the enquiry reaches DishNet only when the visitor sends that message in WhatsApp, so nothing here says it was received. */
  var form = document.getElementById('consult-form');
  var result = document.getElementById('consult-result');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ok = true, first = null;
    form.querySelectorAll('.field').forEach(function (f) {
      var el = f.querySelector('input, select, textarea'); if (!el) return;
      var val = (el.value || '').trim();
      var bad = (el.required && !val) || (el.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val));
      f.classList.toggle('invalid', bad);
      el.setAttribute('aria-invalid', bad ? 'true' : 'false');
      if (bad) { ok = false; if (!first) first = el; }
    });
    if (!ok) { first.focus(); result.classList.remove('show'); return; }
    var d = new FormData(form), g = function (k) { return (d.get(k) || '').toString().trim(); };
    var lines = ['Hi DishNet, I would like to book my free ICT consultation.', '',
      'Name: ' + g('name')];
    if (g('organisation')) lines.push('Organisation: ' + g('organisation'));
    lines.push('Phone/WhatsApp: ' + g('phone'));
    if (g('email')) lines.push('Email: ' + g('email'));
    lines.push('Location: ' + g('location'), 'Customer type: ' + g('customer_type'), 'Assistance needed: ' + g('assistance'), '', 'Requirements: ' + g('requirements'));
    var text = lines.join('\n');
    document.getElementById('consult-preview').textContent = text;
    document.getElementById('consult-wa').href = 'https://wa.me/' + WA + '?text=' + encodeURIComponent(text);
    result.classList.add('show'); result.focus();
  });
  form.querySelectorAll('input, select, textarea').forEach(function (el) {
    el.addEventListener('input', function () { var f = el.closest('.field'); if (f && f.classList.contains('invalid')) { f.classList.remove('invalid'); el.setAttribute('aria-invalid', 'false'); } });
  });

})();
