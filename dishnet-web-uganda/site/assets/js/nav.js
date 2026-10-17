/*
 * nav.js — the mobile menu, on every page.
 *
 * Replaces the inline onclick="" handlers the pages used to carry. The toggle
 * reports its state (aria-expanded) and names what it controls
 * (aria-controls="mobileNav"); Escape closes the menu; closing returns focus
 * to the toggle, so a keyboard or screen-reader user is not left on a button
 * that has just disappeared. While the menu is open it covers the whole page,
 * so everything behind it is inert: Tab, Shift+Tab and a screen reader stay
 * in the menu instead of reaching links hidden under it.
 * Same IDs (mToggle, mClose) as become-a-distributor.html, which keeps its
 * own simpler script (no Escape, no focus handling, no aria-controls).
 *
 *   <button class="mobile-toggle" id="mToggle" aria-label="Open menu"
 *           aria-expanded="false" aria-controls="mobileNav">
 *   <div class="mobile-nav" id="mobileNav">
 *     <button class="mobile-nav-close" id="mClose" aria-label="Close menu">
 */
(function () {
  'use strict';
  var menu = document.getElementById('mobileNav');
  var toggle = document.getElementById('mToggle');
  var close = document.getElementById('mClose');
  if (!menu || !toggle) return;

  function isOpen() { return menu.classList.contains('open'); }

  // Everything beside the menu, at every level up to <body>, is made inert
  // while it is open; only what this script made inert is released again.
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

  function open() {
    menu.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
    background(true);
    if (close) close.focus();
  }

  function shut(returnFocus) {
    if (!isOpen()) return;
    menu.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    background(false);
    if (returnFocus) toggle.focus();
  }

  toggle.addEventListener('click', function () { if (isOpen()) shut(true); else open(); });
  if (close) close.addEventListener('click', function () { shut(true); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') shut(true);
  });
  // A link in the menu leaves the page or jumps within it; either way the
  // menu should not still be covering the screen.
  menu.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (a) shut(false);
  });
})();
