/*
 * Storefront behaviour — reseller websites only (loaded by layouts/client.php
 * when the request is on a store's host).
 *
 * An external file rather than inline <script>: the CSP allows 'self' scripts
 * and nonce'd inline ones, and a file needs neither a nonce nor a view change.
 *
 * Everything here is progressive enhancement. Without JavaScript the menus
 * still open on hover/focus (CSS :hover/:focus-within), the mobile menu is a
 * plain list of links, and every plan tab's panel is reachable from /store.
 */
(function () {
    'use strict';

    function closeAllDropdowns(except) {
        document.querySelectorAll('[data-sf-dropdown].is-open').forEach(function (item) {
            if (item !== except) {
                item.classList.remove('is-open');
                var toggle = item.querySelector('[data-sf-dropdown-toggle]');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    document.addEventListener('click', function (event) {
        // Desktop menus: a click toggles (touch screens have no hover).
        var toggle = event.target.closest('[data-sf-dropdown-toggle]');
        if (toggle) {
            var item = toggle.closest('[data-sf-dropdown]');
            var open = !item.classList.contains('is-open');
            closeAllDropdowns(item);
            item.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }

        if (!event.target.closest('[data-sf-dropdown]')) {
            closeAllDropdowns(null);
        }

        // Mobile drawer.
        var drawerToggle = event.target.closest('[data-sf-drawer-toggle]');
        if (drawerToggle) {
            var drawer = document.querySelector('[data-sf-drawer]');
            if (!drawer) {
                return;
            }
            var opening = drawer.hasAttribute('hidden');
            if (opening) {
                drawer.removeAttribute('hidden');
            } else {
                drawer.setAttribute('hidden', '');
            }
            drawerToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            drawerToggle.setAttribute('aria-label', opening ? 'Close menu' : 'Open menu');
            document.body.classList.toggle('sf-drawer-open', opening);
            return;
        }

        // Plan tabs on the home page.
        var tab = event.target.closest('[data-sf-tab]');
        if (tab) {
            var list = tab.closest('[data-sf-tabs]');
            list.querySelectorAll('[data-sf-tab]').forEach(function (other) {
                var selected = other === tab;
                other.classList.toggle('is-active', selected);
                other.setAttribute('aria-selected', selected ? 'true' : 'false');
                var panel = document.getElementById(other.getAttribute('data-sf-tab'));
                if (panel) {
                    if (selected) {
                        panel.removeAttribute('hidden');
                    } else {
                        panel.setAttribute('hidden', '');
                    }
                }
            });
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAllDropdowns(null);
            var drawer = document.querySelector('[data-sf-drawer]');
            var drawerToggle = document.querySelector('[data-sf-drawer-toggle]');
            if (drawer && !drawer.hasAttribute('hidden')) {
                drawer.setAttribute('hidden', '');
                document.body.classList.remove('sf-drawer-open');
                if (drawerToggle) {
                    drawerToggle.setAttribute('aria-expanded', 'false');
                    drawerToggle.focus();
                }
            }
        }

        // Arrow keys move between plan tabs, as a tablist should.
        var tab = event.target.closest && event.target.closest('[data-sf-tab]');
        if (tab && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
            var tabs = Array.prototype.slice.call(tab.closest('[data-sf-tabs]').querySelectorAll('[data-sf-tab]'));
            var next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            next.focus();
            next.click();
        }
    });

    // The header gains a solid background and shadow once the page scrolls.
    function onScroll() {
        var header = document.querySelector('[data-sf-header]');
        if (header) {
            header.classList.toggle('is-scrolled', window.scrollY > 24);
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('DOMContentLoaded', onScroll);
})();
