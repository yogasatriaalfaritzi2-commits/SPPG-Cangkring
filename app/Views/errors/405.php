<?php
$code = '405';
$title = 'Metode tidak diizinkan';
$message = $message ?? 'Metode permintaan tidak sesuai untuk halaman ini.';
$loggedIn = \App\Core\Auth::check();
$actionHref = url(home_path());
$actionLabel = $loggedIn ? 'Kembali ke halaman awal' : 'Kembali ke halaman masuk';
