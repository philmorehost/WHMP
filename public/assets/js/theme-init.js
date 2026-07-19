// Applies a previously-chosen theme before first paint, so switching pages
// doesn't flash the OS-default theme for a frame before app.js (deferred)
// gets a chance to run. Loaded synchronously in <head>, before the CSS
// links — kept in its own tiny external file (not an inline <script>)
// because the CSP is script-src 'self' with no 'unsafe-inline' (R11).
(function () {
    'use strict';
    try {
        var stored = localStorage.getItem('cv-theme');
        if (stored === 'dark' || stored === 'light') {
            document.documentElement.setAttribute('data-theme', stored);
        }
    } catch (e) {
        // Storage disabled/unavailable — falls back to prefers-color-scheme.
    }
})();
