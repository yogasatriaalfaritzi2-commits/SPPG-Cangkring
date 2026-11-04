(function () {
    'use strict';

    var body = document.body;
    var sidebarToggles = document.querySelectorAll('[data-sidebar-toggle]');
    var sidebarClosers = document.querySelectorAll('[data-sidebar-close]');
    var bootstrapApi = window.bootstrap || null;

    function setSidebar(open) {
        body.classList.toggle('sidebar-open', open);
        sidebarToggles.forEach(function (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    sidebarToggles.forEach(function (button) {
        button.addEventListener('click', function () {
            setSidebar(!body.classList.contains('sidebar-open'));
        });
    });

    sidebarClosers.forEach(function (element) {
        element.addEventListener('click', function () {
            setSidebar(false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setSidebar(false);
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            setSidebar(false);
        }
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var inputId = button.getAttribute('data-password-toggle');
        var input = inputId ? document.getElementById(inputId) : null;
        if (!input) {
            return;
        }

        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');

            var icon = button.querySelector('i');
            if (icon) {
                icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        });
    });

    // ---------- Notifikasi toast ----------
    if (bootstrapApi) {
        document.querySelectorAll('.app-toast').forEach(function (element) {
            var autoHide = element.getAttribute('data-toast-autohide') === '1';
            var toast = new bootstrapApi.Toast(element, { autohide: autoHide, delay: 6000 });
            toast.show();
        });
    }

    // ---------- Modal konfirmasi ----------
    var modalElement = document.getElementById('confirmModal');
    var modal = modalElement && bootstrapApi ? new bootstrapApi.Modal(modalElement) : null;
    var titleElement = modalElement ? modalElement.querySelector('#confirmModalTitle') : null;
    var textElement = modalElement ? modalElement.querySelector('#confirmModalText') : null;
    var acceptButton = modalElement ? modalElement.querySelector('#confirmModalAccept') : null;
    var pendingForm = null;

    function markLoading(form, submitter) {
        var button = submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        if (!button || !button.classList) {
            return;
        }
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
    }

    if (modal && acceptButton) {
        acceptButton.addEventListener('click', function () {
            var form = pendingForm;
            pendingForm = null;
            if (!form) {
                return;
            }
            markLoading(form, null);
            modal.hide();
            form.submit();
        });

        modalElement.addEventListener('hidden.bs.modal', function () {
            pendingForm = null;
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (!form.hasAttribute('data-confirm')) {
            markLoading(form, event.submitter);
            return;
        }

        event.preventDefault();

        var message = form.getAttribute('data-confirm-text') || 'Lanjutkan dengan tindakan ini?';
        var heading = form.getAttribute('data-confirm-title') || 'Konfirmasi';
        var label = form.getAttribute('data-confirm-label') || 'Ya, lanjutkan';
        var tone = form.getAttribute('data-confirm-tone') === 'danger' ? 'btn-danger' : 'btn-warning';

        if (!modal) {
            if (window.confirm(heading + '\n\n' + message)) {
                markLoading(form, event.submitter);
                form.submit();
            }
            return;
        }

        if (titleElement) {
            titleElement.textContent = heading;
        }
        if (textElement) {
            textElement.textContent = message;
        }
        acceptButton.classList.remove('btn-danger', 'btn-warning');
        acceptButton.classList.add(tone);
        acceptButton.textContent = label;

        pendingForm = form;
        modal.show();
    });
})();
