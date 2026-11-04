<?php
$code = '403';
$title = 'Akses ditolak';
$message = $message ?? 'Anda tidak memiliki hak akses untuk membuka halaman ini.';
$loggedIn = \App\Core\Auth::check();
$actionHref = url(home_path());
$actionLabel = $loggedIn ? 'Kembali ke halaman awal' : 'Kembali ke halaman masuk';
