// Unobtrusive event delegation (blueprint §5 security hardening) — replaces
// inline onclick/onchange/onsubmit attributes so the CSP can drop
// 'unsafe-inline' from script-src without breaking any interaction.
(function () {
    'use strict';

    // Server admin add/edit form (provisioning/server-form.php): the
    // module-slug select shows/hides the API-username field and relabels
    // the token field per module. This used to be an inline <script> block
    // in the view itself — moved here because CSP's script-src 'self' (no
    // 'unsafe-inline') blocks inline <script> tags exactly the same way it
    // blocks inline event-handler attributes, so that inline block was
    // silently non-functional in any browser actually enforcing the CSP.
    function updateServerModuleFields(select) {
        var form = select.closest('form');
        var usernameField = form && form.querySelector('[data-server-username-field]');
        var tokenLabel = form && form.querySelector('[data-server-token-label]');
        if (!usernameField || !tokenLabel) {
            return;
        }

        var val = select.value;
        if (val === 'interserver-vps') {
            usernameField.style.display = 'none';
            tokenLabel.textContent = 'API Key';
        } else if (val === 'local') {
            usernameField.style.display = 'none';
            tokenLabel.textContent = 'API Token / Password';
        } else {
            usernameField.style.display = 'block';
            tokenLabel.textContent = 'API Token / Password';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var selects = document.querySelectorAll('[data-server-module-select]');
        for (var i = 0; i < selects.length; i++) {
            updateServerModuleFields(selects[i]);
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.matches('[data-auto-submit]')) {
            target.form.submit();
        }
        if (target.matches('[data-server-module-select]')) {
            updateServerModuleFields(target);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Live client-side table filter — an input marked data-table-filter
    // points at a table (by CSS selector) and hides tbody rows that don't
    // contain the typed text, no page reload or server round-trip. Only
    // filters rows already in the DOM, so this is for the app's many
    // unpaginated admin/client list tables, not a substitute for the
    // Clients list's server-side paginated search.
    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!target.matches('[data-table-filter]')) {
            return;
        }

        var table = document.querySelector(target.getAttribute('data-table-filter'));
        if (!table) {
            return;
        }

        var needle = target.value.trim().toLowerCase();
        var rows = table.querySelectorAll('tbody tr');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
        }
    });

    // Dark-mode toggle (R17). theme-init.js already applied any stored
    // choice before paint; this just wires the click and persists it.
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (!toggle) {
            return;
        }

        var root = document.documentElement;
        var current = root.getAttribute('data-theme');
        var isDark = current === 'dark' || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var next = isDark ? 'light' : 'dark';

        root.setAttribute('data-theme', next);

        try {
            localStorage.setItem('cv-theme', next);
        } catch (e) {
            // Storage disabled/unavailable — the toggle still works for this page load.
        }
    });

    // Mobile off-canvas sidebar drawer — the hamburger button toggles it,
    // the backdrop always closes it. State lives as a body attribute (same
    // pattern as the dark-mode toggle's <html data-theme>), CSS does the
    // rest; no persistence needed, it starts closed on every page load.
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-sidebar-toggle]')) {
            var isOpen = document.body.getAttribute('data-sidebar-open') === 'true';
            document.body.setAttribute('data-sidebar-open', isOpen ? 'false' : 'true');
        } else if (event.target.closest('[data-sidebar-close]')) {
            document.body.setAttribute('data-sidebar-open', 'false');
        }
    });

    // Live Server-Side Search (Pjax/Swapping search)
    var searchDebounceTimer;
    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!target.matches('input[name="q"]') && !target.matches('input[name="domain_search"]') && !target.matches('input[name="domain_name"]') && !target.matches('input[data-live-search]')) {
            return;
        }

        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(function () {
            var form = target.closest('form');
            var actionUrl = form ? form.getAttribute('action') || window.location.pathname : window.location.pathname;
            var paramName = target.getAttribute('name') || 'q';
            
            var url = new URL(window.location.origin + actionUrl);
            url.searchParams.set(paramName, target.value);

            var currentParams = new URLSearchParams(window.location.search);
            currentParams.forEach(function (val, key) {
                if (key !== paramName && key !== 'page') {
                    url.searchParams.set(key, val);
                }
            });

            fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                return res.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');

                var targets = [
                    { selector: '.cv-table', replacer: '.cv-table' },
                    { selector: '.cv-datatable__pagination', replacer: '.cv-datatable__pagination' }
                ];

                targets.forEach(function (t) {
                    var oldEl = document.querySelector(t.selector);
                    var newEl = doc.querySelector(t.replacer);
                    if (oldEl && newEl) {
                        oldEl.innerHTML = newEl.innerHTML;
                    }
                });
                
                window.history.replaceState(null, '', url.toString());
            })
            .catch(function (err) {
                console.error('Live search error:', err);
            });
        }, 250);
    });
})();
