/**
 * ═══════════════════════════════════════════════════════════════════════════
 * mobile-nav.js — progressive-enhancement hamburger for the shared .navbar
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The site's navbar is a flat flex row of 8 links. That is fine on desktop but
 * unusable on a phone (the links overflow the 56px bar). This script injects a
 * hamburger toggle into every page's .navbar and lets the accompanying CSS
 * (see css/main.css → "Mobile nav") collapse the links into a dropdown below
 * ~820px. It is a no-op when the navbar or link list is absent, so it can be
 * dropped onto every page unconditionally without coupling to page scripts.
 *
 * Plain (non-module) script, loaded with `defer`, so ordering vs. the page's
 * ES-module logic does not matter and it never blocks rendering.
 */
(function () {
  'use strict';

  function init() {
    var nav = document.querySelector('.navbar');
    if (!nav) return;
    var links = nav.querySelector('.nav-links');
    if (!links || nav.querySelector('.nav-toggle')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nav-toggle';
    btn.setAttribute('aria-label', 'Toggle navigation menu');
    btn.setAttribute('aria-controls', 'nav-links');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span></span><span></span><span></span>';
    if (!links.id) links.id = 'nav-links';
    nav.appendChild(btn); // sits on the right; CSS controls visibility

    function setOpen(open) {
      nav.classList.toggle('nav-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!nav.classList.contains('nav-open'));
    });

    // Collapse after choosing a destination.
    links.addEventListener('click', function (e) {
      if (e.target.closest('a')) setOpen(false);
    });

    // Tap outside the navbar closes the menu.
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('nav-open') && !nav.contains(e.target)) setOpen(false);
    });

    // Never leave a mobile dropdown stuck open when rotating up to desktop width.
    window.addEventListener('resize', function () {
      if (window.innerWidth > 820) setOpen(false);
    });

    // Esc closes it too.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setOpen(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
