(function () {
    'use strict';

    var shell = document.querySelector('[data-calendar]');
    if (shell === null) {
        return;
    }

    var host = shell.querySelector('.calendar-host');
    if (host === null) {
        return;
    }

    var events = [];
    try {
        events = JSON.parse(shell.getAttribute('data-events') || '[]');
    } catch (error) {
        events = [];
    }

    if (typeof window.FullCalendar === 'undefined') {
        host.textContent = 'Kalender tidak dapat dimuat. Gunakan daftar kegiatan untuk melihat jadwal.';
        return;
    }

    var detail = document.querySelector('[data-detail]');
    var from = shell.getAttribute('data-from') || '';
    var to = shell.getAttribute('data-to') || '';

    // Bundel FullCalendar yang dipakai hanya membawa locale Inggris, jadi teks antarmuka
    // bahasa Indonesia didefinisikan di sini. Nama bulan dan hari diambil peramban lewat Intl.
    var locale = {
        code: 'id',
        week: { dow: 1, doy: 7 },
        buttonText: {
            today: 'Hari ini',
            month: 'Bulan',
            week: 'Minggu',
            day: 'Hari',
            prev: '‹',
            next: '›'
        },
        weekText: 'Mg',
        allDayText: 'Seharian',
        moreLinkText: function (n) {
            return '+' + n + ' lagi';
        },
        noEventsText: 'Tidak ada kegiatan pada tampilan ini.'
    };

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }

    function field(label, value) {
        var row = element('div', 'calendar-field');
        row.appendChild(element('dt', null, label));

        if (value instanceof Node) {
            row.appendChild(value);
        } else {
            row.appendChild(element('dd', null, value === '' || value === null ? '—' : value));
        }

        return row;
    }

    function renderDetail(props) {
        if (detail === null) {
            return;
        }

        detail.textContent = '';
        detail.appendChild(element('p', 'calendar-detail-title', props.kode + ' — ' + props.nama));

        var list = element('dl', 'calendar-detail-list');

        var statusValue = element('dd');
        statusValue.appendChild(element('span', 'pill pill-' + props.statusTone, props.statusLabel));
        if (props.terlambat) {
            statusValue.appendChild(element('span', 'calendar-late', 'Lewat tanggal selesai'));
        }
        list.appendChild(field('Status', statusValue));

        var projectValue = element('dd');
        var projectLink = element('a', null, props.proyek);
        projectLink.href = props.proyekUrl;
        projectValue.appendChild(projectLink);
        projectValue.appendChild(element('span', 'cell-sub', ' (' + props.proyekKode + ')'));
        list.appendChild(field('Proyek', projectValue));

        list.appendChild(field('Rentang', props.mulai + ' – ' + props.selesai));
        list.appendChild(field('Durasi', props.durasi + ' hari'));
        list.appendChild(field('Penanggung jawab', props.pj));
        list.appendChild(field('Kategori pekerjaan', props.kategori));
        list.appendChild(field('Prioritas', props.prioritas));
        list.appendChild(field('Progres', props.progres + '%'));

        var description = element('div', 'calendar-field calendar-field-block');
        description.appendChild(element('dt', null, 'Uraian'));
        description.appendChild(element('dd', 'text-start', props.uraian === '' ? '(kosong)' : props.uraian));
        list.appendChild(description);

        detail.appendChild(list);

        var link = element('a', 'btn btn-sm btn-primary');
        link.href = props.detailUrl;
        link.textContent = 'Rincian dan riwayat kegiatan';
        detail.appendChild(link);
    }

    var calendar = new window.FullCalendar.Calendar(host, {
        locale: locale,
        initialView: 'dayGridMonth',
        height: 'auto',
        fixedWeekCount: false,
        dayMaxEventRows: 3,
        eventOrder: 'start',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        // Panah ditulis sebagai teks, bukan lewat font ikon bawaan FullCalendar. Font itu
        // disertakan sebagai data: URI dan kebijakan CSP font-src 'self' memblokirnya,
        // sehingga muncul galat konsol pada setiap halaman kalender.
        buttonIcons: false,
        validRange: from !== '' && to !== '' ? { start: from, end: to } : undefined,
        events: events,
        datesSet: labelNavButtons,
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            renderDetail(info.event.extendedProps);
        }
    });

    // Tombol mundur/ maju hanya menampilkan panah, jadi nama tombolnya dipasang
    // supaya pembaca layar masih punya sesuatu untuk dibacakan.
    function labelNavButtons() {
        var pairs = [['.fc-prev-button', 'Ke periode sebelumnya'], ['.fc-next-button', 'Ke periode berikutnya']];

        pairs.forEach(function (pair) {
            var button = host.querySelector(pair[0]);
            if (button !== null) {
                button.setAttribute('aria-label', pair[1]);
                button.title = pair[1];
            }
        });
    }

    calendar.render();
})();
