<?php
$code = '500';
$title = $title ?? 'Terjadi kesalahan pada server';
$message = $message ?? 'Permintaan Anda tidak dapat diproses saat ini. Peristiwa ini telah dicatat oleh sistem.';
$actionHref = $actionHref ?? url('/login');
$actionLabel = $actionLabel ?? 'Muat ulang halaman';
