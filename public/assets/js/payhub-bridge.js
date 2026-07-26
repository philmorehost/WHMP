/**
 * PayHub SDK Bridge
 *
 * PayHub's inline.js (https://merchant.payhub.com.ng/inline.js) declares:
 *   const PayhubPop = { ... }
 *
 * A `const` at the top level of a classic (non-module) script is scoped to
 * that script's execution context and is NOT automatically attached to the
 * `window` object. Code in other scripts that checks `window.PayhubPop` will
 * always see `undefined` even after inline.js has loaded and executed.
 *
 * This bridge script is loaded AFTER inline.js (app.js appends it in the
 * sdk.onload callback). Because it runs in the same global scope (classic
 * scripts share globalThis), `PayhubPop` is already in scope here and we can
 * assign it to `window`, making it visible to all subsequent code.
 *
 * This file is hosted on 'self' so it is permitted by the application's
 * Content-Security-Policy (script-src 'self' ...) without needing unsafe-inline.
 */

/* jshint esversion: 6 */
/* global PayhubPop */

if (typeof PayhubPop !== 'undefined') {
    window.PayhubPop = PayhubPop;
}
