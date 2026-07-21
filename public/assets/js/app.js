// Unobtrusive event delegation (blueprint §5 security hardening) — replaces
// inline onclick/onchange/onsubmit attributes so the CSP can drop
// 'unsafe-inline' from script-src without breaking any interaction.
(function () {
    'use strict';

    // Copy one element's value into another on click (e.g. the ticket
    // AI-copilot "Insert into Reply" button copies the suggestion textarea
    // into the reply box). Replaces an inline <script> the CSP blocked.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-copy-value-from]');
        if (!trigger) { return; }
        var source = document.getElementById(trigger.getAttribute('data-copy-value-from'));
        var target = document.getElementById(trigger.getAttribute('data-copy-value-to'));
        if (source && target) {
            target.value = source.value;
            target.focus();
        }
    });

    // Insert a <select>'s chosen value into a target field on change (e.g.
    // the canned-reply picker filling the ticket reply box).
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-insert-value-into]');
        if (!select || !select.value) { return; }
        var target = document.getElementById(select.getAttribute('data-insert-value-into'));
        if (target) {
            target.value = select.value;
        }
    });

    // Onboarding copilot (client dashboard): ask a question, POST it to the
    // AI endpoint, render the answer. Suggestion chips prefill + submit.
    function copilotAsk(widget, question) {
        var answerBox = widget.querySelector('[data-copilot-answer]');
        var submit = widget.querySelector('[data-copilot-submit]');
        var token = widget.getAttribute('data-copilot-token') || '';
        if (!question) { return; }

        if (answerBox) {
            answerBox.style.display = 'block';
            answerBox.textContent = 'Thinking…';
        }
        if (submit) { submit.disabled = true; }

        var body = new FormData();
        body.append('question', question);
        body.append('_token', token);

        fetch('/client/copilot/ask', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (answerBox) { answerBox.textContent = data && data.success ? data.answer : ((data && data.message) || 'Sorry, I could not answer that right now.'); }
        })
        .catch(function () {
            if (answerBox) { answerBox.textContent = 'Sorry, something went wrong. Please try again.'; }
        })
        .then(function () { if (submit) { submit.disabled = false; } });
    }

    document.addEventListener('click', function (event) {
        var chip = event.target.closest('[data-copilot-suggest]');
        if (!chip) { return; }
        var widget = chip.closest('[data-copilot]');
        var input = widget && widget.querySelector('[data-copilot-input]');
        var q = chip.getAttribute('data-copilot-suggest');
        if (input) { input.value = q; }
        copilotAsk(widget, q);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-copilot-form]');
        if (!form) { return; }
        event.preventDefault();
        var widget = form.closest('[data-copilot]');
        var input = widget && widget.querySelector('[data-copilot-input]');
        copilotAsk(widget, input ? input.value.trim() : '');
    });

    // TLD category tabs on the public domain register page: clicking a
    // [data-tld-tab] shows the matching [data-tld-panel] and hides the rest.
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-tld-tab]');
        if (!tab) { return; }
        var name = tab.getAttribute('data-tld-tab');

        var tabs = document.querySelectorAll('[data-tld-tab]');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('is-active', tabs[i] === tab);
        }

        var panels = document.querySelectorAll('[data-tld-panel]');
        for (var j = 0; j < panels.length; j++) {
            panels[j].hidden = panels[j].getAttribute('data-tld-panel') !== name;
        }
    });

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

    // Test Connection (admin/servers list + server-edit page): both views
    // used to carry their own inline onclick + inline <script> for this —
    // silently non-functional under the CSP (see file header), same bug
    // class as everything else in this section.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-test-server]');
        if (!btn) {
            return;
        }

        var id = btn.getAttribute('data-test-server');
        var token = btn.getAttribute('data-token');
        var resultEl = btn.parentElement.querySelector('.server-test-result');
        var originalLabel = btn.innerText;

        btn.disabled = true;
        btn.innerText = 'Testing...';
        if (resultEl) {
            resultEl.textContent = '';
            resultEl.className = 'server-test-result';
        }

        fetch('/admin/servers/' + id + '/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '_token=' + encodeURIComponent(token),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!resultEl) {
                return;
            }
            resultEl.textContent = data.message || (data.success ? 'Connected.' : 'Connection failed.');
            resultEl.className = 'server-test-result cv-badge ' + (data.success ? 'cv-badge--success' : 'cv-badge--danger');
        })
        .catch(function () {
            if (resultEl) {
                resultEl.textContent = 'Request failed.';
                resultEl.className = 'server-test-result cv-badge cv-badge--danger';
            }
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerText = originalLabel;
        });
    });

    // Generic show/hide toggle — a trigger with data-toggle-target (a CSS
    // selector) flips the target's visibility. data-toggle-class switches
    // a class instead of inline display (for elements styled via a CSS
    // class, e.g. ".cv-mobile-nav--open"); otherwise display toggles
    // between 'none' and data-toggle-display (default 'block').
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-toggle-target]');
        if (!trigger) {
            return;
        }

        var target = document.querySelector(trigger.getAttribute('data-toggle-target'));
        if (!target) {
            return;
        }

        var toggleClass = trigger.getAttribute('data-toggle-class');
        if (toggleClass) {
            var isOpen = target.classList.toggle(toggleClass);
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            return;
        }

        var showDisplay = trigger.getAttribute('data-toggle-display') || 'block';
        target.style.display = target.style.display === 'none' || target.style.display === '' ? showDisplay : 'none';
    });

    // Generic inline "Edit" prefill — a trigger with data-edit-form (a CSS
    // selector for the add/edit <form> already on the page) and
    // data-edit-fields (a JSON object of input name => value) copies those
    // values into the form, points it at data-edit-action, relabels its
    // submit button, and reveals its cancel button. Same shape repeats
    // across product groups/server groups/TLD pricing/departments/option
    // groups — one delegated handler instead of five near-duplicate
    // inline-script copies.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-edit-trigger]');
        if (!trigger) {
            return;
        }

        var form = document.querySelector(trigger.getAttribute('data-edit-form'));
        if (!form) {
            return;
        }

        var fields = {};
        try {
            fields = JSON.parse(trigger.getAttribute('data-edit-fields') || '{}');
        } catch (e) {
            fields = {};
        }

        Object.keys(fields).forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) {
                return;
            }
            if (input.type === 'checkbox') {
                input.checked = !!fields[name];
            } else {
                input.value = fields[name];
            }
        });

        form.setAttribute('action', trigger.getAttribute('data-edit-action'));

        var submitBtn = form.querySelector('[data-edit-submit]');
        var submitLabel = trigger.getAttribute('data-edit-submit-label');
        if (submitBtn && submitLabel) {
            submitBtn.innerText = submitLabel;
        }

        var titleEl = document.querySelector(trigger.getAttribute('data-edit-title-target'));
        var titleLabel = trigger.getAttribute('data-edit-title');
        if (titleEl && titleLabel) {
            titleEl.innerText = titleLabel;
        }

        var cancelBtn = form.querySelector('[data-edit-cancel]');
        if (cancelBtn) {
            cancelBtn.style.display = 'inline-block';
        }

        form.scrollIntoView({ behavior: 'smooth' });
    });

    // Companion "Cancel" for the inline-edit pattern above — resets the
    // form back to its original create-mode action/labels.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-edit-cancel]');
        if (!trigger) {
            return;
        }

        var form = trigger.closest('form') || document.querySelector(trigger.getAttribute('data-edit-form') || '');
        if (!form) {
            return;
        }

        form.reset();
        form.setAttribute('action', trigger.getAttribute('data-edit-reset-action'));

        var submitBtn = form.querySelector('[data-edit-submit]');
        var submitLabel = trigger.getAttribute('data-edit-reset-label');
        if (submitBtn && submitLabel) {
            submitBtn.innerText = submitLabel;
        }

        var titleEl = document.querySelector(trigger.getAttribute('data-edit-title-target'));
        var titleLabel = trigger.getAttribute('data-edit-reset-title');
        if (titleEl && titleLabel) {
            titleEl.innerText = titleLabel;
        }

        trigger.style.display = 'none';
    });

    // Row-as-link — a <tr data-row-link="/some/url"> navigates on click,
    // except when the click actually landed on a real link/button/form
    // control inside the row (so "Manage" links, delete forms, etc. inside
    // a clickable row keep working normally instead of double-navigating).
    document.addEventListener('click', function (event) {
        var row = event.target.closest('[data-row-link]');
        if (!row) {
            return;
        }

        if (event.target.closest('a, button, input, select, textarea, form')) {
            return;
        }

        window.location = row.getAttribute('data-row-link');
    });

    // Copy-to-clipboard — a trigger with data-copy-text copies its literal
    // value, or data-copy-target (a CSS selector) copies that element's
    // current value/text. Briefly swaps the trigger's own label to confirm.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-copy-target], [data-copy-text]');
        if (!trigger) {
            return;
        }

        var text = trigger.getAttribute('data-copy-text');
        if (text === null) {
            var source = document.querySelector(trigger.getAttribute('data-copy-target'));
            if (!source) {
                return;
            }
            text = (source.value !== undefined ? source.value : source.textContent) || '';
        }

        var restoreLabel = trigger.innerText;
        var doneLabel = trigger.getAttribute('data-copy-done-label') || 'Copied!';

        var showCopied = function () {
            trigger.innerText = doneLabel;
            setTimeout(function () { trigger.innerText = restoreLabel; }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showCopied, function () {
                fallbackCopy(text);
                showCopied();
            });
        } else {
            fallbackCopy(text);
            showCopied();
        }
    });

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // Nothing more we can do — clipboard access denied.
        }
        document.body.removeChild(textarea);
    }

    // Mobile nav toggle (partials/header.php) — was an inline <script>
    // block in the view itself, same CSP issue as everything else here.
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('[data-mobile-menu-toggle]');
        var nav = document.querySelector('[data-mobile-nav]');
        if (!toggle || !nav) {
            return;
        }
        toggle.addEventListener('click', function () {
            var isOpen = nav.classList.toggle('cv-mobile-nav--open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    // Domain registration page (domains/register.php) — clicking "Register"
    // on a search result reveals the nameserver-choice form pre-filled
    // with that domain; the nameserver-choice radios show/hide the custom
    // nameserver fields.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-domain-register-trigger]');
        if (!trigger) {
            return;
        }

        var domain = trigger.getAttribute('data-domain-register-trigger');
        var wrapper = document.getElementById('register-form-wrapper');
        var domainLabel = document.getElementById('register-form-domain');
        var domainInput = document.getElementById('register-form-domain-input');

        if (domainLabel) {
            domainLabel.innerText = domain;
        }
        if (domainInput) {
            domainInput.value = domain;
        }
        if (wrapper) {
            wrapper.style.display = 'block';
            wrapper.scrollIntoView({ behavior: 'smooth' });
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.name !== 'nameserver_choice') {
            return;
        }

        var customFields = document.getElementById('custom-ns-fields');
        if (customFields) {
            customFields.style.display = target.value === 'custom' ? 'grid' : 'none';
        }
    });

    // require_domain product page (cart/product.php) — the domain_option
    // radios (register/transfer/existing) swap the domain-name input
    // between "name + TLD dropdown" and "full domain" shapes. Rebuilt from
    // scratch each time (matching the original inline version) since the
    // "existing domain" shape drops the TLD dropdown entirely.
    function renderDomainInputWrapper(option) {
        var wrapper = document.getElementById('domain-input-wrapper');
        if (!wrapper) {
            return;
        }

        var tldOptions = wrapper.getAttribute('data-tld-options');
        var tlds = [];
        try {
            tlds = JSON.parse(tldOptions || '[]');
        } catch (e) {
            tlds = [];
        }

        if (option === 'existing') {
            wrapper.innerHTML = '<div style="display:flex; gap:var(--cv-space-2); align-items:center;">'
                + '<span style="font-weight:600; color:var(--cv-text-secondary);">www.</span>'
                + '<input class="cv-input" type="text" name="domain_name" placeholder="example.com" required style="flex:1;">'
                + '</div>';
            return;
        }

        var optionsHtml = tlds.map(function (tld) { return '<option value="' + tld + '">' + tld + '</option>'; }).join('');
        wrapper.innerHTML = '<div style="display:flex; gap:var(--cv-space-2); align-items:center;">'
            + '<span style="font-weight:600; color:var(--cv-text-secondary);">www.</span>'
            + '<input class="cv-input" type="text" name="domain_name" placeholder="example" required style="flex:1;" data-domain-availability-input>'
            + '<select class="cv-select" name="domain_tld" style="width:100px;" data-domain-availability-tld>' + optionsHtml + '</select>'
            + '</div>'
            + '<div data-domain-availability-result style="font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);"></div>';
    }

    document.addEventListener('change', function (event) {
        if (event.target.name === 'domain_option') {
            renderDomainInputWrapper(event.target.value);
        }
    });

    // Live availability check for the require_domain product-page domain
    // field — checks the typed name against whichever TLD is selected,
    // via the same registrar-backed endpoint the standalone domain search
    // page uses, so "is this TLD actually connected to a registrar" is
    // answered the same way in both places.
    var domainAvailabilityTimer;
    function checkProductPageDomainAvailability() {
        var input = document.querySelector('[data-domain-availability-input]');
        var tldSelect = document.querySelector('[data-domain-availability-tld]');
        var resultEl = document.querySelector('[data-domain-availability-result]');
        if (!input || !tldSelect || !resultEl || input.value.trim() === '') {
            return;
        }

        var domain = input.value.trim().toLowerCase() + tldSelect.value;
        resultEl.textContent = 'Checking availability...';
        resultEl.style.color = 'var(--cv-text-secondary)';

        fetch('/domains/availability?domain=' + encodeURIComponent(domain), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.checked) {
                resultEl.textContent = data.message || 'Could not check availability.';
                resultEl.style.color = 'var(--cv-text-secondary)';
            } else if (data.available) {
                resultEl.textContent = domain + ' is available.';
                resultEl.style.color = '#22c55e';
            } else {
                resultEl.textContent = domain + ' is already taken.';
                resultEl.style.color = '#ef4444';
            }
        })
        .catch(function () {
            resultEl.textContent = 'Could not check availability.';
            resultEl.style.color = 'var(--cv-text-secondary)';
        });
    }

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-domain-availability-input]')) {
            return;
        }
        clearTimeout(domainAvailabilityTimer);
        domainAvailabilityTimer = setTimeout(checkProductPageDomainAvailability, 500);
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-domain-availability-tld]')) {
            checkProductPageDomainAvailability();
        }
    });

    // Product group delete flow (catalog/group-migrate-delete.php) — the
    // migrate/delete_all radios show/hide the target-group select and
    // toggle whether it's required.
    document.addEventListener('change', function (event) {
        if (event.target.name !== 'group_action') {
            return;
        }

        var wrapper = document.getElementById('migrate-group-wrapper');
        if (!wrapper) {
            return;
        }

        var select = wrapper.querySelector('select');
        if (event.target.value === 'migrate') {
            wrapper.style.display = 'block';
            if (select) {
                select.setAttribute('required', 'required');
            }
        } else {
            wrapper.style.display = 'none';
            if (select) {
                select.removeAttribute('required');
            }
        }
    });

    // "Select all" checkbox — a trigger with data-select-all (a CSS
    // selector for the checkboxes it should drive) checks/unchecks all of
    // them together (catalog/option-group-show.php bulk-delete column).
    document.addEventListener('click', function (event) {
        var master = event.target.closest('[data-select-all]');
        if (!master) {
            return;
        }

        var checkboxes = document.querySelectorAll(master.getAttribute('data-select-all'));
        checkboxes.forEach(function (cb) { cb.checked = master.checked; });
    });

    // WHMCS migrator live progress (import/whmcs.php) — was an inline
    // onsubmit + inline <script>, same CSP issue as everything else here.
    // The form still had a real method="post" action, so without this JS
    // the migration itself still ran (a plain page reload after
    // completion) — only the live progress bar/counters were silently
    // never shown.
    var whmcsPollInterval = null;
    // Identifies the current import attempt. The server stamps this into
    // the progress file, so the UI can ignore a previous run's leftover
    // result (which persists in the file between attempts) instead of
    // showing its stale error/username/IP as if it were the current run.
    var whmcsCurrentRunId = null;

    function whmcsPollProgress() {
        fetch('/admin/import/whmcs/progress')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || Object.keys(data).length === 0) {
                    return;
                }

                // Ignore a previous attempt's leftover progress. During a
                // live run the server stamps the current run_id within the
                // first update, so this only skips stale pre-run state.
                if (whmcsCurrentRunId && data.run_id && data.run_id !== whmcsCurrentRunId) {
                    return;
                }

                var pct = parseInt(data.percentage || 0, 10);
                var bar = document.getElementById('progress-bar');
                var text = document.getElementById('progress-text');
                var step = document.getElementById('progress-step');
                if (bar) { bar.style.width = pct + '%'; }
                if (text) { text.innerText = pct + '%'; }
                if (step) { step.innerText = data.current_step || 'Processing...'; }

                if (data.imported) {
                    Object.keys(data.imported).forEach(function (key) {
                        var el = document.getElementById('stat-' + key);
                        if (el) {
                            el.innerText = data.imported[key];
                        }
                    });
                }

                if (data.errors && data.errors.length > 0) {
                    var container = document.getElementById('progress-errors-container');
                    var list = document.getElementById('progress-errors');
                    if (container) { container.style.display = 'block'; }
                    if (list) {
                        list.innerHTML = data.errors.map(function (err) {
                            return '<li>' + whmcsEscapeHtml(err.reason) + '</li>';
                        }).join('');
                    }
                }
            })
            .catch(function (err) { console.error('Error polling progress:', err); });
    }

    function whmcsEscapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.id !== 'migration-form') {
            return;
        }

        event.preventDefault();

        var staticError = document.getElementById('static-error');
        var staticSuccess = document.getElementById('static-success');
        if (staticError) { staticError.style.display = 'none'; }
        if (staticSuccess) { staticSuccess.style.display = 'none'; }

        var bar = document.getElementById('progress-bar');
        var text = document.getElementById('progress-text');
        var step = document.getElementById('progress-step');
        var errorsList = document.getElementById('progress-errors');
        var errorsContainer = document.getElementById('progress-errors-container');
        if (bar) { bar.style.width = '0%'; }
        if (text) { text.innerText = '0%'; }
        if (step) { step.innerText = 'Starting connection...'; }
        if (errorsList) { errorsList.innerHTML = ''; }
        if (errorsContainer) { errorsContainer.style.display = 'none'; }

        ['clients', 'servers', 'products', 'services', 'domains', 'invoices', 'transactions', 'currencies', 'tickets', 'promotions'].forEach(function (s) {
            var el = document.getElementById('stat-' + s);
            if (el) { el.innerText = '0'; }
        });

        var progressCard = document.getElementById('migration-progress-card');
        if (progressCard) { progressCard.style.display = 'block'; }

        whmcsCurrentRunId = 'run-' + Date.now() + '-' + Math.floor(Math.random() * 1e9);

        // Build the payload BEFORE disabling the fields. FormData omits
        // disabled controls, so capturing it after the disable loop below
        // would drop every field — including the hidden _token — and the
        // server would reject the request as a missing-CSRF-token 403.
        var formData = new FormData(form);
        formData.append('ajax', '1');
        formData.append('run_id', whmcsCurrentRunId);

        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            elements[i].disabled = true;
        }
        var submitBtn = document.getElementById('submit-btn');
        if (submitBtn) { submitBtn.innerText = 'Migrating...'; }

        whmcsPollInterval = setInterval(whmcsPollProgress, 1000);

        function reEnableForm() {
            for (var j = 0; j < elements.length; j++) {
                elements[j].disabled = false;
            }
            if (submitBtn) { submitBtn.innerText = 'Connect and Migrate'; }
        }

        fetch('/admin/import/whmcs', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            // Follow no redirects silently — a 302 to /login (expired
            // session) should surface as an error the admin can see, not a
            // fetched login page we then try to parse as JSON.
            redirect: 'manual',
        })
        // Read the response as TEXT first, keeping the HTTP status. The old
        // code called r.json() blindly as the first step, so ANY non-JSON
        // response (a 403 CSRF/permission HTML page, a 302 login redirect,
        // a 5xx error page) threw here and fell into .catch() as a generic
        // "lost connection" — hiding the real, usually-trivial cause
        // (expired session / stale CSRF token). Now we inspect status+body
        // and report what actually happened.
        .then(function (r) {
            return r.text().then(function (body) {
                return { status: r.status, type: r.type, body: body };
            });
        })
        .then(function (resp) {
            clearInterval(whmcsPollInterval);
            whmcsPollProgress();
            reEnableForm();

            var data = null;
            try { data = JSON.parse(resp.body); } catch (e) { data = null; }

            if (data) {
                if (data.success) {
                    if (staticSuccess) {
                        staticSuccess.innerText = data.message || 'Migration completed successfully!';
                        staticSuccess.style.display = 'block';
                    }
                    location.reload();
                } else if (staticError) {
                    staticError.innerText = data.message || 'Migration failed.';
                    staticError.style.display = 'block';
                }
                return;
            }

            // Non-JSON response — map the HTTP status to a real cause.
            if (!staticError) { return; }
            if (resp.status === 403) {
                staticError.innerText = 'Security check failed (403) — your session or CSRF token has expired. Reload this page to get a fresh token, then run the migration again. (Nothing was imported.)';
            } else if (resp.status === 401 || resp.status === 419 || resp.type === 'opaqueredirect' || resp.status === 0) {
                staticError.innerText = 'You appear to be logged out. Log back into the admin area, reload this page, then retry the migration. (Nothing was imported.)';
            } else if (resp.status >= 500) {
                staticError.innerText = 'The server hit a fatal error (HTTP ' + resp.status + ') before it could respond. Check storage/migration_error.log on the server for the exact cause.';
            } else {
                // 2xx but unparseable, or some other status — fall back to
                // the progress file, then the generic timeout message.
                checkProgressThenFallback();
                return;
            }
            staticError.style.display = 'block';
        })
        .catch(function () {
            // fetch() itself rejected — a genuine network-level failure
            // (connection dropped, DNS, CORS). The server may still be
            // running the import, so consult the progress file first.
            clearInterval(whmcsPollInterval);
            reEnableForm();
            checkProgressThenFallback();
        });

        function checkProgressThenFallback() {
            // Before blaming a browser/proxy timeout, read the progress
            // file the server writes directly — if the import reached the
            // server and failed for a concrete reason (e.g. a database
            // access-denied error), that reason is far more useful than a
            // generic "lost connection" guess.
            fetch('/admin/import/whmcs/progress')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    // Only trust a recorded failure if it belongs to THIS
                    // attempt — otherwise a new run that died before the
                    // importer wrote anything would surface a previous
                    // run's stale error (old username/host/IP).
                    var isThisRun = data && data.run_id && data.run_id === whmcsCurrentRunId;
                    if (isThisRun && data.status === 'failed' && data.current_step) {
                        if (staticError) {
                            staticError.innerText = data.current_step;
                            staticError.style.display = 'block';
                        }
                    } else {
                        showLostConnectionError();
                    }
                })
                .catch(function () { showLostConnectionError(); });
        }

        function showLostConnectionError() {
            // A non-JSON response with no recorded server-side failure
            // almost always means something between the browser and PHP (a
            // web-server/proxy timeout) cut the connection — not
            // necessarily that the import itself failed. The import runs
            // with ignore_user_abort(true) so it keeps running server-side
            // even if this happens, so it's worth telling the admin to
            // check rather than assuming it's dead.
            if (staticError) {
                staticError.innerText = 'Lost connection to the server before it responded — this often means a timeout between your browser and the server, not that the import failed. Reload this page in a minute and check "Recent WHMCS Imports" below to see if it actually completed.';
                staticError.style.display = 'block';
            }
        }
    });

    // Domain Spinner (domains/register.php) — asks /domains/spin for name
    // variations of whatever's typed in the search box, checked against
    // whichever TLDs an admin enabled for the spinner, and renders the
    // available ones with the same "Register" trigger the main search
    // results use (see the domain-registration-page block above).
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-domain-spin-trigger]');
        if (!trigger) {
            return;
        }

        var input = document.getElementById('domain-search-input');
        var resultsEl = document.getElementById('domain-spin-results');
        if (!input || !resultsEl) {
            return;
        }

        var name = input.value.trim().split('.')[0];
        if (name === '') {
            return;
        }

        var originalLabel = trigger.innerText;
        trigger.disabled = true;
        trigger.innerText = 'Spinning...';
        resultsEl.innerHTML = '';

        fetch('/domains/spin?name=' + encodeURIComponent(name), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var suggestions = data.suggestions || [];

            if (suggestions.length === 0) {
                resultsEl.innerHTML = '<p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">'
                    + (data.message || 'No available suggestions found — try a different name.') + '</p>';
                return;
            }

            resultsEl.innerHTML = suggestions.map(function (s) {
                return '<div class="cv-card" style="margin-bottom:var(--cv-space-3);display:flex;justify-content:space-between;align-items:center;">'
                    + '<div><strong>' + s.domain + '</strong>'
                    + '<div style="font-size:var(--cv-text-xs);color:#22c55e;font-weight:600;">Available &mdash; $' + Number(s.price).toFixed(2) + '/yr</div></div>'
                    + '<button type="button" class="cv-btn" data-domain-register-trigger="' + s.domain + '">Register</button>'
                    + '</div>';
            }).join('');
        })
        .catch(function () {
            resultsEl.innerHTML = '<p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Could not fetch suggestions right now.</p>';
        })
        .finally(function () {
            trigger.disabled = false;
            trigger.innerText = originalLabel;
        });
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
