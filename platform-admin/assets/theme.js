/* ProCast Platform Admin — theme.js
 *
 * Light/dark theme bootstrap for the super admin portal.
 *
 * WHY THIS IS A SEPARATE FILE instead of an inline <script> in <head>:
 * the portal sends `Content-Security-Policy: default-src 'none'; script-src
 * 'self'; …` with no 'unsafe-inline' and no nonce (see
 * app/Middleware/SuperAdminGuard.php). An inline bootstrap would be blocked by
 * the browser, leaving the stored theme unapplied on every page load. It is
 * whitelisted in the Kernel's ASSETS map and loaded synchronously BEFORE
 * admin.css, so the class is already on <html> when the first paint happens and
 * the page never flashes dark-then-light.
 *
 * Kept self-contained (no dependency on admin.js) because the standalone login
 * page loads no other script.
 */
(function () {
  'use strict';

  var KEY = 'pa_theme';
  var root = document.documentElement;

  function isLight() {
    return root.classList.contains('theme-light');
  }

  function stored() {
    try {
      return localStorage.getItem(KEY);
    } catch (e) {
      return null; // storage blocked (private mode / hardened browser)
    }
  }

  function remember(value) {
    try {
      localStorage.setItem(KEY, value);
    } catch (e) {
      /* Not fatal: the choice still applies for this page, it just won't persist. */
    }
  }

  // Dark is the default. The portal has always been dark-only, so an unset or
  // unreadable key must NOT flip a first-time operator into light mode.
  root.classList.toggle('theme-light', stored() === 'light');

  /**
   * @param {'light'|'dark'} theme
   * @param {boolean} persist write the choice to localStorage
   */
  window.paApplyTheme = function (theme, persist) {
    var light = theme === 'light';
    root.classList.toggle('theme-light', light);
    if (persist) {
      remember(light ? 'light' : 'dark');
    }
    syncControls();
  };

  window.paToggleTheme = function () {
    window.paApplyTheme(isLight() ? 'dark' : 'light', true);
  };

  // The sun/moon swap is done in CSS (see .pa-theme-toggle in admin.css), so a
  // toggle is correct the instant the class changes and needs no JS repaint. All
  // that is left is the accessible name, which CSS cannot express. Queryed by
  // attribute rather than id because both the layout and the login page render
  // their own button.
  function syncControls() {
    var label = isLight() ? 'Switch to dark mode' : 'Switch to light mode';
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('title', label);
      buttons[i].setAttribute('aria-label', label);
      buttons[i].setAttribute('aria-pressed', isLight() ? 'true' : 'false');
    }
  }

  function bind() {
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function () {
        window.paToggleTheme();
      });
    }
    syncControls();
  }

  // This file runs in <head>, so the buttons have not been parsed yet.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();