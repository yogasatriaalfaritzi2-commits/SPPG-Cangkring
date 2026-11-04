(function () {
    'use strict';

    if (window.SPPGCharts) {
        window.SPPGCharts.mount(document);
    }

    var hash = window.location.hash.replace('#', '');
    if (hash !== '' && window.bootstrap) {
        var link = document.getElementById(hash);
        if (link && link.classList.contains('nav-link')) {
            new window.bootstrap.Tab(link).show();
        }
    }

    document.querySelectorAll('input[data-progress-input]').forEach(function (input) {
        var output = document.getElementById(input.getAttribute('data-progress-input'));
        if (!output) {
            return;
        }

        input.addEventListener('input', function () {
            var value = Number(input.value);
            if (isNaN(value) || value < 0) {
                value = 0;
            }
            if (value > 100) {
                value = 100;
            }
            output.textContent = String(value) + '%';
        });
    });
})();
