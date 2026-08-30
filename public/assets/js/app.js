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

    // Open the item when the client clicks anywhere on its row/card
    // (WHMCS-style: service → manage, invoice → view/pay, ticket → open).
    // The element carries data-open-url; nested interactive controls (links,
    // buttons, checkboxes, selects, textareas) are left to handle themselves,
    // so the invoice "Cancel"/checkbox and any inner links keep working.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-open-url]') : null;
        if (!trigger) { return; }
        if (event.target.closest('a, button, input, select, textarea, label')) { return; }
        var url = trigger.getAttribute('data-open-url');
        if (url) { window.location.href = url; }
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

    // Domain registrant contact source (admin domain contact page + client
    // domain detail): choosing a saved contact or the account hides the
    // custom-contact fields; "Custom contact" reveals them.
    function contactSourceToggle(select) {
        var form = select.closest('form');
        var custom = select.closest('[data-contact-custom]') || (form ? form.querySelector('[data-contact-custom]') : null);
        if (!custom) { return; }
        // value '' = custom contact; '-1' = account; 'N' = saved contact.
        custom.style.display = (select.value === '') ? '' : 'none';
    }
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-contact-source]');
        if (select) { contactSourceToggle(select); }
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-contact-source]').forEach(function (select) {
            contactSourceToggle(select);
        });
    });

    // Admin table column filters (partials/table-filter.php + table-filter-row).
    // These are DELEGATED listeners, not inline scripts, so they keep working
    // on pages whose results table is swapped in later via innerHTML (the
    // admin clients live-search does exactly that — inline scripts inside
    // innerHTML never execute).
    //
    // Disabling empty filter inputs before submit keeps the URL to just the
    // active filters; disabled controls aren't serialized.
    function adminFilterApply(input) {
        var row = input.closest ? input.closest('.table-filter-row') : null;
        var form = input.form || (input.getAttribute && document.getElementById(input.getAttribute('form')));
        if (!row || !form) { return; }
        row.querySelectorAll('[data-filter-input]').forEach(function (el) {
            if (el.value === '') { el.disabled = true; }
        });
        form.submit();
    }

    // "⚙ Filters" toggle: reveal/hide the whole filter row. Column headers
    // themselves are sort links (WHMCS-style A-Z / 1-0) — no JS needed for
    // them, the browser follows the anchor with sort=column&dir=asc|desc.
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-filter-toggle]');
        if (!toggle) { return; }
        var row = document.getElementById('filter-row-' + toggle.getAttribute('data-filter-toggle'));
        if (!row) { return; }
        var showingAll = !row.classList.contains('is-expanded');
        row.classList.toggle('is-expanded', showingAll);
        toggle.classList.add('is-active');
        row.querySelectorAll('.table-filter-cell').forEach(function (cell) {
            cell.classList.toggle('is-hidden', !showingAll);
        });
        event.preventDefault();
    });

    // Enter in a text filter input applies the filter.
    document.addEventListener('keydown', function (event) {
        var input = event.target.closest ? event.target.closest('[data-filter-input]') : null;
        if (input && event.key === 'Enter') {
            event.preventDefault();
            adminFilterApply(input);
        }
    });

    // A <select> filter applies immediately when the admin picks an option.
    document.addEventListener('change', function (event) {
        var input = event.target.closest ? event.target.closest('[data-filter-input]') : null;
        if (input && input.tagName === 'SELECT') {
            adminFilterApply(input);
        }
    });

    // Onboarding copilot (client dashboard): ask a question, POST it to the
    // AI endpoint, render the answer as chat bubbles.
    function copilotAsk(widget, question) {
        var chatBox = widget.querySelector('#dbd-chat') || widget.querySelector('[data-copilot-chat]');
        var submitBtn = widget.querySelector('#dbd-ask') || widget.querySelector('[data-copilot-submit]');
        var inputEl = widget.querySelector('#dbd-q') || widget.querySelector('[data-copilot-input]');
        var token = widget.getAttribute('data-token') || widget.getAttribute('data-copilot-token') || '';

        question = (question || '').trim();
        if (!question) { return; }

        function appendBubble(text, type) {
            if (!chatBox) { return null; }
            chatBox.style.display = 'flex';
            chatBox.classList.add('has-messages');
            var el = document.createElement('div');
            el.className = 'dbd-msg dbd-msg--' + type;
            el.textContent = text;
            chatBox.appendChild(el);
            chatBox.scrollTop = chatBox.scrollHeight;
            return el;
        }

        // Show user bubble & thinking bubble
        appendBubble(question, 'user');
        if (inputEl) { inputEl.value = ''; }
        if (submitBtn) { submitBtn.disabled = true; }

        var thinkingEl = appendBubble('Thinking…', 'thinking');

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
            if (thinkingEl && thinkingEl.parentNode) { thinkingEl.parentNode.removeChild(thinkingEl); }
            var answerText = data && data.success && data.answer ? data.answer : ((data && data.message) || 'Sorry, I could not answer that right now.');
            appendBubble(answerText, 'ai');
        })
        .catch(function () {
            if (thinkingEl && thinkingEl.parentNode) { thinkingEl.parentNode.removeChild(thinkingEl); }
            appendBubble('Sorry, something went wrong. Please check your connection and try again.', 'ai');
        })
        .then(function () {
            if (submitBtn) { submitBtn.disabled = false; }
        });
    }

    // Chip button clicks
    document.addEventListener('click', function (event) {
        var chip = event.target.closest('[data-q], [data-copilot-suggest]');
        if (!chip) { return; }
        var widget = chip.closest('#dbd-copilot, [data-copilot]');
        if (!widget) { return; }
        var q = chip.getAttribute('data-q') || chip.getAttribute('data-copilot-suggest') || '';
        copilotAsk(widget, q);
    });

    // Ask button click
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('#dbd-ask, [data-copilot-submit]');
        if (!btn) { return; }
        var widget = btn.closest('#dbd-copilot, [data-copilot]');
        if (!widget) { return; }
        var inputEl = widget.querySelector('#dbd-q, [data-copilot-input]');
        copilotAsk(widget, inputEl ? inputEl.value : '');
    });

    // Enter key press in question input box
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') { return; }
        var inputEl = event.target.closest('#dbd-q, [data-copilot-input]');
        if (!inputEl) { return; }
        event.preventDefault();
        var widget = inputEl.closest('#dbd-copilot, [data-copilot]');
        if (!widget) { return; }
        copilotAsk(widget, inputEl.value);
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

    // PayHub inline checkout — self-contained iframe implementation.
    //
    // WHY WE DO NOT USE inline.js:
    //   PayHub's inline.js line 6-7:
    //     const scriptSource = document.currentScript ? document.currentScript.src : '';
    //     const baseUrl = scriptSource ? scriptSource.substring(...) : '';
    //   When loaded dynamically (document.createElement('script')), document.currentScript
    //   is always null, so baseUrl = '' and the iframe src becomes the RELATIVE path
    //   'checkout.php?...' which the browser resolves against client.philmorehost.com.
    //   WHMP's own 'frame-ancestors: none' CSP then blocks that self-load, showing
    //   "client.philmorehost.com refused to connect" inside the popup.
    //
    // FIX: Build the iframe ourselves with the ABSOLUTE URL we already know:
    //   https://merchant.payhub.com.ng/checkout.php?ref=REF&amount=AMOUNT&email=EMAIL&embed=1&origin=ORIGIN
    // PayHub's checkout.php in embed mode fires postMessage({type:'payhub_success',...})
    // to the parent — we listen for that and trigger server-side verification.
    // No external script loading required at all.

    function openPayhubCheckout(opts) {
        // opts: { ref, amount (major units NGN), email, callbackUrl, onClose }
        var PAYHUB_ORIGIN = 'https://merchant.payhub.com.ng';
        var checkoutUrl = PAYHUB_ORIGIN + '/checkout.php'
            + '?ref='    + encodeURIComponent(opts.ref)
            + '&amount=' + encodeURIComponent(opts.amount)
            + '&email='  + encodeURIComponent(opts.email)
            + '&embed=1'
            + '&origin=' + encodeURIComponent(window.location.origin);

        // --- Build overlay ---
        var overlay = document.createElement('div');
        overlay.id = 'payhub-checkout-overlay';
        Object.assign(overlay.style, {
            position: 'fixed', top: '0', left: '0', width: '100%', height: '100%',
            backgroundColor: 'rgba(0,0,0,0.55)', backdropFilter: 'blur(4px)',
            zIndex: '999999', display: 'flex', alignItems: 'center', justifyContent: 'center'
        });

        var container = document.createElement('div');
        Object.assign(container.style, {
            width: '100%', maxWidth: '450px', height: '600px',
            backgroundColor: '#fff', borderRadius: '24px', overflow: 'hidden',
            boxShadow: '0 25px 50px -12px rgba(0,0,0,0.25)', position: 'relative'
        });

        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '\u00d7';
        Object.assign(closeBtn.style, {
            position: 'absolute', top: '12px', right: '14px',
            width: '32px', height: '32px', borderRadius: '50%', border: 'none',
            backgroundColor: '#f1f5f9', color: '#64748b', fontSize: '22px',
            cursor: 'pointer', zIndex: '10', lineHeight: '1'
        });

        var iframe = document.createElement('iframe');
        iframe.src = checkoutUrl;
        Object.assign(iframe.style, { width: '100%', height: '100%', border: 'none' });
        iframe.setAttribute('allow', 'clipboard-read; clipboard-write');

        function destroy() {
            window.removeEventListener('message', onMessage, false);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }

        closeBtn.addEventListener('click', function () {
            destroy();
            if (opts.onClose) { opts.onClose(); }
        });

        // Listen for payment success postMessage from PayHub's checkout.php.
        // checkout.php (embed mode, line 164-167) fires:
        //   window.parent.postMessage({ type: 'payhub_success', data: { reference, status } }, origin)
        function onMessage(event) {
            if (event.origin !== PAYHUB_ORIGIN) { return; }
            if (!event.data || event.data.type !== 'payhub_success') { return; }
            destroy();
            if (opts.callbackUrl) { window.location.href = opts.callbackUrl; }
        }
        window.addEventListener('message', onMessage, false);

        container.appendChild(closeBtn);
        container.appendChild(iframe);
        overlay.appendChild(container);
        document.body.appendChild(overlay);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-payhub-pay]');
        if (!button || button.disabled) { return; }
        event.preventDefault();

        var invoiceId = button.getAttribute('data-invoice-id');
        var slug      = button.getAttribute('data-gateway-slug');
        var label     = 'Pay with ' + (button.getAttribute('data-gateway-name') || 'PayHub');
        var idle      = function (text) { button.disabled = false; button.textContent = text || label; };

        button.disabled = true;
        button.textContent = 'Starting\u2026';

        var body = new FormData();
        body.append('_token', button.getAttribute('data-token') || '');

        fetch('/client/invoices/' + encodeURIComponent(invoiceId) + '/pay/' + encodeURIComponent(slug) + '/init', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                if (data && data.redirectUrl) { window.location.href = data.redirectUrl; return; }
                idle();
                window.alert((data && data.message) || 'Could not start the payment. Please try again.');
                return;
            }

            openPayhubCheckout({
                ref:         data.reference,
                // checkout.php expects the amount in MAJOR units (NGN), same as
                // /api/transaction/initialize — NOT multiplied by 100.
                amount:      data.amount,
                email:       data.email,
                callbackUrl: data.callbackUrl,
                onClose:     function () { idle(); },
            });
        })
        .catch(function (err) {
            idle();
            window.alert((err && err.message) || 'Could not start the payment. Please try again.');
        });
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
        var usernameLabel = form && form.querySelector('[data-server-username-label]');
        var usernameInput = form && form.querySelector('[data-server-username-input]');
        var tokenLabel = form && form.querySelector('[data-server-token-label]');
        var tokenInput = form && form.querySelector('[data-server-token-input]');
        var portField = form && form.querySelector('[data-server-port-field]');

        if (!usernameField || !tokenLabel) {
            return;
        }

        var val = select.value;

        if (val === 'interserver-vps' || val === 'interserver_vps') {
            usernameField.style.display = 'none';
            tokenLabel.textContent = 'API Key';
            if (portField) portField.style.display = 'block';
        } else if (val === 'interserver-dedicated' || val === 'interserver_dedicated') {
            usernameField.style.display = 'block';
            if (usernameLabel) usernameLabel.textContent = 'API Username';
            if (usernameInput) usernameInput.placeholder = 'e.g., api_username';
            tokenLabel.textContent = 'API Token / Password';
            if (tokenInput) tokenInput.placeholder = '';
            if (portField) portField.style.display = 'block';
        } else if (val === 'resellerclub-email' || val === 'resellerclub_email') {
            usernameField.style.display = 'block';
            if (usernameLabel) usernameLabel.textContent = 'Reseller ID';
            if (usernameInput) usernameInput.placeholder = 'e.g., 123456';
            tokenLabel.textContent = 'API Token';
            if (tokenInput) tokenInput.placeholder = '';
            if (portField) portField.style.display = 'block';
        } else if (val === 'local') {
            usernameField.style.display = 'none';
            tokenLabel.textContent = 'API Token / Password';
            if (portField) portField.style.display = 'block';
        } else if (val === 'nocix-dedicated' || val === 'nocix_dedicated' || val === 'nocix') {
            usernameField.style.display = 'block';
            if (usernameLabel) usernameLabel.textContent = 'API Username';
            if (usernameInput) usernameInput.placeholder = 'e.g., api_user';
            tokenLabel.textContent = 'API Token';
            if (tokenInput) tokenInput.placeholder = '';
            if (portField) portField.style.display = 'block';
        } else {
            usernameField.style.display = 'block';
            if (usernameLabel) usernameLabel.textContent = 'API Username';
            if (usernameInput) usernameInput.placeholder = '';
            tokenLabel.textContent = 'API Token / Password';
            if (tokenInput) tokenInput.placeholder = '';
            if (portField) portField.style.display = 'block';
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
            // An array value means a checkbox group (name="foo[]") rather
            // than a single input — check the ones whose value is listed,
            // uncheck the rest. Added for promo banners' target-pages picker;
            // existing single-value callers are unaffected since none of
            // them pass an array.
            if (Array.isArray(fields[name])) {
                var group = form.querySelectorAll('[name="' + name + '[]"]');
                group.forEach(function (cb) {
                    cb.checked = fields[name].indexOf(cb.value) !== -1;
                });
                return;
            }

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

    // Domain search results (domains/register.php) — the page renders the
    // TLD/price list immediately with no live registrar call in the request
    // path; each row's real availability is then fetched here, one request
    // per domain, all in parallel (the browser runs several at once rather
    // than the server checking them one after another), so results fill in
    // as each check resolves instead of the whole page waiting on all of
    // them.
    document.querySelectorAll('[data-domain-check]').forEach(function (row) {
        var domain = row.getAttribute('data-domain-check');
        var statusEl = row.querySelector('.dr-result-status');
        var actionEl = row.querySelector('.dr-result-action');

        fetch('/domains/availability?domain=' + encodeURIComponent(domain), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!statusEl) {
                return;
            }

            if (!data.checked) {
                statusEl.innerText = data.message || 'Could not check availability.';
                return;
            }

            if (data.available) {
                statusEl.style.cssText = 'font-size:var(--cv-text-sm);color:#10b981;font-weight:700;margin-top:0.3rem;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;';
                statusEl.innerHTML = '<span style="font-weight:800;font-size:0.85rem;padding:0.35rem 0.75rem;text-transform:uppercase;letter-spacing:0.05em;background:#10b981;color:#ffffff;border-radius:6px;box-shadow:0 2px 4px rgba(16,185,129,0.3);">&#10004; AVAILABLE</span> <span style="font-size:1rem;color:var(--cv-text-primary);">'
                    + (data.formatted_price || ('$' + Number(data.price).toFixed(2))) + '/yr</span>';

                if (actionEl) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cv-btn';
                    btn.setAttribute('data-domain-register-trigger', domain);
                    btn.innerText = 'Add to Cart';
                    actionEl.innerHTML = '';
                    actionEl.appendChild(btn);
                }
            } else {
                statusEl.style.cssText = 'font-size:var(--cv-text-sm);color:#ef4444;font-weight:700;margin-top:0.3rem;';
                statusEl.innerHTML = '<span style="font-weight:800;font-size:0.85rem;padding:0.35rem 0.75rem;text-transform:uppercase;letter-spacing:0.05em;background:#ef4444;color:#ffffff;border-radius:6px;box-shadow:0 2px 4px rgba(239,68,68,0.3);display:inline-block;">&#10008; ALREADY TAKEN</span>';
            }
        })
        .catch(function () {
            if (statusEl) {
                statusEl.innerText = 'Could not check availability right now.';
            }
        });
    });

    // Marketing campaign AI copilot: "Help me write" drafts from a brief,
    // "Refine" rewrites whatever is already in the form. Both only fill the
    // subject/body inputs — nothing is saved or sent, so the admin always gets
    // the last word on what goes out.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-copilot-action]') : null;
        if (!btn) {
            return;
        }

        var panel = btn.closest('[data-campaign-copilot]');
        var form = btn.closest('form');
        if (!panel || !form) {
            return;
        }

        var mode = btn.getAttribute('data-copilot-action');
        var status = panel.querySelector('[data-copilot-status]');
        var briefEl = panel.querySelector('[data-copilot-brief]');
        var subjectEl = form.querySelector('[data-campaign-subject]');
        var bodyEl = form.querySelector('[data-campaign-body]');
        var buttons = panel.querySelectorAll('[data-copilot-action]');

        var setBusy = function (busy) {
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].disabled = busy;
            }
        };

        var say = function (msg, isError) {
            if (status) {
                status.textContent = msg;
                status.style.color = isError ? 'var(--cv-color-danger-600, #b42318)' : 'var(--cv-text-secondary)';
            }
        };

        var payload = new FormData();
        payload.set('mode', mode);
        payload.set('brief', briefEl ? briefEl.value : '');
        payload.set('subject', subjectEl ? subjectEl.value : '');
        payload.set('body', bodyEl ? bodyEl.value : '');

        // The endpoint is CSRF-checked like every other POST, so send the
        // token the form already carries.
        var token = form.querySelector('input[name="_token"]');
        if (token) {
            payload.set('_token', token.value);
        }

        setBusy(true);
        say(mode === 'refine' ? 'Refining…' : 'Writing…', false);

        fetch('/admin/campaigns/copilot', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                say((data && data.message) || 'The copilot could not produce a draft.', true);
                return;
            }

            if (subjectEl && data.subject) {
                subjectEl.value = data.subject;
            }
            if (bodyEl && data.body) {
                bodyEl.value = data.body;
            }

            say('Draft ready — review and edit before saving.', false);
        })
        .catch(function () {
            say('Network error contacting the copilot.', true);
        })
        .then(function () {
            setBusy(false);
        });
    });

    // Promo banner AI copilot: drafts eyebrow/headline/subtext/CTA copy from
    // the coupon code + discount already typed into the form. Same
    // fetch-and-fill shape as the campaign copilot above — text only, the
    // admin still picks the visual template separately.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-promo-copilot-action]') : null;
        if (!btn) {
            return;
        }

        var panel = btn.closest('[data-promo-copilot]');
        var form = btn.closest('form');
        if (!panel || !form) {
            return;
        }

        var status = panel.querySelector('[data-promo-copilot-status]');
        var couponEl = form.querySelector('[data-promo-copilot-coupon]');
        var discountEl = panel.querySelector('[data-promo-copilot-discount]');
        var briefEl = panel.querySelector('[data-promo-copilot-brief]');
        var eyebrowEl = form.querySelector('[data-promo-eyebrow]');
        var headlineEl = form.querySelector('[data-promo-headline]');
        var subtextEl = form.querySelector('[data-promo-subtext]');
        var ctaEl = form.querySelector('[data-promo-cta]');

        var say = function (msg, isError) {
            if (status) {
                status.textContent = msg;
                status.style.color = isError ? 'var(--cv-color-danger-600, #b42318)' : 'var(--cv-text-secondary)';
            }
        };

        var payload = new FormData();
        payload.set('coupon_code', couponEl ? couponEl.value : '');
        payload.set('discount_description', discountEl ? discountEl.value : '');
        payload.set('brief', briefEl ? briefEl.value : '');

        var token = form.querySelector('input[name="_token"]');
        if (token) {
            payload.set('_token', token.value);
        }

        btn.disabled = true;
        say('Writing…', false);

        fetch('/admin/promo-banners/copilot', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                say((data && data.message) || 'The copilot could not produce a draft.', true);
                return;
            }

            if (eyebrowEl && data.eyebrow_text) { eyebrowEl.value = data.eyebrow_text; }
            if (headlineEl && data.headline) { headlineEl.value = data.headline; }
            if (subtextEl && data.subtext) { subtextEl.value = data.subtext; }
            if (ctaEl && data.cta_text) { ctaEl.value = data.cta_text; }

            say('Draft ready — review and edit before saving.', false);
        })
        .catch(function () {
            say('Network error contacting the copilot.', true);
        })
        .then(function () {
            btn.disabled = false;
        });
    });

    // Knowledgebase article AI copilot: "Write Draft" / "Refine Current Text"
    // fill the title/body fields — nothing is saved until the real form is
    // submitted, same as the campaign and promo-banner copilots above.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-kb-copilot-action]') : null;
        if (!btn) {
            return;
        }

        var panel = btn.closest('[data-kb-copilot]');
        var form = document.getElementById('kb-article-form');
        if (!panel || !form) {
            return;
        }

        var mode = btn.getAttribute('data-kb-copilot-action');
        var status = panel.querySelector('[data-kb-copilot-status]');
        var briefEl = panel.querySelector('[data-kb-copilot-brief]');
        var referenceEl = panel.querySelector('[data-kb-copilot-reference]');
        var titleEl = form.querySelector('[data-kb-title]');
        var bodyEl = form.querySelector('[data-kb-body]');
        var buttons = panel.querySelectorAll('[data-kb-copilot-action]');

        var setBusy = function (busy) {
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].disabled = busy;
            }
        };

        var say = function (msg, isError) {
            if (status) {
                status.textContent = msg;
                status.style.color = isError ? 'var(--cv-color-danger-600, #b42318)' : 'var(--cv-text-secondary)';
            }
        };

        var payload = new FormData();
        payload.set('mode', mode);
        payload.set('brief', briefEl ? briefEl.value : '');
        payload.set('reference_notes', referenceEl ? referenceEl.value : '');
        payload.set('title', titleEl ? titleEl.value : '');
        payload.set('body', bodyEl ? bodyEl.value : '');

        var token = form.querySelector('input[name="_token"]');
        if (token) {
            payload.set('_token', token.value);
        }

        setBusy(true);
        say(mode === 'refine' ? 'Refining…' : 'Writing…', false);

        fetch('/admin/kb/articles/copilot', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                say((data && data.message) || 'The copilot could not produce a draft.', true);
                return;
            }

            if (titleEl && data.title) { titleEl.value = data.title; }
            if (bodyEl && data.body) { bodyEl.value = data.body; }

            say('Draft ready — review and edit before saving.', false);
        })
        .catch(function () {
            say('Network error contacting the copilot.', true);
        })
        .then(function () {
            setBusy(false);
        });
    });

    // Knowledgebase category AI copilot: fills the name/description fields
    // on the add-category form (or the inline edit form once data-edit-trigger
    // has swapped it into edit mode) from a short brief.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-kb-category-copilot-action]') : null;
        if (!btn) {
            return;
        }

        var panel = btn.closest('[data-kb-category-copilot]');
        var form = document.getElementById('kb-category-form');
        if (!panel || !form) {
            return;
        }

        var status = panel.querySelector('[data-kb-category-copilot-status]');
        var briefEl = panel.querySelector('[data-kb-category-copilot-brief]');
        var nameEl = form.querySelector('[data-kb-category-name]');
        var descEl = form.querySelector('[data-kb-category-description]');

        var say = function (msg, isError) {
            if (status) {
                status.textContent = msg;
                status.style.color = isError ? 'var(--cv-color-danger-600, #b42318)' : 'var(--cv-text-secondary)';
            }
        };

        var payload = new FormData();
        payload.set('brief', briefEl ? briefEl.value : '');

        var token = form.querySelector('input[name="_token"]');
        if (token) {
            payload.set('_token', token.value);
        }

        btn.disabled = true;
        say('Writing…', false);

        fetch('/admin/kb/categories/copilot', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                say((data && data.message) || 'The copilot could not produce a draft.', true);
                return;
            }

            if (nameEl && data.name) { nameEl.value = data.name; }
            if (descEl && data.description) { descEl.value = data.description; }

            say('Draft ready — review and edit before saving.', false);
        })
        .catch(function () {
            say('Network error contacting the copilot.', true);
        })
        .then(function () {
            btn.disabled = false;
        });
    });

    // Manual invoice builder (billing/invoice-create.php): add/remove line
    // items and keep a running total. Delegated because rows are added after
    // load, and in this file rather than inline because the CSP blocks
    // inline <script>.
    function recalcInvoiceTotal() {
        var amounts = document.querySelectorAll('[data-invoice-items] input[name="item_amount[]"]');
        var total = 0;

        for (var i = 0; i < amounts.length; i++) {
            var value = parseFloat(amounts[i].value);
            if (!isNaN(value)) {
                total += value;
            }
        }

        var out = document.querySelector('[data-invoice-total]');
        if (out) {
            out.textContent = total.toFixed(2);
        }
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest) {
            return;
        }

        if (event.target.closest('[data-add-invoice-item]')) {
            var list = document.querySelector('[data-invoice-items]');
            var template = list ? list.querySelector('[data-invoice-item-row]') : null;
            if (!list || !template) {
                return;
            }

            var row = template.cloneNode(true);
            var inputs = row.querySelectorAll('input');
            for (var i = 0; i < inputs.length; i++) {
                inputs[i].value = '';
            }
            list.appendChild(row);
            return;
        }

        var remove = event.target.closest('[data-remove-invoice-item]');
        if (remove) {
            var rows = document.querySelectorAll('[data-invoice-item-row]');
            // Keep one row so the form can never end up with nothing to type in.
            if (rows.length > 1) {
                remove.closest('[data-invoice-item-row]').remove();
            } else {
                var lastInputs = rows[0].querySelectorAll('input');
                for (var j = 0; j < lastInputs.length; j++) {
                    lastInputs[j].value = '';
                }
            }
            recalcInvoiceTotal();
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-invoice-items] input[name="item_amount[]"]')) {
            recalcInvoiceTotal();
        }
    });

    // Admin order builder (billing/order-create.php): same clone-template
    // add/remove pattern as the invoice line items above, but for
    // product_id[]/billing_cycle[]/quantity[] rows instead — kept separate
    // since the field names and row shape differ.
    document.addEventListener('click', function (event) {
        if (!event.target.closest) {
            return;
        }

        if (event.target.closest('[data-add-order-item]')) {
            var list = document.querySelector('[data-order-items]');
            var template = list ? list.querySelector('[data-order-item-row]') : null;
            if (!list || !template) {
                return;
            }

            var row = template.cloneNode(true);
            var selects = row.querySelectorAll('select');
            for (var i = 0; i < selects.length; i++) {
                selects[i].selectedIndex = 0;
            }
            var inputs = row.querySelectorAll('input');
            for (var j = 0; j < inputs.length; j++) {
                inputs[j].value = inputs[j].type === 'number' ? '1' : '';
            }
            list.appendChild(row);
            return;
        }

        var removeOrderItem = event.target.closest('[data-remove-order-item]');
        if (removeOrderItem) {
            var orderRows = document.querySelectorAll('[data-order-item-row]');
            if (orderRows.length > 1) {
                removeOrderItem.closest('[data-order-item-row]').remove();
            } else {
                var lastSelects = orderRows[0].querySelectorAll('select');
                for (var k = 0; k < lastSelects.length; k++) {
                    lastSelects[k].selectedIndex = 0;
                }
                var lastInputs = orderRows[0].querySelectorAll('input');
                for (var m = 0; m < lastInputs.length; m++) {
                    lastInputs[m].value = lastInputs[m].type === 'number' ? '1' : '';
                }
            }
        }
    });

    // Copy-to-clipboard for any [data-copy-target="#selector"] button — used by
    // the Ask AI answer panel, where the whole point is pasting the text into a
    // client email.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-copy-target]') : null;
        if (!btn) {
            return;
        }

        var source = document.querySelector(btn.getAttribute('data-copy-target'));
        if (!source) {
            return;
        }

        var text = source.innerText;
        var original = btn.textContent;
        var done = function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = original; }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
        } else {
            fallbackCopy(text, done);
        }
    });

    // navigator.clipboard needs a secure context; plain-HTTP installs fall back.
    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            /* clipboard unavailable — leave the button label alone */
        }
        document.body.removeChild(ta);
    }

    // ---- Domain pricing admin page (domains/pricing-index.php) -------------
    // All of this previously lived in an inline <script> on that page, which
    // the CSP (script-src 'self', no 'unsafe-inline') blocked outright — the
    // "Bulk Add Multiple TLDs" and "Import from WHMCS" buttons did nothing at
    // all as a result. Behaviour has to live in this file to run.
    function toggleSection(selector) {
        var section = document.querySelector(selector);
        if (section) {
            section.style.display = section.style.display === 'none' || section.style.display === '' ? 'block' : 'none';
        }
    }

    document.addEventListener('click', function (event) {
        var el = event.target.closest ? event.target : null;
        if (!el) {
            return;
        }

        if (el.closest('#toggle-bulk-form')) {
            toggleSection('#bulk-form-section');
            return;
        }

        if (el.closest('#toggle-whmcs-form')) {
            toggleSection('#whmcs-form-section');
            return;
        }

        var hider = el.closest('[data-toggle-hide]');
        if (hider) {
            var target = document.querySelector(hider.getAttribute('data-toggle-hide'));
            if (target) {
                target.style.display = 'none';
            }
        }
    });

    // Bulk TLD editor: the checkboxes live inside the table but submit with the
    // bulk form via form="tld-bulk-update-form", so this only has to manage
    // visibility, the running count and select-all.
    function syncTldBulkForm() {
        var form = document.querySelector('[data-tld-bulk-form]');
        if (!form) {
            return;
        }

        var checked = document.querySelectorAll('[data-tld-checkbox]:checked');
        var counter = form.querySelector('[data-tld-selected-count]');

        form.hidden = checked.length === 0;

        if (counter) {
            counter.textContent = String(checked.length);
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-tld-select-all]')) {
            var boxes = document.querySelectorAll('[data-tld-checkbox]');
            for (var i = 0; i < boxes.length; i++) {
                // Skip rows the table search has hidden, so "select all" means
                // "all the rows I can actually see".
                var row = boxes[i].closest('tr');
                if (row && row.hidden) {
                    continue;
                }
                boxes[i].checked = event.target.checked;
            }
            syncTldBulkForm();
            return;
        }

        if (event.target.matches('[data-tld-checkbox]')) {
            syncTldBulkForm();
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-tld-bulk-clear]')) {
            return;
        }

        var boxes = document.querySelectorAll('[data-tld-checkbox], [data-tld-select-all]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = false;
        }
        syncTldBulkForm();
    });

    // WHMCS extension import: fetch the TLD list from the configured WHMCS
    // database, let the admin tick the ones they want, then push them through
    // the same bulk-add endpoint the manual form uses.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('#fetch-whmcs-extensions') : null;
        if (!btn) {
            return;
        }

        var loading = document.getElementById('whmcs-extensions-loading');
        var list = document.getElementById('whmcs-extensions-list');
        var error = document.getElementById('whmcs-error');
        var importBtn = document.getElementById('import-whmcs-btn');

        btn.disabled = true;
        if (loading) { loading.style.display = 'block'; }
        if (list) { list.style.display = 'none'; }
        if (error) { error.style.display = 'none'; }

        fetch('/admin/domain-pricing/fetch-whmcs', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                if (error) {
                    error.textContent = data.message || 'Failed to fetch extensions';
                    error.style.display = 'block';
                }
                return;
            }

            if (!data.extensions || data.extensions.length === 0) {
                if (error) {
                    error.textContent = 'No domain extensions found in WHMCS database';
                    error.style.display = 'block';
                }
                return;
            }

            if (!list) {
                return;
            }

            list.innerHTML = '';

            // Built with DOM APIs rather than innerHTML string concatenation:
            // the extension names come from an external database, so this
            // avoids handing them to the HTML parser.
            data.extensions.forEach(function (ext) {
                var label = document.createElement('label');
                label.style.cssText = 'display:flex;align-items:center;gap:var(--cv-space-1);padding:var(--cv-space-1);cursor:pointer;border-bottom:1px solid var(--cv-border-default);';

                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'whmcs-extension-check';
                cb.value = ext;

                var span = document.createElement('span');
                span.textContent = ext;

                label.appendChild(cb);
                label.appendChild(span);
                list.appendChild(label);
            });

            list.style.display = 'block';
            if (importBtn) { importBtn.disabled = false; }
        })
        .catch(function (err) {
            if (error) {
                error.textContent = 'Network error: ' + err.message;
                error.style.display = 'block';
            }
        })
        .then(function () {
            btn.disabled = false;
            if (loading) { loading.style.display = 'none'; }
        });
    });

    // Enable the import button only once something is ticked.
    document.addEventListener('change', function (event) {
        if (!event.target.matches('.whmcs-extension-check')) {
            return;
        }
        var importBtn = document.getElementById('import-whmcs-btn');
        if (importBtn) {
            importBtn.disabled = !document.querySelector('.whmcs-extension-check:checked');
        }
    });

    document.addEventListener('submit', function (event) {
        if (!event.target.matches('#whmcs-import-form')) {
            return;
        }

        event.preventDefault();

        var selected = Array.prototype.slice
            .call(document.querySelectorAll('.whmcs-extension-check:checked'))
            .map(function (cb) { return cb.value; });

        if (selected.length === 0) {
            window.alert('Please select at least one extension');
            return;
        }

        var form = new FormData(event.target);
        form.set('tld_list', selected.join('\n'));

        var importBtn = document.getElementById('import-whmcs-btn');
        if (importBtn) {
            importBtn.disabled = true;
            importBtn.textContent = '⏳ Importing...';
        }

        fetch('/admin/domain-pricing/bulk', { method: 'POST', body: form })
            .then(function () { window.location.href = '/admin/domain-pricing'; })
            .catch(function (err) {
                window.alert('Error: ' + err.message);
                if (importBtn) {
                    importBtn.disabled = false;
                    importBtn.textContent = '✓ Import Selected Extensions';
                }
            });
    });

    // "View Product Details" accordion on the product page. Delegated rather
    // than bound on DOMContentLoaded because the page's CSP (script-src 'self',
    // no 'unsafe-inline') blocks inline <script>, so this behaviour has to live
    // in this file to run at all.
    //
    // maxHeight is animated from an explicit pixel value, so it's measured at
    // open time — the description can be any length, and measuring on load
    // would read 0 for content that is still hidden.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-accordion-trigger]') : null;
        if (!trigger) {
            return;
        }

        event.preventDefault();

        var root = trigger.closest('[data-details-accordion]') || document;
        var content = root.querySelector('[data-accordion-content]');
        var icon = trigger.querySelector('[data-accordion-icon]');

        if (!content) {
            return;
        }

        var isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';

        content.style.maxHeight = isOpen ? '0px' : content.scrollHeight + 'px';
        trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

        if (icon) {
            icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
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

        // billing/order-create.php adds a 4th "No domain" choice (value "")
        // ahead of register/transfer/existing, for the (optional-here) case
        // where the admin's order has no domain at all. product.php's
        // require_domain page never has an empty domain_option, so this
        // branch is dead there and safe to add.
        if (option === '') {
            wrapper.style.display = 'none';
            wrapper.innerHTML = '';
            return;
        }
        wrapper.style.display = 'block';

        var tldOptions = wrapper.getAttribute('data-tld-options');
        var tlds = [];
        try {
            tlds = JSON.parse(tldOptions || '[]');
        } catch (e) {
            tlds = [];
        }

        // This replaces the wrapper's DOM wholesale, so the markup below must
        // mirror resources/views/cart/product.php exactly — anything styled
        // only server-side would disappear the first time an option changes.
        if (option === 'existing') {
            wrapper.innerHTML = '<div class="domain-field">'
                + '<span class="domain-field__prefix">www.</span>'
                + '<input class="domain-field__input" type="text" name="domain_name" placeholder="yourbusiness.com" required>'
                + '</div>';
            return;
        }

        var optionsHtml = tlds.map(function (tld) { return '<option value="' + tld + '">' + tld + '</option>'; }).join('');
        wrapper.innerHTML = '<div class="domain-field">'
            + '<span class="domain-field__prefix">www.</span>'
            + '<input class="domain-field__input" type="text" name="domain_name" placeholder="yourbusiness" required data-domain-availability-input>'
            + '<span class="domain-field__divider" aria-hidden="true"></span>'
            + '<select class="domain-field__tld" name="domain_tld" aria-label="Domain extension" data-domain-availability-tld>' + optionsHtml + '</select>'
            + '</div>'
            + '<div class="domain-result" data-domain-availability-result></div>';
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
        // Existing service/domain orders record a domain the client already
        // holds — there is no availability to check, and the result box would
        // only confuse ("already taken" for something we're intentionally
        // adding). Skip the call entirely while that mode is enabled.
        var existingMode = document.getElementById('order-is-existing');
        if (existingMode && existingMode.checked) {
            return;
        }

        var input = document.querySelector('[data-domain-availability-input]');
        var tldSelect = document.querySelector('[data-domain-availability-tld]');
        var resultEl = document.querySelector('[data-domain-availability-result]');
        if (!input || !tldSelect || !resultEl || input.value.trim() === '') {
            return;
        }

        // Strip anything from the first dot onward before appending the
        // selected TLD — mirrors CheckoutController::addToCart()'s
        // server-side rule exactly, so a client who types the full domain
        // ("example.com") here sees availability checked for the same
        // domain that actually gets added to the cart, not "example.com.com".
        var rawName = input.value.trim().toLowerCase();
        var firstDot = rawName.indexOf('.');
        var nameOnly = firstDot !== -1 ? rawName.substring(0, firstDot) : rawName;
        var domain = nameOnly + tldSelect.value;
        resultEl.textContent = 'Checking availability...';
        resultEl.style.color = 'var(--cv-text-secondary)';

        var optionEl = document.querySelector('[data-domain-option-toggle]:checked');
        var option = optionEl ? optionEl.value : 'register';

        fetch('/domains/availability?domain=' + encodeURIComponent(domain), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.checked) {
                resultEl.textContent = data.message || 'Could not check availability.';
                resultEl.style.color = 'var(--cv-text-secondary)';
            } else if (data.available) {
                var priceStr = option === 'transfer' ? data.formatted_transfer_price : data.formatted_price;
                resultEl.textContent = domain + ' is available! Price: ' + priceStr + ' / Year';
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

    // Client dashboard domain register/transfer widget (#dbd-domain-widget).
    // Register mode is "name + TLD dropdown"; transfer mode is a full domain
    // (no TLD select). Both get a debounced live availability/price check
    // against the same registrar-backed endpoint the standalone register page
    // uses (/domains/availability), and submit navigates to the full
    // register or transfer page with the domain prefilled.
    (function () {
        var widget = document.getElementById('dbd-domain-widget');
        if (!widget) { return; }

        var modeEls = widget.querySelectorAll('[data-dbd-domain-mode]');
        var form = widget.querySelector('[data-dbd-domain-form]');
        var nameInput = widget.querySelector('[data-dbd-domain-name]');
        var tldSelect = widget.querySelector('[data-dbd-domain-tld]');
        var tldDivider = widget.querySelector('[data-dbd-tld-divider]');
        var submitBtn = widget.querySelector('[data-dbd-domain-submit]');
        var resultEl = widget.querySelector('[data-dbd-domain-result]');
        if (!form || !nameInput || !tldSelect || !submitBtn || !resultEl) { return; }

        var currentMode = 'register';
        var timer = null;

        function domainFromInput() {
            var raw = nameInput.value.trim().toLowerCase();
            if (raw === '') { return ''; }
            if (currentMode === 'transfer') { return raw; }
            // Register: strip anything from the first dot (so typing the full
            // domain doesn't append the TLD twice), then add the TLD.
            var dot = raw.indexOf('.');
            var nameOnly = dot !== -1 ? raw.substring(0, dot) : raw;
            return nameOnly + tldSelect.value;
        }

        function renderMode(mode) {
            currentMode = mode;
            modeEls.forEach(function (el) {
                var active = el.getAttribute('data-dbd-domain-mode') === mode;
                el.classList.toggle('is-active', active);
                el.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            var isRegister = mode === 'register';
            tldSelect.style.display = isRegister ? '' : 'none';
            if (tldDivider) { tldDivider.style.display = isRegister ? '' : 'none'; }
            nameInput.placeholder = isRegister ? 'yourbusiness' : 'yourbusiness.com';
            submitBtn.textContent = isRegister ? 'Search' : 'Transfer';
            check();
        }

        function check() {
            var domain = domainFromInput();
            if (domain === '' || domain.indexOf('.') === -1) {
                resultEl.textContent = '';
                resultEl.className = 'dbd-domain__result';
                return;
            }
            resultEl.textContent = 'Checking availability…';
            resultEl.className = 'dbd-domain__result is-checking';
            fetch('/domains/availability?domain=' + encodeURIComponent(domain), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.checked) {
                    resultEl.textContent = data.message || 'Could not check availability.';
                    resultEl.className = 'dbd-domain__result is-error';
                } else if (data.available) {
                    var price = currentMode === 'transfer' ? data.formatted_transfer_price : data.formatted_price;
                    resultEl.textContent = domain + ' is available · ' + price + '/yr';
                    resultEl.className = 'dbd-domain__result is-ok';
                } else {
                    resultEl.textContent = domain + ' is already taken';
                    resultEl.className = 'dbd-domain__result is-error';
                }
            })
            .catch(function () {
                resultEl.textContent = 'Could not check availability.';
                resultEl.className = 'dbd-domain__result is-error';
            });
        }

        modeEls.forEach(function (el) {
            el.addEventListener('click', function () {
                renderMode(el.getAttribute('data-dbd-domain-mode'));
            });
        });

        nameInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(check, 450);
        });

        tldSelect.addEventListener('change', check);

        // Featured-TLD chips: jump to register mode and pre-select the TLD.
        widget.querySelectorAll('[data-dbd-featured-tld]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                renderMode('register');
                tldSelect.value = chip.getAttribute('data-dbd-featured-tld');
                nameInput.focus();
                check();
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var domain = domainFromInput();
            if (domain === '' || domain.indexOf('.') === -1) {
                resultEl.textContent = 'Enter a valid domain name.';
                resultEl.className = 'dbd-domain__result is-error';
                return;
            }
            var base = currentMode === 'transfer' ? '/domains/transfer' : '/domains/register';
            window.location.href = base + '?domain=' + encodeURIComponent(domain);
        });
    })();

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

    // Tab switcher. A container marked [data-tabs] holds buttons carrying
    // data-tab-target="<panel id>"; the panel selector comes from the
    // container's data-tab-panels.
    //
    // Replaces onclick="switchTab(event, 'dns')" on the client domain page.
    // The function was defined and the panels existed — the inline handler
    // attribute was simply never invoked, because a nonce authorises a
    // <script> block but not inline event handlers, and script-src has no
    // 'unsafe-inline'. So Nameservers, DNS Records and Advanced looked
    // unimplemented when they were only unreachable.
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-tab-target]');
        if (!tab) {
            return;
        }

        var container = tab.closest('[data-tabs]');
        if (!container) {
            return;
        }

        event.preventDefault();

        var panelSelector = container.getAttribute('data-tab-panels') || '.domain-tab-content';
        var panels = document.querySelectorAll(panelSelector);
        for (var i = 0; i < panels.length; i++) {
            panels[i].classList.remove('active');
        }

        var tabs = container.querySelectorAll('[data-tab-target]');
        for (var j = 0; j < tabs.length; j++) {
            tabs[j].classList.remove('active');
        }

        var target = document.getElementById(tab.getAttribute('data-tab-target'));
        if (target) {
            target.classList.add('active');
        }
        tab.classList.add('active');
    });

    // Copy-to-clipboard for a value rendered elsewhere on the page, e.g. the
    // domain EPP code. Also previously an inline onclick.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-copy-from]');
        if (!btn) {
            return;
        }

        var source = document.getElementById(btn.getAttribute('data-copy-from'));
        if (!source || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(source.textContent.trim()).then(function () {
            var original = btn.textContent;
            btn.textContent = '✓ Copied!';
            setTimeout(function () { btn.textContent = original; }, 2000);
        }).catch(function () {
            window.alert('Could not copy to clipboard — select the text and copy it manually.');
        });
    });

    // Guard for a bulk-action submit button: refuse to submit with nothing
    // ticked, optionally require a companion <select> to have a value, and
    // optionally confirm with the count.
    //
    // These were inline onclick="return validate…()" handlers. The nonce on a
    // <script> block does NOT extend to inline event-handler attributes, and
    // script-src carries no 'unsafe-inline', so CSP silently blocked every one
    // of them — the select-all box did nothing and, worse, "Delete Selected"
    // submitted with no confirmation at all.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-require-checked]');
        if (!btn) {
            return;
        }

        var checked = document.querySelectorAll(btn.getAttribute('data-require-checked') + ':checked');

        if (checked.length === 0) {
            event.preventDefault();
            window.alert(btn.getAttribute('data-require-checked-message') || 'Please select at least one item.');
            return;
        }

        var valueSelector = btn.getAttribute('data-require-value');
        if (valueSelector) {
            var field = document.querySelector(valueSelector);
            if (field && !field.value) {
                event.preventDefault();
                window.alert(btn.getAttribute('data-require-value-message') || 'Please choose a value first.');
                return;
            }
        }

        var confirmMessage = btn.getAttribute('data-confirm-count');
        if (confirmMessage && !window.confirm(confirmMessage.replace('{count}', checked.length))) {
            event.preventDefault();
        }
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

    // Password-only re-sync (import/whmcs.php). Unlike the full migration
    // above this reads nothing but the remote client accounts table and
    // only fills empty local password hashes — but it still goes over the
    // network, so the same AJAX + error-mapping handling applies.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.id !== 'password-sync-form') {
            return;
        }

        event.preventDefault();

        var syncError = document.getElementById('sync-error');
        var syncSuccess = document.getElementById('sync-success');
        if (syncError) { syncError.style.display = 'none'; }
        if (syncSuccess) { syncSuccess.style.display = 'none'; }

        var formData = new FormData(form);
        formData.append('ajax', '1');

        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            elements[i].disabled = true;
        }
        var syncBtn = document.getElementById('password-sync-btn');
        if (syncBtn) { syncBtn.innerText = 'Syncing...'; }

        function reEnableForm() {
            for (var j = 0; j < elements.length; j++) {
                elements[j].disabled = false;
            }
            if (syncBtn) { syncBtn.innerText = 'Sync Client Passwords'; }
        }

        fetch('/admin/import/whmcs/passwords', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            redirect: 'manual',
        })
        .then(function (r) {
            return r.text().then(function (body) {
                return { status: r.status, type: r.type, body: body };
            });
        })
        .then(function (resp) {
            reEnableForm();

            var data = null;
            try { data = JSON.parse(resp.body); } catch (e) { data = null; }

            if (data) {
                if (data.success) {
                    if (syncSuccess) {
                        var detail = '';
                        if (typeof data.matched === 'number' && typeof data.not_found === 'number') {
                            detail = ' (' + data.matched + ' password(s) restored, ' + data.not_found + ' local account(s) had no matching WHMCS record)';
                        }
                        syncSuccess.innerText = (data.message || 'Password sync completed successfully!') + detail;
                        syncSuccess.style.display = 'block';
                    }
                } else if (syncError) {
                    syncError.innerText = data.message || 'Password sync failed.';
                    syncError.style.display = 'block';
                }
                return;
            }

            if (!syncError) { return; }
            if (resp.status === 403) {
                syncError.innerText = 'Security check failed (403) — your session or CSRF token has expired. Reload this page to get a fresh token, then try the sync again. (Nothing was changed.)';
            } else if (resp.status === 401 || resp.status === 419 || resp.type === 'opaqueredirect' || resp.status === 0) {
                syncError.innerText = 'You appear to be logged out. Log back into the admin area, reload this page, then retry the sync. (Nothing was changed.)';
            } else if (resp.status >= 500) {
                syncError.innerText = 'The server hit a fatal error (HTTP ' + resp.status + ') before it could respond. Check storage/migration_error.log on the server for the exact cause.';
            } else {
                syncError.innerText = 'The server returned an unexpected response (HTTP ' + resp.status + '). Nothing was changed.';
            }
            syncError.style.display = 'block';
        })
        .catch(function () {
            reEnableForm();
            if (syncError) {
                syncError.innerText = 'Lost connection to the server before it responded — the sync may still have completed server-side. Reload this page and check the recent imports table below.';
                syncError.style.display = 'block';
            }
        });
    });

    // Domain Spinner (domains/register.php) — asks /domains/spin for name
    // variations of whatever's typed in the search box against whichever
    // TLDs an admin enabled for the spinner. /domains/spin returns those
    // combinations unchecked (it used to check each one live, sequentially,
    // before responding — up to 15 registrar round-trips on one request,
    // which is why this box could sit on "Spinning..." for a long time); the
    // real availability check for each candidate happens here instead, one
    // fetch per candidate, all in parallel, and only the available ones get
    // rendered, with the same "Register" trigger the main search results use.
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
        resultsEl.innerHTML = '<div style="padding:var(--cv-space-3);text-align:center;color:var(--cv-text-secondary);">Loading suggestions...</div>';

        function restoreTrigger() {
            trigger.disabled = false;
            trigger.innerText = originalLabel;
        }

        function noSuggestions(message) {
            resultsEl.innerHTML = '<p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">' + message + '</p>';
        }

        function renderAvailable(available) {
            resultsEl.innerHTML = '';

            available.forEach(function (c) {
                var card = document.createElement('div');
                card.className = 'cv-card';
                card.style.cssText = 'margin-bottom:var(--cv-space-3);display:flex;justify-content:space-between;align-items:center;';

                var left = document.createElement('div');
                var title = document.createElement('strong');
                title.style.cssText = 'font-size:1.1rem;color:var(--cv-text-primary);';
                title.innerText = c.domain;

                var statusRow = document.createElement('div');
                statusRow.style.cssText = 'font-size:var(--cv-text-sm);color:#10b981;font-weight:700;margin-top:0.3rem;display:flex;align-items:center;gap:0.5rem;';
                statusRow.innerHTML = '<span style="font-weight:800;font-size:0.85rem;padding:0.35rem 0.75rem;text-transform:uppercase;letter-spacing:0.05em;background:#10b981;color:#ffffff;border-radius:6px;box-shadow:0 2px 4px rgba(16,185,129,0.3);">&#10004; AVAILABLE</span>';

                var priceSpan = document.createElement('span');
                priceSpan.style.cssText = 'font-size:1rem;color:var(--cv-text-primary);';
                priceSpan.innerText = (c.avail.formatted_price || ('$' + Number(c.avail.price).toFixed(2))) + '/yr';
                statusRow.appendChild(priceSpan);

                left.appendChild(title);
                left.appendChild(statusRow);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cv-btn';
                btn.setAttribute('data-domain-register-trigger', c.domain);
                btn.innerText = 'Register';

                card.appendChild(left);
                card.appendChild(btn);
                resultsEl.appendChild(card);
            });
        }

        fetch('/domains/spin?name=' + encodeURIComponent(name), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var candidates = data.candidates || [];

            if (candidates.length === 0) {
                noSuggestions(data.message || 'No available suggestions found — try a different name.');
                restoreTrigger();
                return;
            }

            Promise.all(candidates.map(function (c) {
                return fetch('/domains/availability?domain=' + encodeURIComponent(c.domain), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(function (r) { return r.json(); })
                .then(function (avail) { return { domain: c.domain, avail: avail }; })
                .catch(function () { return null; });
            }))
            .then(function (checked) {
                var available = checked.filter(function (c) { return c && c.avail && c.avail.checked && c.avail.available; });

                if (available.length === 0) {
                    noSuggestions('No available suggestions found — try a different name.');
                    return;
                }

                renderAvailable(available);
            })
            .finally(restoreTrigger);
        })
        .catch(function () {
            noSuggestions('Could not fetch suggestions right now.');
            restoreTrigger();
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

    // Select-all checkbox delegation (CSP compliant)
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target.matches('[data-select-all-trigger]')) {
            var selector = target.getAttribute('data-select-all-trigger');
            var checkboxes = document.querySelectorAll(selector);
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = target.checked;
            }
            updateBulkDeleteButtonVisibility(selector);
            updateMergeSelectedButtonVisibility(selector);
        }
        if (target.matches('[data-select-all-item]')) {
            var group = target.getAttribute('data-select-all-item');
            var selectAll = document.querySelector('[data-select-all-trigger="' + group + '"]');
            var checkboxes = document.querySelectorAll(group);
            if (selectAll) {
                var allChecked = true;
                for (var j = 0; j < checkboxes.length; j++) {
                    if (!checkboxes[j].checked) {
                        allChecked = false;
                        break;
                    }
                }
                selectAll.checked = allChecked;
            }
            updateBulkDeleteButtonVisibility(group);
            updateMergeSelectedButtonVisibility(group);
        }
    });

    function updateBulkDeleteButtonVisibility(selector) {
        var checkboxes = document.querySelectorAll(selector);
        var anyChecked = false;
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                anyChecked = true;
                break;
            }
        }
        var deleteBtns = document.querySelectorAll('[data-bulk-delete-for="' + selector + '"]');
        for (var j = 0; j < deleteBtns.length; j++) {
            deleteBtns[j].style.display = anyChecked ? 'inline-block' : 'none';
        }
    }

    // Support tickets list (support/tickets-index.php): "Merge Selected"
    // only makes sense for exactly two tickets (a merge is always one
    // source into one target), unlike Close/Delete which apply to any
    // number — so it gets its own visibility rule rather than reusing
    // data-bulk-delete-for's "any checked" one.
    function updateMergeSelectedButtonVisibility(selector) {
        var checkboxes = document.querySelectorAll(selector);
        var checkedCount = 0;
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                checkedCount++;
            }
        }
        var mergeBtns = document.querySelectorAll('[data-merge-selected-for="' + selector + '"]');
        for (var j = 0; j < mergeBtns.length; j++) {
            mergeBtns[j].style.display = checkedCount === 2 ? 'inline-block' : 'none';
        }
    }

    // Clicking "Merge Selected" doesn't submit a form — it jumps to the
    // SOURCE ticket's own page (the one that's about to be merged away),
    // with its merge form's target field prefilled — the merge form always
    // posts "merge THIS ticket into target_ticket_id", so landing on the
    // source's page is what makes the prefilled submit actually merge the
    // two the way this button describes. The lower ticket id is treated as
    // the survivor and the higher as the one being merged away — a
    // deterministic rule since checkbox clicks don't carry a reliable
    // "which one first" order.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-merge-selected-for]') : null;
        if (!btn) {
            return;
        }

        event.preventDefault();

        var selector = btn.getAttribute('data-merge-selected-for');
        var checkboxes = document.querySelectorAll(selector);
        var ids = [];
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                ids.push(parseInt(checkboxes[i].value, 10));
            }
        }

        if (ids.length !== 2) {
            return;
        }

        var survivor = Math.min(ids[0], ids[1]);
        var mergeAway = Math.max(ids[0], ids[1]);

        window.location.href = '/admin/tickets/' + mergeAway + '?merge_target_prefill=' + survivor;
    });

    // Admin: Show hostname hint for specific server modules
    document.addEventListener('change', function(event) {
        if (!event.target.matches('[data-server-module-select]')) return;
        
        var module = event.target.value;
        var hintEl = event.target.closest('form').querySelector('#hostname-hint');
        if (!hintEl) return;
        
        if (module === 'interserver-vps' || module === 'interserver-dedicated' || module === 'interserver_vps' || module === 'interserver_dedicated') {
            hintEl.innerHTML = 'Hint: Use <strong>https://my.interserver.net/</strong>';
            hintEl.style.display = 'block';
        } else if (module === 'nocix-dedicated' || module === 'nocix_dedicated' || module === 'nocix') {
            hintEl.innerHTML = 'Hint: Use <strong>https://manage.nocix.net/</strong>';
            hintEl.style.display = 'block';
        } else if (module === 'resellerclub-email' || module === 'resellerclub_email') {
            hintEl.innerHTML = 'Hint: Use <strong>https://httpapi.com/api</strong>';
            hintEl.style.display = 'block';
        } else {
            hintEl.style.display = 'none';
        }
    });

    // Trigger module select logic once on load if present
    var serverModuleSelect = document.querySelector('[data-server-module-select]');
    if (serverModuleSelect) {
        serverModuleSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Admin Products: Bulk Update Server Settings Toggle & Submit (CSP-compliant event delegation)
    document.addEventListener('click', function(event) {
        var toggle = event.target.closest('#toggle-bulk-update-form, [data-toggle-bulk-update]');
        if (toggle) {
            event.preventDefault();
            var section = document.getElementById('bulk-update-section');
            if (section) {
                var isHidden = window.getComputedStyle(section).display === 'none';
                section.style.setProperty('display', isHidden ? 'block' : 'none', 'important');
            }
            return;
        }

        var cancel = event.target.closest('#cancel-bulk-update-btn, [data-cancel-bulk-update]');
        if (cancel) {
            event.preventDefault();
            var sectionCancel = document.getElementById('bulk-update-section');
            if (sectionCancel) {
                sectionCancel.style.setProperty('display', 'none', 'important');
            }
            return;
        }
    });

    document.addEventListener('change', function(event) {
        if (event.target.id === 'select-all-products') {
            var checked = event.target.checked;
            document.querySelectorAll('.product-select-checkbox').forEach(function(cb) {
                cb.checked = checked;
            });
            updateBulkProductsState();
        } else if (event.target.classList && event.target.classList.contains('product-select-checkbox')) {
            updateBulkProductsState();
        }
    });

    function updateBulkProductsState() {
        var selected = document.querySelectorAll('.product-select-checkbox:checked');
        var count = selected.length;
        var btn = document.getElementById('bulk-submit-btn');
        var countLabel = document.getElementById('selected-count-label');
        var section = document.getElementById('bulk-update-section');

        if (countLabel) {
            countLabel.textContent = count;
        }
        if (btn) {
            btn.disabled = (count === 0);
        }
        if (count > 0 && section) {
            section.style.setProperty('display', 'block', 'important');
        }
    }

    document.addEventListener('submit', function(event) {
        var form = event.target.closest('#bulk-update-form');
        if (!form) return;
        event.preventDefault();

        var selected = Array.from(document.querySelectorAll('.product-select-checkbox:checked'))
            .map(function(cb) { return cb.value; });

        if (selected.length === 0) {
            alert('Please select at least one product using the checkboxes below.');
            return;
        }

        var serverGroupId = form.querySelector('[name="server_group_id"]').value;
        var autosetup = form.querySelector('[name="autosetup"]').value;
        var requireDomain = form.querySelector('[name="require_domain"]').value;
        var isUpsell = form.querySelector('[name="is_upsell"]').value;

        if (!serverGroupId && !autosetup && !requireDomain && !isUpsell) {
            alert('Please select at least one setting to update.');
            return;
        }

        var btn = document.getElementById('bulk-submit-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳ Updating...';
        }

        var formData = new FormData();
        var tokenEl = form.querySelector('[name="_token"]');
        if (tokenEl) {
            formData.append('_token', tokenEl.value);
        }
        selected.forEach(function(id) { formData.append('product_ids[]', id); });
        if (serverGroupId) formData.append('server_group_id', serverGroupId);
        if (autosetup) formData.append('autosetup', autosetup);
        if (requireDomain) formData.append('require_domain', requireDomain);
        if (isUpsell) formData.append('is_upsell', isUpsell);

        fetch('/admin/products/bulk-update', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        })
        .then(async function(r) {
            var contentType = r.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                var text = await r.text();
                throw new Error('Server returned non-JSON response (Status ' + r.status + '). Please refresh and try again.');
            }
            return r.json();
        })
        .then(function(data) {
            if (data && data.success) {
                alert(data.message || 'Products updated successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + ((data && data.message) ? data.message : 'Unknown error during bulk update'));
            }
        })
        .catch(function(err) {
            alert('Bulk Update Error: ' + (err.message || 'Network error'));
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.textContent = '✓ Update Selected Products';
            }
        });
    });

    // Campaign target type switcher (All vs Inactive vs Group vs Individual vs External)
    document.addEventListener('change', function(event) {
        if (event.target.id === 'campaign-target-type') {
            var val = event.target.value;
            var groupField = document.getElementById('target-group-field');
            var individualField = document.getElementById('target-individual-field');
            var externalField = document.getElementById('target-external-field');
            var inactiveField = document.getElementById('target-inactive-field');

            if (groupField) groupField.style.display = (val === 'group') ? 'block' : 'none';
            if (individualField) individualField.style.display = (val === 'individual') ? 'block' : 'none';
            if (externalField) externalField.style.display = (val === 'external') ? 'block' : 'none';
            if (inactiveField) inactiveField.style.display = (val === 'inactive') ? 'block' : 'none';
        } else if (event.target.id === 'select-all-clients') {
            var isChecked = event.target.checked;
            var checkboxes = document.querySelectorAll('.client-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = isChecked;
            });
            var bulkBtn = document.getElementById('bulk-delete-clients-btn');
            if (bulkBtn) bulkBtn.disabled = !isChecked && document.querySelectorAll('.client-checkbox:checked').length === 0;
        } else if (event.target.classList.contains('client-checkbox')) {
            var checkedCount = document.querySelectorAll('.client-checkbox:checked').length;
            var bulkBtn = document.getElementById('bulk-delete-clients-btn');
            if (bulkBtn) bulkBtn.disabled = checkedCount === 0;
            var selectAll = document.getElementById('select-all-clients');
            if (selectAll) {
                var total = document.querySelectorAll('.client-checkbox').length;
                selectAll.checked = (total > 0 && checkedCount === total);
            }
        }
    });

    // Client notification compose form (notifications/client-notifications-index.php)
    // — target type switcher (Individual vs Selected vs All) and a client-side
    // filter over the "Selected Clients" checkbox list, same shape as the
    // campaign target switcher above but a distinct id so the two never collide.
    document.addEventListener('change', function(event) {
        if (event.target.id === 'client-notification-target-type') {
            var val = event.target.value;
            var individualField = document.getElementById('notif-target-individual-field');
            var selectedField = document.getElementById('notif-target-selected-field');

            if (individualField) individualField.style.display = (val === 'individual') ? 'block' : 'none';
            if (selectedField) selectedField.style.display = (val === 'selected') ? 'block' : 'none';
        }
    });

    document.addEventListener('input', function(event) {
        if (!event.target.matches('[data-notif-client-filter]')) {
            return;
        }

        var needle = event.target.value.trim().toLowerCase();
        document.querySelectorAll('[data-notif-client-row]').forEach(function(row) {
            var label = row.querySelector('[data-notif-client-label]');
            var text = label ? label.textContent.toLowerCase() : '';
            row.style.display = (needle === '' || text.indexOf(needle) !== -1) ? 'block' : 'none';
        });
    });

    // Admin clients live search (debounced AJAX filter, no page reload) — the
    // server returns just the results table + pagination for ?fragment=1, and
    // it is swapped into #admin-client-results, so typing never loses focus.
    var clientSearchInput = document.getElementById('client-search-input');
    var clientResultsContainer = document.getElementById('admin-client-results');
    if (clientSearchInput && clientResultsContainer) {
        var searchDebounceTimer = null;
        var searchRequestSeq = 0;
        var clientSearchXhr = null;
        var clientSearchMessage = null;

        function performClientSearch(query) {
            if (clientSearchXhr) {
                clientSearchXhr.abort();
            }

            var seq = ++searchRequestSeq;
            var url = '/admin/clients?q=' + encodeURIComponent(query) + '&fragment=1';

            clientSearchXhr = new XMLHttpRequest();
            clientSearchXhr.open('GET', url);
            clientSearchXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            clientSearchXhr.onreadystatechange = function () {
                if (clientSearchXhr.readyState !== 4 || clientSearchXhr.status !== 200) {
                    return;
                }
                if (seq !== searchRequestSeq) {
                    return;
                }
                clientResultsContainer.innerHTML = clientSearchXhr.responseText;
                clientSearchMessage = null;
                clientSearchInput.focus();
                clientSearchInput.setSelectionRange(clientSearchInput.value.length, clientSearchInput.value.length);
            };
            clientSearchXhr.onerror = function () {
                if (seq !== searchRequestSeq) {
                    return;
                }
                if (!clientSearchMessage) {
                    clientSearchMessage = document.createElement('div');
                    clientSearchMessage.style.cssText = 'color:var(--cv-text-secondary);font-size:var(--cv-text-sm);padding:16px;';
                    clientResultsContainer.prepend(clientSearchMessage);
                }
                clientSearchMessage.textContent = 'Could not refresh results.';
            };
            clientSearchXhr.send();
        }

        clientSearchInput.addEventListener('input', function () {
            clearTimeout(searchDebounceTimer);
            var query = this.value;
            searchDebounceTimer = setTimeout(function () {
                performClientSearch(query);
            }, 400);
        });
    }

    // Admin "create order" client picker (order-create.php) — an autocomplete
    // search over /admin/clients/options instead of a full-page <select> that
    // would be unusable once the client base grows. Type >= 2 chars, pick a
    // match; the hidden client_id input drives the form. Also toggles the
    // "existing service/domain" hint when the checkbox is flipped.
    var orderClientPicker = document.querySelector('[data-client-picker]');
    if (orderClientPicker) {
        var searchInput = orderClientPicker.querySelector('[data-client-search-input]');
        var idInput = orderClientPicker.querySelector('[data-client-id-input]');
        var resultsEl = orderClientPicker.querySelector('[data-client-results]');
        var hintEl = orderClientPicker.querySelector('[data-client-picker-hint]');
        var pickerTimer = null;

        // Preselect the label when the form re-renders with a client already
        // chosen (validation error) — look the id up from the server data.
        var preselectedId = (idInput ? idInput.value : '');
        if (preselectedId && searchInput && hintEl) {
            fetch('/admin/clients/options?q=&limit=100', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = data.clients || [];
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].id) === String(preselectedId)) {
                        searchInput.value = list[i].first_name + ' ' + list[i].last_name;
                        hintEl.textContent = list[i].email;
                        break;
                    }
                }
            })
            .catch(function () {});
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = this.value.trim();
                clearTimeout(pickerTimer);

                if (query.length < 2) {
                    if (resultsEl) resultsEl.style.display = 'none';
                    return;
                }

                pickerTimer = setTimeout(function () {
                    fetch('/admin/clients/options?q=' + encodeURIComponent(query), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!resultsEl) return;
                        resultsEl.innerHTML = '';

                        var list = data.clients || [];
                        if (list.length === 0) {
                            resultsEl.innerHTML = '<div style="padding:8px 12px;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">No clients match &ldquo;' + query.replace(/</g, '&lt;') + '&rdquo;</div>';
                            resultsEl.style.display = 'block';
                            return;
                        }

                        list.forEach(function (client) {
                            var row = document.createElement('button');
                            row.type = 'button';
                            row.className = 'cv-client-option';
                            row.style.cssText = 'display:block;width:100%;text-align:left;padding:8px 12px;background:none;border:none;border-bottom:1px solid var(--cv-border-default);cursor:pointer;font-size:var(--cv-text-sm);';
                            row.innerHTML = '<strong>' + (client.first_name + ' ' + client.last_name).replace(/</g, '&lt;') + '</strong> &mdash; <span style="color:var(--cv-text-secondary);">' + client.email.replace(/</g, '&lt;') + '</span>';
                            row.addEventListener('click', function () {
                                if (idInput) idInput.value = String(client.id);
                                if (searchInput) searchInput.value = client.first_name + ' ' + client.last_name;
                                if (hintEl) hintEl.textContent = client.email + ' (ID #' + client.id + ')';
                                if (resultsEl) resultsEl.style.display = 'none';
                            });
                            resultsEl.appendChild(row);
                        });

                        resultsEl.style.display = 'block';
                    })
                    .catch(function () {});
                }, 250);
            });

            searchInput.addEventListener('blur', function () {
                // Let a click on a result register before hiding.
                setTimeout(function () {
                    if (resultsEl) resultsEl.style.display = 'none';
                }, 150);
            });
        }
    }

    // Promo banner popup (partials/promo-banner.php) — shown once per browser
    // per day per banner. Rendered hidden server-side so it never flashes
    // before this runs; the dismiss cookie is checked here rather than
    // server-side because "seen it today" is a purely client-side fact,
    // same reasoning as the dark-mode flag using localStorage instead of a
    // round trip.
    var promoBanner = document.querySelector('[data-promo-banner]');
    if (promoBanner) {
        var promoBannerId = promoBanner.getAttribute('data-promo-banner-id');
        var dismissCookieName = 'cv_promo_banner_dismissed_' + promoBannerId;
        var alreadyDismissed = document.cookie.indexOf(dismissCookieName + '=1') !== -1;

        if (!alreadyDismissed) {
            setTimeout(function() {
                promoBanner.hidden = false;
            }, 900);
        }
    }

    document.addEventListener('click', function(event) {
        var dismissTrigger = event.target.closest('[data-promo-banner-dismiss]');
        if (!dismissTrigger) {
            return;
        }

        var banner = dismissTrigger.closest('[data-promo-banner]');
        if (!banner) {
            return;
        }

        banner.hidden = true;
        var bannerId = banner.getAttribute('data-promo-banner-id');
        document.cookie = 'cv_promo_banner_dismissed_' + bannerId + '=1; max-age=86400; path=/; samesite=lax';
    });

    // SMTP test-send on the General Settings screen: posts the recipient to
    // /admin/settings/general/test-send and surfaces the transport's outcome
    // inline, so an admin can confirm real SMTP delivery without digging
    // through the email log.
    var smtpTestButton = document.getElementById('smtp-test-send');
    if (smtpTestButton) {
        var smtpTestResult = document.getElementById('smtp-test-result');

        smtpTestButton.addEventListener('click', function() {
            var to = (document.getElementById('smtp-test-to') || {}).value || '';

            if (!to) {
                smtpTestResult.textContent = 'Enter a recipient email first.';
                smtpTestResult.style.color = 'var(--cv-danger, #dc2626)';
                return;
            }

            smtpTestButton.disabled = true;
            smtpTestButton.textContent = 'Sending…';
            smtpTestResult.textContent = '';
            smtpTestResult.style.color = '';

            var body = new URLSearchParams();
            body.set('_token', smtpTestButton.getAttribute('data-token') || '');
            body.set('to', to);

            // Explicit Content-Type — see the mail-piping test button: without
            // it PHP never populates $_POST and the CSRF token arrives empty.
            fetch('/admin/settings/general/test-send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    smtpTestResult.textContent = (data && data.message) || 'Test email failed.';
                    smtpTestResult.style.color = 'var(--cv-danger, #dc2626)';
                    return;
                }
                smtpTestResult.textContent = data.message || 'Test email sent successfully.';
                smtpTestResult.style.color = '#16a34a';
            })
            .catch(function () {
                smtpTestResult.textContent = 'Could not reach the server to send the test email.';
                smtpTestResult.style.color = 'var(--cv-danger, #dc2626)';
            })
            .then(function () {
                smtpTestButton.disabled = false;
                smtpTestButton.textContent = '📨 Send Test Email';
            });
        });
    }

    // Home page product-category tabs (pages/home.php): each [data-home-tab]
    // button reveals its matching [data-home-panel], one at a time. Delegated
    // so it works under the strict CSP (no inline handlers).
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-home-tab]');
        if (!tab) return;

        var idx = tab.getAttribute('data-home-tab');
        var tabList = tab.parentElement;
        var tabs = tabList.querySelectorAll('[data-home-tab]');
        for (var i = 0; i < tabs.length; i++) {
            var active = tabs[i] === tab;
            tabs[i].classList.toggle('is-active', active);
            tabs[i].setAttribute('aria-selected', active ? 'true' : 'false');
        }

        var panels = document.querySelectorAll('[data-home-panel]');
        for (var j = 0; j < panels.length; j++) {
            panels[j].hidden = panels[j].getAttribute('data-home-panel') !== idx;
        }
    });

    // Client "Cancel Order" modal (partials/cancel-order-modal.php): open /
    // close via delegated listeners — the strict CSP blocks inline onclick,
    // so the buttons expose data-cancel-order-open / data-cancel-order-close.
    document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-cancel-order-open]');
        if (open) {
            var modalOpen = document.getElementById('cancel-order-modal');
            if (modalOpen) { modalOpen.style.display = 'flex'; }
            return;
        }
        var close = event.target.closest('[data-cancel-order-close]');
        if (close) {
            var modalClose = document.getElementById('cancel-order-modal');
            if (modalClose) { modalClose.style.display = 'none'; }
        }
    });

    // Mail Piping "Test Connection": posts to /admin/mail-piping/test and
    // surfaces the IMAP auth/connect result inline so an admin can confirm the
    // mailbox settings (especially the [CLOSED] authenticate failure) without
    // waiting for the next 5-minute cron sweep.
    var mailPipingTestButton = document.getElementById('mailpiping-test');
    if (mailPipingTestButton) {
        var mailPipingTestResult = document.getElementById('mailpiping-test-result');

        mailPipingTestButton.addEventListener('click', function() {
            mailPipingTestButton.disabled = true;
            mailPipingTestButton.textContent = 'Testing…';
            mailPipingTestResult.textContent = '';
            mailPipingTestResult.style.color = '';

            var body = new URLSearchParams();
            body.set('_token', mailPipingTestButton.getAttribute('data-token') || '');

            // Explicit Content-Type: some browsers don't auto-set it for a
            // URLSearchParams body, and without it PHP never populates $_POST
            // so the CSRF token above arrives empty (403).
            fetch('/admin/mail-piping/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    mailPipingTestResult.textContent = (data && data.message) || 'Connection test failed.';
                    mailPipingTestResult.style.color = 'var(--cv-danger, #dc2626)';
                    return;
                }
                mailPipingTestResult.textContent = data.message || 'Connected and authenticated successfully.';
                mailPipingTestResult.style.color = '#16a34a';
            })
            .catch(function () {
                mailPipingTestResult.textContent = 'Could not reach the server to run the test.';
                mailPipingTestResult.style.color = 'var(--cv-danger, #dc2626)';
            })
            .then(function () {
                mailPipingTestButton.disabled = false;
                mailPipingTestButton.textContent = '🔌 Test Connection';
            });
        });
    }
})();
