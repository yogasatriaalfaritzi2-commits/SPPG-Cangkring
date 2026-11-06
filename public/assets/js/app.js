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

    // ---------- Global Quick Search / Command Palette (Ctrl+K) ----------
    var paletteModalEl = document.getElementById('commandPaletteModal');
    var paletteInput = document.getElementById('commandPaletteInput');
    var paletteResults = document.getElementById('commandPaletteResults');
    var paletteModal = paletteModalEl && bootstrapApi ? new bootstrapApi.Modal(paletteModalEl) : null;
    var searchItems = window.__APP_SEARCH_ITEMS || [];

    function renderPaletteResults(query) {
        if (!paletteResults) return;
        query = (query || '').toLowerCase().trim();

        var filtered = searchItems.filter(function (item) {
            if (!query) return true;
            return item.title.toLowerCase().indexOf(query) !== -1 ||
                   item.category.toLowerCase().indexOf(query) !== -1;
        });

        if (filtered.length === 0) {
            paletteResults.innerHTML = '<div class="p-4 text-center text-muted"><i class="bi bi-search fs-3 d-block mb-1"></i>Tidak ada hasil yang cocok dengan "' + query.replace(/</g, '&lt;') + '".</div>';
            return;
        }

        var html = '<div class="list-group list-group-flush">';
        filtered.forEach(function (item, index) {
            var activeClass = index === 0 ? ' active' : '';
            html += '<a href="' + item.url + '" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2 rounded mb-1 border-0 palette-item' + activeClass + '">' +
                        '<div class="d-flex align-items-center gap-2">' +
                            '<span class="badge bg-light text-primary border p-2"><i class="bi ' + (item.icon || 'bi-link') + ' fs-6"></i></span>' +
                            '<div>' +
                                '<div class="fw-semibold text-dark text-decoration-none">' + item.title + '</div>' +
                                '<small class="text-muted">' + item.category + '</small>' +
                            '</div>' +
                        '</div>' +
                        '<i class="bi bi-chevron-right text-muted small"></i>' +
                    '</a>';
        });
        html += '</div>';
        paletteResults.innerHTML = html;
    }

    function openPalette() {
        if (!paletteModal) return;
        paletteModal.show();
        setTimeout(function () {
            if (paletteInput) {
                paletteInput.value = '';
                paletteInput.focus();
                renderPaletteResults('');
            }
        }, 150);
    }

    document.querySelectorAll('[data-command-palette-trigger]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openPalette();
        });
    });

    if (paletteInput) {
        paletteInput.addEventListener('input', function () {
            renderPaletteResults(paletteInput.value);
        });

        paletteInput.addEventListener('keydown', function (e) {
            if (!paletteResults) return;
            var items = paletteResults.querySelectorAll('.palette-item');
            if (items.length === 0) return;

            var currentIndex = -1;
            items.forEach(function (el, idx) {
                if (el.classList.contains('active')) currentIndex = idx;
            });

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var nextIndex = (currentIndex + 1) % items.length;
                items.forEach(function (el) { el.classList.remove('active'); });
                items[nextIndex].classList.add('active');
                items[nextIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prevIndex = (currentIndex - 1 + items.length) % items.length;
                items.forEach(function (el) { el.classList.remove('active'); });
                items[prevIndex].classList.add('active');
                items[prevIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentIndex >= 0 && items[currentIndex]) {
                    window.location.href = items[currentIndex].getAttribute('href');
                }
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            openPalette();
        }
    });
})();
