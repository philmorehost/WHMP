/* Manual username change tool: the same instant local rules + debounced,
   cancellable, cached server check as the client modal. */
(function () {
    'use strict';
    var form = document.getElementById('uca-manual');
    if (!form) { return; }
    var input = document.getElementById('uca-new');
    var out = document.getElementById('uca-verdict');
    var sid = form.getAttribute('data-service');
    var min = parseInt(form.getAttribute('data-min'), 10) || 1;
    var max = parseInt(form.getAttribute('data-max'), 10) || 16;
    var cache = new Map();
    var timer = null;
    var ctl = null;

    function say(kind, text) { out.className = 'uca-verdict' + (kind ? ' uca-verdict--' + kind : ''); out.textContent = text; }

    function local(n) {
        if (n === '') { return ''; }
        if (/^[0-9]/.test(n)) { return 'Must start with a letter.'; }
        if (!/^[a-z][a-z0-9]*$/.test(n)) { return 'Lowercase letters and digits only.'; }
        if (n.length < min || n.length > max) { return min + '–' + max + ' characters.'; }
        if (n.indexOf('test') === 0) { return 'Cannot start with "test".'; }
        return null;
    }

    input.addEventListener('input', function () {
        var n = input.value.toLowerCase().replace(/\s+/g, '');
        if (n !== input.value) { input.value = n; }
        clearTimeout(timer);
        if (ctl) { ctl.abort(); }
        var bad = local(n);
        if (bad !== null) { say(bad ? 'bad' : '', bad || ''); return; }
        if (cache.has(n)) { var c = cache.get(n); say(c.ok ? 'ok' : 'bad', c.ok ? '✓ Available on the server' : c.message); return; }
        timer = setTimeout(function () {
            ctl = typeof AbortController === 'function' ? new AbortController() : null;
            var slow = setTimeout(function () { if (input.value === n) { say('', 'Checking with the server…'); } }, 160);
            fetch('/admin/username-changer/check?service_id=' + sid + '&u=' + encodeURIComponent(n), { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    clearTimeout(slow);
                    // "Couldn't reach the server" is momentary: show it, never cache it.
                    if (res.code !== 'unverified') { cache.set(n, res); }
                    if (input.value === n) { say(res.ok ? 'ok' : 'bad', res.ok ? '✓ Available on the server' : res.message); }
                })
                .catch(function () { clearTimeout(slow); });
        }, 120);
    });
    if (input.value) { input.dispatchEvent(new Event('input')); }
})();
/* Per-button confirmation (app.js only handles form-level data-confirm). */
document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-confirm]') : null;
    if (b && b.form && !b.form.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
});
/* Show/hide a section with a checkbox (fee settings). */
(function () {
    document.querySelectorAll('[data-uca-toggle]').forEach(function (box) {
        var target = document.querySelector(box.getAttribute('data-uca-toggle'));
        if (!target) { return; }
        var sync = function () { target.style.display = box.checked ? '' : 'none'; };
        box.addEventListener('change', sync);
        sync();
    });
})();
/* Reseller price → live margin. */
(function () {
    var price = document.querySelector('[data-ucn-cost]');
    var out = document.getElementById('ucn-margin');
    if (!price || !out) { return; }
    var cost = parseFloat(price.getAttribute('data-ucn-cost')) || 0;
    var unit = out.textContent.replace(/^[\d.,\s-]+/, '');
    price.addEventListener('input', function () {
        var p = price.value === '' ? cost : parseFloat(price.value);
        var m = isNaN(p) ? 0 : p - cost;
        out.textContent = (m < 0 ? 'Below cost' : m.toFixed(2) + ' ' + unit);
        out.style.color = m < 0 ? '#b91c1c' : '';
    });
})();
