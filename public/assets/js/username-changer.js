/*
 * cPanel Username Changer — client modal.
 *
 * Built so the client never waits:
 *   1. Every keystroke is judged LOCALLY first (format, length, "test" prefix,
 *      reserved words) — the same rules as UsernamePolicy.php, embedded in the
 *      page — so most verdicts appear in the same frame as the key press.
 *   2. Only a locally-valid name asks the server "is it free?". That request is
 *      debounced (120 ms), cancellable (AbortController — a newer keystroke
 *      kills the older request), and cached per name, so going back to a name
 *      you already tried is instant.
 *   3. The server settles known-taken names from its records in about a
 *      millisecond; a name free in the records is then confirmed with WHM
 *      itself, so "available" always means the hosting server agreed. "Checking
 *      with the server…" only appears if that takes longer than 160 ms. A server
 *      that cannot be reached is a retryable warning — never cached, never
 *      shown as available.
 *   4. Hovering or focusing the Change button warms the connection AND asks the
 *      server to re-sync its copy of the WHM account list if it is stale.
 *   5. Suggestion chips are never assumed free: when the modal opens they are
 *      verified one by one in the background; taken ones disappear and verified
 *      ones get a tick, so clicking a chip is an instant, true answer.
 *
 * No framework, no globals beyond one IIFE; works with the CSP (external file).
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 120;
    var SPINNER_DELAY_MS = 160;
    var roots = {};

    function $(root, sel) { return root.querySelector(sel); }
    function $all(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

    function init(root) {
        var sid = root.getAttribute('data-ucn-root');
        if (roots[sid]) { return roots[sid]; }

        var dataEl = $(root, '[data-ucn-data]');
        var data = {};
        try { data = JSON.parse(dataEl ? dataEl.textContent : '{}'); } catch (e) { data = {}; }

        var rules = data.rules || { min: 5, max: 16, reserved: [] };
        var reserved = {};
        (rules.reserved || []).forEach(function (w) { reserved[w] = true; });

        var state = {
            sid: sid,
            root: root,
            data: data,
            modal: $(root, '[data-ucn-modal]'),
            input: $(root, '[data-ucn-input]'),
            verdict: $(root, '[data-ucn-verdict]'),
            status: $(root, '[data-ucn-status]'),
            chips: $(root, '[data-ucn-chips]'),
            form: $(root, '[data-ucn-form]'),
            flash: $(root, '[data-ucn-flash]'),
            cache: new Map(),
            controller: null,
            timer: null,
            spinnerTimer: null,
            okFor: null,
            step: 1,
            lastFocus: null,
            checkUrl: '/client/services/' + sid + '/username/check?u=',
            warmUrl: '/client/services/' + sid + '/username/warm',
            warmed: null,
            retryTimer: null,
            chipBusy: false
        };

        state.localRule = function (name) {
            if (name === '') { return ['empty', 'Enter a username.']; }
            if (/^[0-9]/.test(name)) { return ['leading_digit', 'The username must start with a letter.']; }
            if (!/^[a-z][a-z0-9]*$/.test(name)) { return ['chars', 'Use only lowercase letters (a–z) and digits (0–9).']; }
            if (name.length < rules.min) { return ['short', 'Use at least ' + rules.min + ' characters.']; }
            if (name.length > rules.max) { return ['long', 'Use at most ' + rules.max + ' characters.']; }
            if (name.indexOf('test') === 0) { return ['test_prefix', 'cPanel does not allow usernames that start with "test".']; }
            if (reserved[name]) { return ['reserved', 'That name is reserved by the system. Please choose another.']; }
            if (name === String(data.current || '').toLowerCase()) { return ['same', 'That is already your username.']; }
            return null;
        };

        wire(state);
        roots[sid] = state;
        return state;
    }

    function setVerdict(state, kind, text) {
        if (!state.verdict) { return; }
        state.verdict.textContent = text;
        state.verdict.className = 'ucn-verdict' + (kind ? ' ucn-verdict--' + kind : '');
        if (state.status) { state.status.className = 'ucn-status' + (kind ? ' ucn-status--' + kind : ''); }
        if (state.input) { state.input.setAttribute('aria-invalid', kind === 'bad' ? 'true' : 'false'); }
    }

    function setNextEnabled(state, enabled) {
        var btn = $(state.root, '[data-ucn-step="1"] [data-ucn-next]');
        if (btn) { btn.disabled = !enabled; }
    }

    function renderChips(state, list) {
        if (!state.chips || !Array.isArray(list)) { return; }
        state.chips.textContent = '';
        list.forEach(function (s) {
            var known = state.cache.get(s);
            if (known && !known.ok) { return; } // already known to be taken
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ucn-chip';
            b.setAttribute('data-ucn-chip', s);
            b.textContent = s;
            if (known && known.ok) { b.classList.add('is-verified'); b.title = 'Available on the server'; }
            state.chips.appendChild(b);
        });
        verifyChips(state);
    }

    function getJson(url, signal) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: signal
        }).then(function (r) { return r.json(); });
    }

    // Checks suggestion chips one at a time in the background (never more than
    // one request in flight, so it cannot crowd out what the person types).
    // Taken chips are removed; free ones get a tick and a cached answer.
    function verifyChips(state) {
        if (state.chipBusy || !state.chips || !state.modal || state.modal.hidden) { return; }
        var next = $all(state.chips, '[data-ucn-chip]').filter(function (b) {
            return !b.classList.contains('is-verified') && !b.hasAttribute('data-ucn-unverified');
        })[0];
        if (!next) { return; }
        var name = next.getAttribute('data-ucn-chip');
        var known = state.cache.get(name);
        if (known) { markChip(state, next, known); verifyChips(state); return; }
        state.chipBusy = true;
        next.classList.add('is-checking');
        var done = function (res) {
            state.chipBusy = false;
            next.classList.remove('is-checking');
            if (res && res.code === 'slow_down') { return; }
            if (res && res.code && res.code !== 'unverified') { state.cache.set(name, res); }
            markChip(state, next, res || { code: 'unverified' });
            setTimeout(function () { verifyChips(state); }, 0);
        };
        getJson(state.checkUrl + encodeURIComponent(name)).then(done).catch(function () { done({ code: 'unverified' }); });
    }

    function markChip(state, chip, res) {
        if (res.ok) {
            chip.classList.add('is-verified');
            chip.title = 'Available on the server';
        } else if (res.code === 'unverified') {
            chip.setAttribute('data-ucn-unverified', '1'); // keep it; checked when picked
        } else if (chip.parentNode) {
            chip.parentNode.removeChild(chip);
        }
    }

    function applyResult(state, name, res) {
        if (!state.input || state.input.value !== name) { return; } // stale answer
        clearTimeout(state.spinnerTimer);
        if (res.ok) {
            state.okFor = name;
            setVerdict(state, 'ok', '✓ “' + name + '” is available.');
            setNextEnabled(state, true);
        } else if (res.code === 'unverified') {
            // The hosting server did not answer in time. Not "available", not
            // "taken" — retry once on our own, then leave it to the person.
            state.okFor = null;
            setVerdict(state, 'wait', res.message || 'We couldn\'t reach the hosting server. Please try again.');
            setNextEnabled(state, false);
            if (!res.retried) {
                clearTimeout(state.retryTimer);
                state.retryTimer = setTimeout(function () {
                    if (state.input.value === name) { check(state, true); }
                }, 2500);
            }
        } else {
            state.okFor = null;
            setVerdict(state, res.code === 'slow_down' ? 'wait' : 'bad', res.message || 'Not available.');
            setNextEnabled(state, false);
        }
        if (res.suggestions && res.suggestions.length) { renderChips(state, res.suggestions); }
    }

    function check(state, isRetry) {
        var raw = state.input.value;
        var name = raw.toLowerCase().replace(/\s+/g, '');
        if (name !== raw) {
            var pos = state.input.selectionStart;
            state.input.value = name;
            try { state.input.setSelectionRange(pos, pos); } catch (e) { /* not focusable */ }
        }

        $all(state.root, '[data-ucn-new-label]').forEach(function (el) { el.textContent = name || 'new'; });

        clearTimeout(state.timer);
        clearTimeout(state.spinnerTimer);
        clearTimeout(state.retryTimer);
        if (state.controller) { state.controller.abort(); state.controller = null; }
        state.okFor = null;
        setNextEnabled(state, false);

        var local = state.localRule(name);
        if (local) {
            setVerdict(state, name === '' ? '' : 'bad', local[1]);
            return;
        }

        var cached = state.cache.get(name);
        if (cached) { applyResult(state, name, cached); return; }

        // Only show "checking…" if the answer is not back almost at once —
        // a spinner that flashes for 30 ms reads as lag, not speed.
        state.spinnerTimer = setTimeout(function () { setVerdict(state, 'wait', 'Checking with the server…'); }, SPINNER_DELAY_MS);

        state.timer = setTimeout(function () {
            var ctl = typeof AbortController === 'function' ? new AbortController() : null;
            state.controller = ctl;
            getJson(state.checkUrl + encodeURIComponent(name), ctl ? ctl.signal : undefined).then(function (res) {
                res = res || {};
                // Momentary answers are never cached: a retry must really retry.
                if (res.code !== 'slow_down' && res.code !== 'unverified') { state.cache.set(name, res); }
                if (isRetry) { res.retried = true; }
                applyResult(state, name, res);
            }).catch(function (err) {
                if (err && err.name === 'AbortError') { return; }
                clearTimeout(state.spinnerTimer);
                if (state.input.value === name) {
                    state.okFor = null;
                    setNextEnabled(state, false);
                    setVerdict(state, 'wait', 'Could not check right now — please try again in a moment.');
                }
            });
        }, isRetry ? 0 : DEBOUNCE_MS);
    }

    // Opens the connection and asks the server to re-sync its copy of the WHM
    // account list if it is stale — before the first keystroke. Resolves when
    // done (or failed); never blocks typing.
    function warm(state) {
        if (state.warmed) { return state.warmed; }
        state.warmed = getJson(state.warmUrl).catch(function () { return null; });
        return state.warmed;
    }

    function showStep(state, n) {
        state.step = n;
        $all(state.root, '[data-ucn-step]').forEach(function (el) { el.hidden = el.getAttribute('data-ucn-step') !== String(n); });
        $all(state.root, '[data-ucn-step-dot]').forEach(function (el) {
            var i = parseInt(el.getAttribute('data-ucn-step-dot'), 10);
            el.classList.toggle('is-active', i === n);
            el.classList.toggle('is-done', i < n);
        });
        var focusTarget = $(state.root, '[data-ucn-step="' + n + '"] input, [data-ucn-step="' + n + '"] button');
        if (focusTarget) { focusTarget.focus({ preventScroll: true }); }
    }

    function open(state) {
        if (!state.modal) { return; }
        state.lastFocus = document.activeElement;
        state.modal.hidden = false;
        document.documentElement.classList.add('ucn-lock');
        requestAnimationFrame(function () { state.modal.classList.add('is-open'); });
        // Verify the suggestion chips once the account copy is fresh.
        warm(state).then(function () { verifyChips(state); });
        if (state.input) { setTimeout(function () { state.input.focus(); }, 30); }
    }

    function close(state) {
        if (!state.modal) { return; }
        state.modal.classList.remove('is-open');
        document.documentElement.classList.remove('ucn-lock');
        setTimeout(function () { state.modal.hidden = true; }, 180);
        if (state.lastFocus && state.lastFocus.focus) { state.lastFocus.focus(); }
    }

    function flash(state, ok, msg) {
        if (!state.flash) { return; }
        state.flash.hidden = false;
        state.flash.className = 'ucn-flash ucn-flash--' + (ok ? 'ok' : 'bad');
        state.flash.textContent = msg;
    }

    function post(state, form, onDone) {
        var body = new FormData(form);
        body.append('ajax', '1');
        return fetch(form.getAttribute('action'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected response (HTTP ' + r.status + ').' }; }); })
          .then(function (res) { flash(state, !!res.ok, res.message || (res.ok ? 'Done.' : 'Something went wrong.')); if (onDone) { onDone(res); } })
          .catch(function () { flash(state, false, 'Network error — please try again.'); if (onDone) { onDone({ ok: false }); } });
    }

    function wire(state) {
        var root = state.root;

        if (state.input) {
            state.input.addEventListener('input', function () { check(state); });
            state.input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (state.okFor === state.input.value) { showStep(state, 2); }
                }
            });
        }

        root.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target : e.target.parentNode;
            var chip = t.closest('[data-ucn-chip]');
            if (chip && state.input) {
                state.input.value = chip.getAttribute('data-ucn-chip');
                check(state);
                state.input.focus();
                return;
            }
            if (t.closest('[data-ucn-close]')) { close(state); return; }
            if (t.closest('[data-ucn-back]')) { showStep(state, Math.max(1, state.step - 1)); return; }
            var next = t.closest('[data-ucn-next]');
            if (next && !next.disabled) { showStep(state, state.step + 1); }
        });

        $all(root, '[data-ucn-ack]').forEach(function (box) {
            box.addEventListener('change', function () {
                var all = $all(root, '[data-ucn-ack]').every(function (b) { return b.checked; });
                var btn = $(root, '[data-ucn-ack-next]');
                if (btn) { btn.disabled = !all; }
            });
        });

        $all(root, '[data-ucn-method]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var pin = $(root, '[data-ucn-pin]');
                if (pin) { pin.hidden = radio.value !== 'pin' || !radio.checked; }
            });
        });
        var first = $(root, '[data-ucn-method]:checked');
        if (first && first.value === 'pin') { var p = $(root, '[data-ucn-pin]'); if (p) { p.hidden = false; } }

        if (state.form) {
            state.form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = $(root, '[data-ucn-submit]');
                if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }
                post(state, state.form, function (res) {
                    if (res.ok) {
                        setTimeout(function () { window.location.reload(); }, 1600);
                    } else if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Request change';
                        if (res.code && ['taken', 'pending', 'server', 'prefix8', 'unverified', 'reserved', 'chars', 'short', 'long', 'test_prefix', 'same'].indexOf(res.code) !== -1) {
                            state.cache.delete(state.input.value);
                            showStep(state, 1);
                            setVerdict(state, 'bad', res.message);
                        }
                    }
                });
            });
        }

        $all(root, '[data-ucn-ajax]').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                var q = f.getAttribute('data-ucn-confirm');
                if (q && !window.confirm(q)) { return; }
                post(state, f, function (res) { if (res.ok) { setTimeout(function () { window.location.reload(); }, 1200); } });
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.modal && !state.modal.hidden) { close(state); }
        });
    }

    // Openers can live anywhere on the page (the inline "Change" next to the
    // username as well as the banner button).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-ucn-open]');
        if (!btn) { return; }
        var root = document.querySelector('[data-ucn-root="' + btn.getAttribute('data-ucn-open') + '"]');
        if (!root) { return; }
        e.preventDefault();
        open(init(root));
    });

    function warmFrom(e) {
        var btn = e.target.closest && e.target.closest('[data-ucn-open]');
        if (!btn) { return; }
        var root = document.querySelector('[data-ucn-root="' + btn.getAttribute('data-ucn-open') + '"]');
        if (root) { warm(init(root)); }
    }
    document.addEventListener('pointerover', warmFrom, { passive: true });
    document.addEventListener('focusin', warmFrom);

    // Deep link: /client/services/12#change-username opens the modal.
    if (window.location.hash === '#change-username') {
        var r = document.querySelector('[data-ucn-root]');
        if (r) { open(init(r)); }
    }
})();
