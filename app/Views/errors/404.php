<?php
$code = '404';
$title = 'Halaman tidak ditemukan';
$message = $message ?? 'Alamat yang Anda buka tidak tersedia atau sudah dipindahkan.';
$loggedIn = \App\Core\Auth::check();
$actionHref = url(home_path());
$actionLabel = $loggedIn ? 'Kembali ke halaman awal' : 'Kembali ke halaman masuk';
