<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

$files = glob(dirname(__DIR__) . '/app/Controllers/*.php') ?: [];
$problems = [];

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    preg_match_all('/^use\s+([^;]+);/m', $source, $useMatches);

    $aliases = [];

    foreach ($useMatches[1] as $import) {
        $parts = explode('\\', trim($import));
        $aliases[strtolower((string) end($parts))] = trim($import);
    }

    preg_match_all('/\b([A-Z][A-Za-z0-9]*)::([a-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls, PREG_SET_ORDER);

    foreach ($calls as $call) {
        $alias = $call[1];
        $method = $call[2];

        if (in_array($alias, ['self', 'static', 'parent'], true) || !isset($aliases[strtolower($alias)])) {
            continue;
        }

        $class = $aliases[strtolower($alias)];

        if (!class_exists($class)) {
            $problems[] = basename($file) . ": kelas tidak ada {$class}";
            continue;
        }

        if (!method_exists($class, $method)) {
            $problems[] = basename($file) . ": metode tidak ada {$class}::{$method}()";
            continue;
        }

        $mirror = new ReflectionMethod($class, $method);

        if ($mirror->isPrivate()) {
            $problems[] = basename($file) . ": metode privat {$class}::{$method}()";
        }
    }
}

$unique = array_values(array_unique($problems));

foreach ($unique as $problem) {
    echo 'GAGAL: ' . $problem . "\n";
}

printf("Pemeriksaan pemanggilan statis controller: %d masalah\n", count($unique));
exit($unique === [] ? 0 : 1);
