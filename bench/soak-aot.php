<?php
/**
 * Long-running AOT pipeline soak test. Walks a pool of class files
 * through Compiler::compileBytes + Loader::callStatic continuously,
 * emitting periodic metrics. Designed to run for 24h+ to validate:
 *
 *   1. Memory bound — Compiler caches (LRU at 1000 entries each per #6)
 *      shouldn't leak. resident memory should plateau.
 *   2. Cache hit rate — over a long run with class repetition, the
 *      hit rate should stabilise.
 *   3. Latency tail — p50 / p99 / p99.9 over rolling windows.
 *   4. Error rate — should be 0 for the curated pool; non-zero is a
 *      regression.
 *
 * Run:
 *   php bench/soak-aot.php                    # 24h default
 *   PHPJAVA_SOAK_HOURS=1 php bench/soak-aot.php   # 1h
 *   PHPJAVA_SOAK_HOURS=0 php bench/soak-aot.php   # unlimited (Ctrl-C)
 *
 * Output: one JSON line per metrics window (default every 60s) on stdout.
 * Suggested invocation:
 *   nohup php bench/soak-aot.php > /tmp/soak-$(date +%s).jsonl 2>&1 &
 *
 * Pool of classes:
 *   - bench/fixtures/*.class (9 from this repo)
 *   - /tmp/probe-aot-extracted/**\/*.class (commons-lang3 if probe ran)
 *
 * Strategy alternated per-iteration:
 *   - 80% compileBytes (warm cache likely)
 *   - 15% Loader::callStatic (compile + execute roundtrip)
 *   - 5% Loader::reset + cold-compile (validates cache eviction)
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Aot\Loader;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

// ── pool ────────────────────────────────────────────────────────────────

/** @return array<int, array{path: string, classPath: string}> */
function buildPool(): array
{
    $pool = [];
    foreach (glob(__DIR__ . '/fixtures/*.class') as $f) {
        $pool[] = [
            'path' => $f,
            'classPath' => str_replace(['$'], ['$'], basename($f, '.class')),
        ];
    }
    $extractRoot = '/tmp/probe-aot-extracted';
    if (is_dir($extractRoot)) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractRoot));
        foreach ($rii as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.class')) {
                $rel = substr($f->getPathname(), strlen($extractRoot) + 1);
                if (str_starts_with($rel, 'META-INF/')) continue;
                $pool[] = [
                    'path' => $f->getPathname(),
                    'classPath' => str_replace('/', '.', preg_replace('/\.class$/', '', $rel)),
                ];
            }
        }
    }
    return $pool;
}

// ── metrics window ──────────────────────────────────────────────────────

class Window
{
    public int $start;
    public int $iterations = 0;
    public int $errors = 0;
    public int $cacheHits = 0;
    public int $coldCompiles = 0;
    public int $callStaticOk = 0;
    /** @var float[] */
    public array $compileMs = [];
    /** @var float[] */
    public array $callMs = [];

    public function __construct() { $this->start = (int) (microtime(true) * 1000); }
}

function pct(array $sorted, float $p): float
{
    if (empty($sorted)) return 0.0;
    $idx = (int) floor(($p / 100) * (count($sorted) - 1));
    return $sorted[$idx];
}

function emitMetrics(Window $w): void
{
    sort($w->compileMs);
    sort($w->callMs);
    $line = [
        'ts'           => date('c'),
        'window_ms'    => (int) (microtime(true) * 1000) - $w->start,
        'iterations'   => $w->iterations,
        'errors'       => $w->errors,
        'cache_hits'   => $w->cacheHits,
        'cold_compiles'=> $w->coldCompiles,
        'call_static_ok' => $w->callStaticOk,
        'rss_mb'       => round(memory_get_usage(true) / 1024 / 1024, 2),
        'rss_peak_mb'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'compile_p50_ms' => round(pct($w->compileMs, 50), 3),
        'compile_p99_ms' => round(pct($w->compileMs, 99), 3),
        'compile_p999_ms'=> round(pct($w->compileMs, 99.9), 3),
        'call_p50_ms'  => round(pct($w->callMs, 50), 3),
        'call_p99_ms'  => round(pct($w->callMs, 99), 3),
    ];
    echo json_encode($line) . "\n";
    flush();
}

// ── soak loop ───────────────────────────────────────────────────────────

$pool = buildPool();
if (empty($pool)) {
    fwrite(STDERR, "Empty class pool — extract commons-lang3 first or check bench/fixtures.\n");
    exit(1);
}
fwrite(STDERR, sprintf("Pool: %d classes\n", count($pool)));

$durationHours = (float) (getenv('PHPJAVA_SOAK_HOURS') !== false
    ? (float) getenv('PHPJAVA_SOAK_HOURS')
    : 24.0);
$endAt = $durationHours > 0 ? microtime(true) + $durationHours * 3600 : PHP_FLOAT_MAX;
$windowSeconds = (int) (getenv('PHPJAVA_SOAK_WINDOW') ?: 60);

// Graceful shutdown — emit a final metrics line before exit.
$shutdown = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$shutdown) { $shutdown = true; });
    pcntl_signal(SIGTERM, function () use (&$shutdown) { $shutdown = true; });
}

// Pre-warm cache with a known-good entry-point class so the
// callStatic strategy has something to dispatch.
Loader::loadClass('BenchAdd');

$window = new Window();
$nextEmit = microtime(true) + $windowSeconds;

while (microtime(true) < $endAt && !$shutdown) {
    $entry = $pool[array_rand($pool)];
    $strategy = mt_rand(0, 99);
    $t0 = microtime(true);

    try {
        if ($strategy < 80) {
            // 80% — warm-cache compileBytes (most realistic production shape)
            (new Compiler())->compileBytes($entry['classPath'], file_get_contents($entry['path']));
            $window->cacheHits++;
        } elseif ($strategy < 95) {
            // 15% — Loader::callStatic on BenchAdd (compile-then-execute roundtrip)
            $r = Loader::callStatic('BenchAdd', 'sum1k');
            $callElapsed = (microtime(true) - $t0) * 1000;
            $window->callMs[] = $callElapsed;
            if ($r === 499500) {
                $window->callStaticOk++;
            } else {
                $window->errors++;
                fwrite(STDERR, "callStatic returned wrong: {$r}\n");
            }
            $window->iterations++;
            continue; // skip the compile-elapsed bookkeeping below
        } else {
            // 5% — clear compile cache + cold compile. Validates that
            // cache repopulation works under load. We don't Loader::
            // reset() because PHP can't undefine eval'd classes; the
            // Loader registry would re-eval and PHP fatals on
            // redeclaration. Compile-cache clear is the analogous LRU-
            // eviction stress test without the eval problem.
            Compiler::clearCompileCache();
            (new Compiler())->compileBytes($entry['classPath'], file_get_contents($entry['path']));
            $window->coldCompiles++;
        }
    } catch (\Throwable $e) {
        $window->errors++;
        if (getenv('PHPJAVA_SOAK_VERBOSE')) {
            fwrite(STDERR, sprintf(
                "[%s] %s: %s\n",
                date('c'),
                get_class($e),
                $e->getMessage()
            ));
        }
    }

    $window->compileMs[] = (microtime(true) - $t0) * 1000;
    $window->iterations++;

    if (microtime(true) >= $nextEmit) {
        emitMetrics($window);
        $window = new Window();
        $nextEmit = microtime(true) + $windowSeconds;
    }
}

// Final metrics on graceful exit.
emitMetrics($window);
fwrite(STDERR, "soak done\n");
