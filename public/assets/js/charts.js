(function () {
    'use strict';

    var Chart = window.Chart || null;

    function palette() {
        var styles = getComputedStyle(document.documentElement);
        var read = function (name, fallback) {
            var value = styles.getPropertyValue(name).trim();
            return value === '' ? fallback : value;
        };

        return {
            navy: read('--navy-700', '#123258'),
            navySoft: read('--navy-600', '#1b4472'),
            green: read('--green-600', '#177a55'),
            gold: read('--gold-500', '#c9a227'),
            ink: read('--ink-500', '#5f6e80'),
            line: read('--line', '#e2e7ed'),
            slices: [
                read('--navy-700', '#123258'),
                read('--green-600', '#177a55'),
                read('--gold-500', '#c9a227'),
                read('--navy-600', '#1b4472'),
                read('--green-700', '#12684a'),
                '#8fa3b8'
            ]
        };
    }

    function readData(canvas) {
        var labels;
        var values;
        var alt = null;

        try {
            labels = JSON.parse(canvas.getAttribute('data-chart-labels') || '[]');
            values = JSON.parse(canvas.getAttribute('data-chart-values') || '[]');
            alt = canvas.getAttribute('data-chart-alt-values')
                ? JSON.parse(canvas.getAttribute('data-chart-alt-values'))
                : null;
        } catch (error) {
            return null;
        }

        if (!Array.isArray(labels) || !Array.isArray(values) || labels.length === 0) {
            return null;
        }

        return {
            type: canvas.getAttribute('data-chart-type') || 'bar',
            labels: labels,
            values: values.map(Number),
            unit: canvas.getAttribute('data-chart-unit') || '',
            max: Number(canvas.getAttribute('data-chart-max') || '0'),
            horizontal: canvas.getAttribute('data-chart-horizontal') === '1',
            label: canvas.getAttribute('data-chart-label') || 'Jumlah',
            altLabel: canvas.getAttribute('data-chart-alt-label') || 'Pembanding',
            altValues: Array.isArray(alt) ? alt.map(Number) : null
        };
    }

    function shortRupiah(value) {
        var abs = Math.abs(value);
        if (abs >= 1000000000) {
            return (value / 1000000000).toFixed(1).replace('.', ',') + ' M';
        }
        if (abs >= 1000000) {
            return (value / 1000000).toFixed(1).replace('.', ',') + ' jt';
        }
        if (abs >= 1000) {
            return Math.round(value / 1000) + ' rb';
        }
        return String(value);
    }

    function buildOptions(spec, colors) {
        var isMoney = spec.unit === 'rupiah';
        var multi = spec.altValues !== null;
        var tickFormat = function (value) {
            return isMoney ? shortRupiah(value) : String(value);
        };

        var scaleKey = spec.type === 'bar' && spec.horizontal ? 'x' : 'y';
        var scales = {
            x: {
                grid: { color: spec.type === 'bar' && !spec.horizontal ? colors.line : 'transparent' },
                ticks: { color: colors.ink, font: { size: 11 } }
            },
            y: {
                grid: { color: colors.line },
                ticks: { color: colors.ink, font: { size: 11 } }
            }
        };

        scales[scaleKey].ticks.callback = tickFormat;
        if (spec.max > 0) {
            scales[scaleKey].max = spec.max;
            scales[scaleKey].min = 0;
        }
        if (spec.type === 'doughnut') {
            scales = { x: { display: false }, y: { display: false } };
        }

        return {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: spec.type === 'bar' && spec.horizontal ? 'y' : 'x',
            plugins: {
                legend: {
                    display: spec.type === 'doughnut' || multi,
                    position: 'bottom',
                    labels: { color: colors.ink, boxWidth: 12, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var raw = context.parsed;
                            var value = typeof raw === 'object' && raw !== null
                                ? (spec.horizontal ? raw.x : raw.y)
                                : raw;
                            var text = isMoney ? 'Rp' + Number(value).toLocaleString('id-ID') : String(value);
                            if (!isMoney && spec.unit !== '%') {
                                text += spec.unit;
                            } else if (!isMoney) {
                                text += '%';
                            }
                            var head = multi
                                ? (context.dataset.label || '') + ': '
                                : (context.label ? context.label + ': ' : '');
                            return head + text;
                        }
                    }
                }
            },
            scales: scales
        };
    }

    function buildConfig(spec, colors) {
        var base = {
            labels: spec.labels,
            datasets: [{
                label: spec.type === 'doughnut' ? 'Proyek' : spec.label,
                data: spec.values
            }]
        };

        if (spec.type === 'line') {
            base.datasets[0].borderColor = colors.green;
            base.datasets[0].backgroundColor = 'rgba(23, 122, 85, 0.12)';
            base.datasets[0].fill = true;
            base.datasets[0].tension = 0.25;
            base.datasets[0].pointRadius = 3;
            base.datasets[0].pointBackgroundColor = colors.green;

            if (spec.altValues !== null) {
                base.datasets.push({
                    label: spec.altLabel,
                    data: spec.altValues,
                    borderColor: colors.gold,
                    backgroundColor: 'transparent',
                    borderDash: [5, 4],
                    fill: false,
                    tension: 0.25,
                    pointRadius: 3,
                    pointBackgroundColor: colors.gold
                });
            }

            return { type: 'line', data: base, options: buildOptions(spec, colors) };
        }

        if (spec.type === 'doughnut') {
            base.datasets[0].backgroundColor = colors.slices;
            base.datasets[0].borderColor = '#ffffff';
            base.datasets[0].borderWidth = 2;
            return { type: 'doughnut', data: base, options: buildOptions(spec, colors) };
        }

        base.datasets[0].backgroundColor = colors.navy;
        base.datasets[0].hoverBackgroundColor = colors.navySoft;
        base.datasets[0].borderRadius = 3;
        base.datasets[0].maxBarThickness = 28;

        if (spec.altValues !== null) {
            base.datasets.push({
                label: spec.altLabel,
                data: spec.altValues,
                backgroundColor: colors.green,
                hoverBackgroundColor: colors.navySoft,
                borderRadius: 3,
                maxBarThickness: 28
            });
        }

        return { type: 'bar', data: base, options: buildOptions(spec, colors) };
    }

    function mount(root) {
        if (!Chart) {
            return;
        }

        var colors = palette();
        (root || document).querySelectorAll('canvas[data-chart-labels]').forEach(function (canvas) {
            var spec = readData(canvas);
            if (spec === null) {
                return;
            }
            /* eslint-disable-next-line no-new */
            new Chart(canvas, buildConfig(spec, colors));
        });
    }

    window.SPPGCharts = { mount: mount };
})();
