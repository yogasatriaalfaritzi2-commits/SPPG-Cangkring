<?php
$code = '400';
$title = 'Permintaan tidak valid';
$message = $message ?? 'Permintaan Anda tidak dapat diproses. Muat ulang halaman lalu coba lagi.';
$loggedIn = \App\Core\Auth::check();
$actionHref = url(home_path());
$actionLabel = $loggedIn ? 'Kembali ke halaman awal' : 'Kembali ke halaman masuk';
