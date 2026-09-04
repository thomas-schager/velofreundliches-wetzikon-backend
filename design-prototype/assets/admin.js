/*
 * Shared app-shell behavior for every "inside the app" prototype page (dashboard, meldungen,
 * meldung-detail, routes-placeholder). Auth pages (login/verify-2fa/forgot-password/...) don't
 * include this file -- they have no shell.
 *
 * Prototype note: nothing here talks to a real backend. Sidebar-collapsed state persists via
 * localStorage only so the choice survives clicking between pages during review.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();

    var shell = document.querySelector('.adm-shell');
    if (!shell) return;

    /* ---- Collapse (desktop) ---- */
    var collapseBtn = document.getElementById('admCollapseBtn');
    var COLLAPSE_KEY = 'adm-sidebar-collapsed';
    if (localStorage.getItem(COLLAPSE_KEY) === '1') {
      shell.classList.add('is-collapsed');
    }
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function () {
        var collapsed = shell.classList.toggle('is-collapsed');
        localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
      });
    }

    /* ---- Mobile drawer ---- */
    var burger = document.getElementById('admBurger');
    var scrim = shell.querySelector('.adm-mobile-scrim');
    function openMobile() { shell.classList.add('is-mobile-open'); }
    function closeMobile() { shell.classList.remove('is-mobile-open'); }
    if (burger) burger.addEventListener('click', openMobile);
    if (scrim) scrim.addEventListener('click', closeMobile);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMobile();
    });

    /* ---- Active nav link (based on body[data-page]) ---- */
    var page = document.body.getAttribute('data-page');
    if (page) {
      var link = shell.querySelector('.adm-navlink[data-page="' + page + '"]');
      if (link) link.classList.add('is-active');
    }

    /* ---- Topbar user menu: click to expand, "Abmelden" navigates to login.html
       (decorative in this prototype -- the real app's version posts to /auth/logout). */
    var userMenu = document.getElementById('admUserMenu');
    var userMenuTrigger = document.getElementById('admUserMenuTrigger');
    var logoutBtn = document.getElementById('admLogoutBtn');
    if (userMenu && userMenuTrigger) {
      function closeUserMenu() {
        userMenu.classList.remove('is-open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
      }
      function toggleUserMenu() {
        var open = userMenu.classList.toggle('is-open');
        userMenuTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      userMenuTrigger.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleUserMenu();
      });
      document.addEventListener('click', function (e) {
        if (!userMenu.contains(e.target)) closeUserMenu();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeUserMenu();
      });
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
          window.location.href = 'login.html';
        });
      }
    }
  });
})();
