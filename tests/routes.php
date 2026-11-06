<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Router;

$router = new Router();
$loader = require dirname(__DIR__) . '/routes/web.php';
$loader($router);

$reflection = new ReflectionClass(Router::class);
$property = $reflection->getProperty('routes');
$property->setAccessible(true);
/** @var array<int, array{method: string, regex: string, action: callable|array{0: class-string, 1: string}, middleware: array<int, string>}> $routes */
$routes = $property->getValue($router);

$errors = [];
$seen = [];

foreach ($routes as $index => $route) {
    $action = $route['action'];

    if (is_array($action)) {
        [$class, $method] = $action;

        if (!class_exists($class)) {
            $errors[] = "#{$index} kelas controller tidak ada: {$class}";
            continue;
        }

        if (!method_exists($class, $method)) {
            $errors[] = "#{$index} metode tidak ada: {$class}::{$method}";
            continue;
        }

        $mirror = new ReflectionMethod($class, $method);

        if (!$mirror->isPublic()) {
            $errors[] = "#{$index} metode tidak publik: {$class}::{$method}";
        }

        $parameters = 0;

        foreach ($mirror->getParameters() as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                $parameters++;
            }
        }

        $groups = preg_match_all('#\(\?P<#', $route['regex'], $matches);
        $expected = 1 + (int) $groups;

        if ($parameters !== $expected) {
            $errors[] = "#{$index} arity {$parameters} tidak cocok rute {$route['regex']} (butuh {$expected})";
        }

        foreach ($route['middleware'] as $definition) {
            if (!is_string($definition) || $definition === '') {
                $errors[] = "#{$index} middleware kosong";
                continue;
            }

            $guard = explode(':', $definition, 2)[0];

            if (!class_exists($guard)) {
                $errors[] = "#{$index} middleware tidak ada: {$guard}";
            }
        }
    }

    $key = $route['method'] . ' ' . $route['regex'];
    $seen[$key] = ($seen[$key] ?? 0) + 1;
}

foreach ($seen as $key => $count) {
    if ($count > 1) {
        $errors[] = "Rute terdaftar ganda: {$key}";
    }
}

// Pemeriksaan bayangan: rute tetap (mis. /rab/tambah) tidak boleh tertelan oleh
// rute pola (/rab/{id}) yang didaftarkan lebih dahulu.
foreach ($routes as $later => $route) {
    if (str_contains($route['regex'], '(?P<')) {
        continue;
    }

    $path = substr($route['regex'], 2, -2);
    $path = str_replace('\\#', '#', $path);

    foreach ($routes as $earlier => $candidate) {
        if ($earlier >= $later || $candidate['method'] !== $route['method']) {
            continue;
        }

        if (!str_contains($candidate['regex'], '(?P<') || preg_match($candidate['regex'], $path) !== 1) {
            continue;
        }

        $errors[] = "Rute #{$later} {$route['method']} {$path} tertutup rute #{$earlier} {$candidate['regex']}";
    }
}

// Slug yang menutup rute harus benar-benar ada pada tabel permissions. Slug fiktif
// membuat rute selamanya ditolak, termasuk bagi Super Admin, tanpa ada yang sadar.
$guardPrefix = \App\Middleware\PermissionMiddleware::class . ':';
$declared = [];

foreach ($routes as $route) {
    foreach ($route['middleware'] as $definition) {
        if (!is_string($definition) || !str_starts_with($definition, $guardPrefix)) {
            continue;
        }

        foreach (explode(',', substr($definition, strlen($guardPrefix))) as $slug) {
            $slug = trim($slug);
            if ($slug !== '') {
                $declared[$slug] = true;
            }
        }
    }
}

$stored = [];
foreach (\App\Core\Database::query('SELECT permission_slug FROM permissions')->fetchAll() as $row) {
    $stored[(string) $row['permission_slug']] = true;
}

foreach (array_keys($declared) as $slug) {
    if (!isset($stored[$slug])) {
        $errors[] = "Rute memakai slug izin yang tidak ada di database: {$slug}";
    }
}

printf("Rute terdaftar: %d\n", count($routes));
printf("Slug izin terpakai: %d dari %d yang terdaftar di database\n", count($declared), count($stored));

if ($errors === []) {
    echo "Semua rute menunjuk metode yang ada dan tidak saling menutupi.\n";
    exit(0);
}

foreach ($errors as $error) {
    echo 'GAGAL: ' . $error . "\n";
}

exit(1);
