// Cloudflare client page: show the MX priority / proxy fields only for record
// types that use them, and confirm destructive buttons. Works without JS too.
(function () {
    'use strict';

    var proxiable = ['A', 'AAAA', 'CNAME'];

    document.querySelectorAll('[data-cf-dns-form]').forEach(function (form) {
        var type = form.querySelector('[data-cf-type]');
        var priority = form.querySelector('[data-cf-priority]');
        var proxy = form.querySelector('[data-cf-proxy]');

        if (!type || type.tagName !== 'SELECT') {
            return;
        }

        function sync() {
            if (priority) {
                priority.hidden = type.value !== 'MX';
            }

            if (proxy) {
                proxy.hidden = proxiable.indexOf(type.value) === -1;
            }
        }

        type.addEventListener('change', sync);
        sync();
    });

    document.querySelectorAll('form[data-cf-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-cf-confirm'))) {
                event.preventDefault();
            }
        });
    });
})();
