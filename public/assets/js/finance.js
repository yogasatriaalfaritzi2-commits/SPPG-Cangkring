(function () {
    'use strict';

    var DASH = '—';

    /*
     Aturan parsing di bawah ini menyalin amount_normalize() dan volume_normalize()
     pada app/Helpers/functions.php. Nilai resmi tetap dihitung server saat formulir
     dikirim; angka di halaman ini hanya perkiraan yang tampak seketika.
    */
    function strip(raw) {
        return String(raw === null || raw === undefined ? '' : raw).replace(/[\u00A0\s]/g, '');
    }

    function parseAmount(raw) {
        var value = strip(raw);
        var negative = value.charAt(0) === '-';
        var digits = negative ? value.slice(1) : value;
        var isGrouped = /^\d{1,3}(?:\.\d{3})+(?:[.,]\d{1,2})?$/.test(digits);
        var isPlain = /^\d+(?:[.,]\d{1,2})?$/.test(digits);
        var fraction = '';

        if (digits === '' || (!isGrouped && !isPlain)) {
            return null;
        }

        var decimal = /([.,])(\d{1,2})$/.exec(digits);
        if (decimal !== null) {
            fraction = '.' + decimal[2];
            digits = digits.slice(0, -decimal[0].length);
        }

        digits = digits.replace(/\./g, '');
        if (digits === '') {
            digits = '0';
        }

        var result = Number((negative ? '-' : '') + digits + fraction);

        return Number.isFinite(result) ? result : null;
    }

    function parseVolume(raw) {
        var value = strip(raw).replace(/,/g, '.');

        if (value === '' || /^\d{1,9}(?:\.\d{1,3})?$/.test(value) === false) {
            return null;
        }

        return Number(value);
    }

    function rupiah(value) {
        var sign = value < 0 ? '-' : '';

        return 'Rp' + sign + String(Math.round(Math.abs(value))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function write(output, text) {
        if (output !== null) {
            output.textContent = text;
        }
    }

    function refreshTotal(form) {
        var volumeField = form.querySelector('[data-live-volume]');
        var priceField = form.querySelector('[data-live-price]');

        if (volumeField === null || priceField === null) {
            return;
        }

        var volume = parseVolume(volumeField.value);
        var price = parseAmount(priceField.value);

        write(
            form.querySelector('output'),
            volume === null || price === null ? DASH : rupiah(volume * price)
        );
    }

    function refreshAmount(form) {
        var field = form.querySelector('[data-live-amount-input]');

        if (field === null) {
            return;
        }

        var amount = parseAmount(field.value);

        write(form.querySelector('output'), amount === null ? DASH : rupiah(amount));
    }

    function wire(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('[data-live-total]'), function (form) {
            if (form.dataset.liveBound === '1') {
                return;
            }

            form.dataset.liveBound = '1';
            ['input', 'change'].forEach(function (event) {
                form.addEventListener(event, function () {
                    refreshTotal(form);
                });
            });
        });

        Array.prototype.forEach.call(scope.querySelectorAll('[data-live-amount]'), function (form) {
            if (form.dataset.liveBound === '1') {
                return;
            }

            form.dataset.liveBound = '1';
            ['input', 'change'].forEach(function (event) {
                form.addEventListener(event, function () {
                    refreshAmount(form);
                });
            });
        });
    }

    if (window.SPPGCharts) {
        window.SPPGCharts.mount(document);
    }

    wire(document);
})();
