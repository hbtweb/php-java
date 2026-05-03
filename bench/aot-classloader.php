<?php
// Smoke-test the AOT classloader integration. With PHPJAVA_AOT_MODE=lazy,
// the standard JavaClass::load → getInvoker → getStatic → getMethods →
// call(...) chain should route through the AOT'd PHP, not the bytecode
// interpreter. Without the env var, falls back to interpreter.
//
// CONTRACTS.md §3 + §5 — production reachability.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Loader;
use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

function check(string $label, $expected, $actual): bool {
    $ok = $expected === $actual;
    $expStr = var_export($expected, true);
    $actStr = var_export($actual, true);
    echo sprintf("%-60s exp=%s got=%s  %s\n", $label, $expStr, $actStr, $ok ? 'PASS' : 'FAIL');
    return $ok;
}

$root     = realpath(__DIR__ . '/..');
$autoload = $root . '/vendor/autoload.php';
$fixtures = $root . '/bench/fixtures';

// Path A — interpreter (default, env unset).
putenv('PHPJAVA_AOT_MODE=');
Loader::reset();
$c = JavaClass::load('BenchAdd');
$r = $c->getInvoker()->getStatic()->getMethods()->call('sum1k');
$okA  = check('interpreter (PHPJAVA_AOT_MODE unset): BenchAdd::sum1k()', 499500, $r);
$okAA = check('  → AOT Loader registry untouched (interpreter path)', false, Loader::isLoaded('BenchAdd'));

// Spawn child with PHPJAVA_AOT_MODE=lazy. Static caches are per-process
// so a fresh process is the simplest way to exercise the env-driven
// dispatch path cleanly.
function runAotChild(string $autoload, string $fixtures, string $className, string $method): array {
    $child = sprintf(
        '<?php
require_once %s;
use PHPJava\Aot\Loader;
use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, %s],
]);

$c = JavaClass::load(%s);
$r = $c->getInvoker()->getStatic()->getMethods()->call(%s);
echo $r . "\n";
echo (Loader::isLoaded(%s) ? "loaded" : "not-loaded") . "\n";
',
        var_export($autoload, true),
        var_export($fixtures, true),
        var_export($className, true),
        var_export($method, true),
        var_export($className, true)
    );
    $tmp = tempnam(sys_get_temp_dir(), 'aot-cl-');
    file_put_contents($tmp, $child);
    $out = trim(shell_exec('PHPJAVA_AOT_MODE=lazy php ' . escapeshellarg($tmp) . ' 2>&1'));
    unlink($tmp);
    $lines = explode("\n", $out);
    return [$lines[0] ?? '', $lines[1] ?? '', $out];
}

[$rB, $loadedB, $rawB] = runAotChild($autoload, $fixtures, 'BenchAdd', 'sum1k');
$okB  = check('lazy-AOT: BenchAdd::sum1k()',                       499500, (int) $rB);
$okBB = check('  → AOT Loader registry populated',                 'loaded', $loadedB);
if (!$okB || !$okBB) echo "stderr/stdout:\n$rawB\n";

[$rC, $loadedC, $rawC] = runAotChild($autoload, $fixtures, 'BenchInvoke', 'callLoop');
$okC  = check('lazy-AOT: BenchInvoke::callLoop() (invokestatic loop)', 100, (int) $rC);
$okCC = check('  → AOT Loader registry populated',                    'loaded', $loadedC);
if (!$okC || !$okCC) echo "stderr/stdout:\n$rawC\n";

$all = $okA && $okAA && $okB && $okBB && $okC && $okCC;
echo "\n" . ($all ? 'OK' : 'FAIL') . "\n";
exit($all ? 0 : 1);
