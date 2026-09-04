/*
 * Shared app-shell behavior for every "inside the app" page (dashboard, meldungen,
 * meldung-detail, routes-placeholder). Auth pages (login/verify-2fa/forgot-password/...) don't
 * include this file -- they have no shell.
 *
 * Sidebar-collapsed state persists via localStorage only so the choice survives clicking between
 * pages. The top-bar user menu does talk to the real backend (POST /auth/logout) -- see below.
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

    /* ---- Topbar user menu: real logout (POST /auth/logout, then back to /login) ---- */
    var userMenu = document.getElementById('admUserMenu');
    if (userMenu) {
      userMenu.addEventListener('click', function () {
        fetch('/auth/logout', { method: 'POST' }).finally(function () {
          window.location.href = '/login';
        });
      });
    }
  });
})();
