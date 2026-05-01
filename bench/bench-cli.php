<?php
// Self-contained CLI bench — same workloads as baseline.clj but runs
// directly under PHP CLI so opcache + JIT can engage.
//
// Usage:
//   php bench-cli.php                        # no optimisations
//   php -d opcache.enable_cli=1 bench-cli.php
//   php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=256M bench-cli.php
//
// Output: same shape as baseline-d803364.json so a diff is meaningful.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

function bench_loop(string $label, int $iters, callable $body): array {
    // Warm up.
    $body();
    $start = microtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $body();
    }
    $total_ns = (microtime(true) - $start) * 1e9;
    return [
        'label' => $label,
        'iters' => $iters,
        'total-ms' => $total_ns / 1e6,
        'ns-per-iter' => $total_ns / $iters,
    ];
}

function load_class(string $name): JavaClass {
    static $cache = [];
    if (!isset($cache[$name])) $cache[$name] = JavaClass::load($name);
    return $cache[$name];
}

// Pre-warm class load so per-iter cost is method-call only.
load_class('BenchEmpty');
load_class('BenchAdd');
load_class('BenchInvoke');
load_class('HelloWorld');

$results = [
    'php-version' => PHP_VERSION,
    'sapi' => php_sapi_name(),
    'opcache-loaded' => extension_loaded('Zend OPcache'),
    'opcache-enabled' => (bool) ini_get('opcache.enable_cli'),
    'opcache-jit' => ini_get('opcache.jit'),
    'opcache-jit-buffer' => ini_get('opcache.jit_buffer_size'),

    // Empty static method — measures per-method-call setup (M1-M4).
    // Class is pre-loaded; only the method-call path is in the loop.
    'empty-method' => bench_loop('empty-method', 100, function () {
        load_class('BenchEmpty')->getInvoker()->getStatic()->getMethods()->call('noop');
    }),

    // sum1k — 1000 iadd ops × 8 bytecodes ≈ 8000 ops per call.
    'iadd-1k' => bench_loop('iadd-1k', 50, function () {
        load_class('BenchAdd')->getInvoker()->getStatic()->getMethods()->call('sum1k');
    }),

    // 100 INVOKESTATIC inside one method — measures dispatch cost
    // without class re-resolve.
    'invoke-100' => bench_loop('invoke-100', 50, function () {
        load_class('BenchInvoke')->getInvoker()->getStatic()->getMethods()->call('callLoop');
    }),

    // HelloWorld — gross top-line, includes one-time setup.
    'helloworld' => bench_loop('helloworld', 10, function () {
        ob_start();
        load_class('HelloWorld')->getInvoker()->getStatic()->getMethods()->call('main', []);
        ob_get_clean();
    }),
];

if (in_array('--json', $argv ?? [], true)) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo str_pad('label', 22) . str_pad('cost', 16) . str_pad('per-unit', 16) . PHP_EOL;
    echo str_repeat('-', 54) . PHP_EOL;
    foreach ($results as $k => $v) {
        if (!is_array($v)) {
            echo str_pad($k, 22) . $v . PHP_EOL;
            continue;
        }
        $cost = sprintf('%.2f ms', $v['total-ms']);
        $per  = sprintf('%.0f ns/iter', $v['ns-per-iter']);
        echo str_pad($k, 22) . str_pad($cost, 16) . str_pad($per, 16) . PHP_EOL;
    }
}
