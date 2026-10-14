/*
 * Free Reseller programme pages: the earnings calculator on the landing page,
 * and the application form's store ID preview, domain-option switch and live
 * domain availability check. Self-contained (no globals) and progressive: the
 * form works without it — the server re-checks the domain on submit anyway.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function money(symbol, amount) {
        var fixed = (Math.round(amount * 100) / 100).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return symbol + parts.join('.');
    }

    // --- earnings calculator ---------------------------------------------------
    function initCalculator(root) {
        var discount = parseFloat(root.getAttribute('data-discount') || '0') || 0;
        var maxMarkup = parseFloat(root.getAttribute('data-max-markup') || '1000') || 1000;
        var symbol = root.getAttribute('data-symbol') || '$';
        var inputs = {};
        var outputs = {};

        Array.prototype.forEach.call(root.querySelectorAll('[data-in]'), function (el) {
            inputs[el.getAttribute('data-in')] = el;
        });
        Array.prototype.forEach.call(root.querySelectorAll('[data-out]'), function (el) {
            outputs[el.getAttribute('data-out')] = el;
        });

        function set(key, text) {
            if (outputs[key]) {
                outputs[key].textContent = text;
            }
        }

        function update() {
            var price = parseFloat(inputs.price ? inputs.price.value : '0') || 0;
            var markup = Math.min(maxMarkup, Math.max(0, parseFloat(inputs.markup ? inputs.markup.value : '0') || 0));
            var customers = Math.max(0, parseInt(inputs.customers ? inputs.customers.value : '0', 10) || 0);
            var cost = price * (1 - discount / 100);
            var retail = price * (1 + markup / 100);
            var each = Math.max(0, retail - cost);

            set('price', money(symbol, price));
            set('markup', markup + '%');
            set('customers', String(customers));
            set('each', money(symbol, each) + ' / month');
            set('monthly', money(symbol, each * customers));
            set('yearly', money(symbol, each * customers * 12));
        }

        Object.keys(inputs).forEach(function (key) {
            inputs[key].addEventListener('input', update);
        });
        update();
    }

    // --- application form -------------------------------------------------------
    function slugify(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 30)
            .replace(/-+$/g, '');
    }

    function initApply(form) {
        var nameInput = form.querySelector('[data-fr-store-name]');
        var slugInput = form.querySelector('[data-fr-slug]');
        var slugTouched = slugInput && slugInput.value !== '';

        if (nameInput && slugInput) {
            slugInput.addEventListener('input', function () {
                slugTouched = slugInput.value !== '';
            });
            nameInput.addEventListener('input', function () {
                if (!slugTouched) {
                    slugInput.placeholder = slugify(nameInput.value) || slugInput.getAttribute('data-placeholder') || '';
                }
            });
            slugInput.setAttribute('data-placeholder', slugInput.placeholder);
            slugInput.addEventListener('blur', function () {
                if (slugInput.value !== '') {
                    slugInput.value = slugify(slugInput.value);
                }
            });
        }

        // Register-new vs existing domain.
        var note = form.querySelector('[data-fr-submit-note]');
        function showPane(mode) {
            Array.prototype.forEach.call(form.querySelectorAll('[data-fr-pane]'), function (pane) {
                pane.hidden = pane.getAttribute('data-fr-pane') !== mode;
            });
            if (note) {
                note.textContent = mode === 'register'
                    ? 'Your new domain will be waiting in your cart, ready to pay.'
                    : 'Free — nothing to pay.';
            }
        }
        Array.prototype.forEach.call(form.querySelectorAll('[data-fr-mode]'), function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    showPane(radio.value);
                }
            });
        });

        // Live availability.
        var domainInput = form.querySelector('[data-fr-domain-input]');
        var searchBtn = form.querySelector('[data-fr-domain-search]');
        var result = form.querySelector('[data-fr-domain-result]');

        if (!domainInput || !result) {
            return;
        }

        var resultIcon = result.querySelector('[data-fr-result-icon]');
        var resultName = result.querySelector('[data-fr-result-name]');
        var resultMsg = result.querySelector('[data-fr-result-msg]');
        var resultPrice = result.querySelector('[data-fr-result-price]');
        var defaultTld = domainInput.getAttribute('data-default-tld') || '.com';
        var lastQuery = '';
        var seq = 0;

        function normalise(value) {
            var v = String(value || '').trim().toLowerCase();
            v = v.replace(/^[a-z]+:\/\//, '').replace(/^www\./, '').split(/[\/?#\s]/)[0];
            if (v !== '' && v.indexOf('.') === -1) {
                v += defaultTld;
            }
            return v;
        }

        function show(state, name, message, price) {
            result.className = 'fr-result is-shown is-' + state;
            resultIcon.textContent = state === 'ok' ? '✓' : (state === 'bad' ? '✕' : '…');
            resultName.textContent = name;
            resultMsg.textContent = message;
            resultPrice.textContent = price || '';
        }

        function check() {
            var domain = normalise(domainInput.value);
            if (domain === '') {
                result.className = 'fr-result';
                return;
            }
            domainInput.value = domain;
            if (domain === lastQuery && result.classList.contains('is-ok')) {
                return;
            }
            lastQuery = domain;
            var mine = ++seq;
            show('wait', domain, 'Checking availability…', '');

            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/domains/availability?domain=' + encodeURIComponent(domain), true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4 || mine !== seq) {
                    return;
                }
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                if (!data) {
                    show('bad', domain, 'We could not check this domain right now. You can still continue — we check it again when you submit.', '');
                    return;
                }
                if (data.checked && data.available) {
                    show('ok', domain, 'Great news — it is available! It will be added to your cart when you finish.', data.formatted_price ? data.formatted_price + '/yr' : '');
                } else if (data.checked) {
                    show('bad', domain, data.message || 'Sorry, this domain is already taken. Try another name or extension.', '');
                } else {
                    show('bad', domain, data.message || 'This domain cannot be checked.', '');
                }
            };
            xhr.send();
        }

        if (searchBtn) {
            searchBtn.addEventListener('click', check);
        }
        domainInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                check();
            }
        });
        domainInput.addEventListener('change', check);

        Array.prototype.forEach.call(form.querySelectorAll('[data-fr-tld]'), function (chip) {
            chip.addEventListener('click', function () {
                var base = normalise(domainInput.value).split('.')[0] || slugify(nameInput ? nameInput.value : '').replace(/-/g, '');
                if (!base) {
                    domainInput.focus();
                    return;
                }
                domainInput.value = base + chip.getAttribute('data-fr-tld');
                check();
            });
        });

        if (domainInput.value !== '' && !domainInput.closest('[hidden]')) {
            check();
        }
    }

    ready(function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-fr-calc]'), initCalculator);
        Array.prototype.forEach.call(document.querySelectorAll('[data-fr-apply]'), initApply);
    });
})();
