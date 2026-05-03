<?php
/**
 * Real-library AOT probe — answers Open Q#1 from STATUS.md "what's
 * NOT verified": does the IR Builder cover production Java bytecode
 * (not just our 9 hand-curated fixtures)?
 *
 * Drives every .class in commons-lang3-3.17.0.jar through Compiler::
 * compileBytes() in a child process per class, captures stderr (with
 * CLJP_IR_DEBUG=1 the IR path logs `IR fallback for {method}: {msg}`),
 * tallies classes processed, top-level failures, methods compiled,
 * IR success, IR fallback, exception-message histograms.
 *
 * Child-process-per-class isolates state (each Compiler instance is
 * fresh) and is fast enough for a few hundred classes.
 *
 * Setup: needs /usr/share/java/commons-lang3-3.17.0.jar (`apt install
 * libcommons-lang3-java`). Extracts into /tmp/probe-aot-extracted/
 * once per run if not already present.
 *
 * Run:  php bench/probe-real-library.php
 */
declare(strict_types=1);

$jarPath = '/usr/share/java/commons-lang3-3.17.0.jar';
$extractRoot = '/tmp/probe-aot-extracted';
$phpJavaRoot = realpath(__DIR__ . '/..');
$childScript = '/tmp/probe-aot-child.php';

if (!is_file($jarPath)) {
    fwrite(STDERR, "Missing: {$jarPath}\nInstall: sudo apt install libcommons-lang3-java\n");
    exit(1);
}

if (!is_dir($extractRoot)) {
    mkdir($extractRoot, 0755, true);
    $cwd = getcwd();
    chdir($extractRoot);
    system('jar xf ' . escapeshellarg($jarPath) . ' >/dev/null 2>&1', $rc);
    chdir($cwd);
    if ($rc !== 0) { fwrite(STDERR, "jar extract failed\n"); exit(1); }
}

file_put_contents($childScript, <<<'PHP'
<?php
declare(strict_types=1);
require_once $argv[1] . '/vendor/autoload.php';
$bytes = stream_get_contents(STDIN);
$cp = $argv[2];
$t0 = microtime(true);
try {
    $php = (new \PHPJava\Aot\Compiler())->compileBytes($cp, $bytes);
} catch (\Throwable $e) {
    fwrite(STDERR, "TOPLEVEL: " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDOUT, "MS=" . ((microtime(true)-$t0)*1000) . "\nMETHODS=0\n");
    exit(1);
}
// Count both static and instance methods (instance-method emit landed
// 2026-05-03; pre-that, all methods were emitted as `public static`).
preg_match_all('/public (?:static )?function /', $php, $m);
fwrite(STDOUT, "MS=" . ((microtime(true)-$t0)*1000) . "\nMETHODS=" . count($m[0]) . "\n");
PHP);

$classFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractRoot));
foreach ($rii as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.class')) {
        $classFiles[] = $f->getPathname();
    }
}
sort($classFiles);
fwrite(STDERR, sprintf("Found %d .class files\n", count($classFiles)));

function probeClass(string $phpJavaRoot, string $childScript, string $classFile, string $classPath): array {
    $bytes = file_get_contents($classFile);
    if ($bytes === false) {
        return ['ok' => false, 'err' => 'read-fail', 'fallbacks' => [], 'methods' => 0, 'ms' => 0];
    }
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $env = ['CLJP_IR_DEBUG' => '1', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $cmd = ['php', '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', $childScript, $phpJavaRoot, $classPath];
    $proc = proc_open($cmd, $desc, $pipes, null, $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'err' => 'spawn-fail', 'fallbacks' => [], 'methods' => 0, 'ms' => 0];
    }
    fwrite($pipes[0], $bytes); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($proc);

    $ms = 0.0; $methods = 0;
    if (preg_match('/^MS=([0-9.]+)/m', $stdout, $m)) $ms = (float)$m[1];
    if (preg_match('/^METHODS=(\d+)/m', $stdout, $m)) $methods = (int)$m[1];

    $fallbacks = [];
    foreach (explode("\n", $stderr) as $line) {
        if (preg_match('/^IR fallback for ([^:]+):\s*(.*)$/', $line, $m)) {
            $fallbacks[] = ['method' => $m[1], 'msg' => trim($m[2])];
        }
    }
    $topErr = null;
    if ($exit !== 0) {
        if (preg_match('/^TOPLEVEL:\s*(.*)$/m', $stderr, $m)) $topErr = trim($m[1]);
        else $topErr = 'exit=' . $exit . ' stderr=' . substr(trim($stderr), 0, 200);
    }
    return ['ok' => $exit === 0, 'err' => $topErr, 'fallbacks' => $fallbacks, 'methods' => $methods, 'ms' => $ms];
}

$classesParsed = 0;
$classesFailedTopLevel = 0;
$topLevelErrors = [];
$methodsTotal = 0;
$methodsIrFallback = 0;
$fallbackErrors = [];
$mostFallbackClasses = [];
$failingClasses = [];

$N = count($classFiles);
$progressEvery = max(1, intdiv($N, 20));
$start = microtime(true);
$totalMs = 0.0;

foreach ($classFiles as $i => $cf) {
    $rel = ltrim(substr($cf, strlen($extractRoot)), '/');
    if (str_starts_with($rel, 'META-INF/')) continue;
    $classPath = str_replace('/', '.', preg_replace('/\.class$/', '', $rel));

    $r = probeClass($phpJavaRoot, $childScript, $cf, $classPath);

    if (!$r['ok']) {
        $classesFailedTopLevel++;
        $msg = $r['err'] ?? 'unknown';
        $msgKey = substr(preg_replace('/\b0x[0-9a-fA-F]+|@\w+|#\d+|\b\d+\b/', '?', $msg), 0, 160);
        $topLevelErrors[$msgKey] = ($topLevelErrors[$msgKey] ?? 0) + 1;
        $failingClasses[] = [$classPath, $msgKey];
    } else {
        $classesParsed++;
        $methodsTotal += $r['methods'];
        $fbCount = count($r['fallbacks']);
        $methodsIrFallback += $fbCount;
        foreach ($r['fallbacks'] as $fb) {
            $msgKey = substr(preg_replace('/\b\d+\b/', 'N', $fb['msg']), 0, 160);
            $fallbackErrors[$msgKey] = ($fallbackErrors[$msgKey] ?? 0) + 1;
        }
        if ($fbCount > 0) $mostFallbackClasses[] = [$classPath, $fbCount, $r['methods']];
        $totalMs += $r['ms'];
    }

    if (($i + 1) % $progressEvery === 0 || $i === $N - 1) {
        fwrite(STDERR, sprintf(
            "[%d/%d] %.1fs  ok=%d topfail=%d methods=%d IRfb=%d\n",
            $i + 1, $N, microtime(true) - $start,
            $classesParsed, $classesFailedTopLevel, $methodsTotal, $methodsIrFallback
        ));
    }
}

$elapsed = microtime(true) - $start;
$irOk = $methodsTotal - $methodsIrFallback;

echo "\n=== AOT real-library probe — commons-lang3-3.17.0 ===\n\n";
echo sprintf("Wall time: %.1fs (avg child-compile %.1f ms)\n",
    $elapsed, $classesParsed > 0 ? $totalMs / $classesParsed : 0);
echo sprintf("Classes total: %d  ok=%d  topfail=%d\n",
    $classesParsed + $classesFailedTopLevel, $classesParsed, $classesFailedTopLevel);
echo sprintf("Methods (in OK classes): %d  IR-ok=%d (%.1f%%)  IR-fallback=%d (%.1f%%)\n\n",
    $methodsTotal, $irOk,
    $methodsTotal ? 100.0 * $irOk / $methodsTotal : 0,
    $methodsIrFallback,
    $methodsTotal ? 100.0 * $methodsIrFallback / $methodsTotal : 0);

if ($topLevelErrors) {
    echo "--- Top-level failure histogram ---\n";
    arsort($topLevelErrors);
    $i = 0; foreach ($topLevelErrors as $msg => $c) { printf("  %4d  %s\n", $c, $msg); if (++$i >= 15) break; }
    echo "\n";
}
if ($fallbackErrors) {
    echo "--- IR fallback histogram (top 25) ---\n";
    arsort($fallbackErrors);
    $i = 0; foreach ($fallbackErrors as $msg => $c) { printf("  %4d  %s\n", $c, $msg); if (++$i >= 25) break; }
    echo "\n";
}
if ($mostFallbackClasses) {
    usort($mostFallbackClasses, fn($a,$b) => $b[1] <=> $a[1]);
    echo "--- Top 10 classes by fallback count ---\n";
    foreach (array_slice($mostFallbackClasses, 0, 10) as [$cp,$fb,$tot]) printf("  %3d/%3d  %s\n", $fb, $tot, $cp);
    echo "\n";
}
if ($failingClasses) {
    echo "--- First 10 top-level-failing classes ---\n";
    foreach (array_slice($failingClasses, 0, 10) as [$cp,$msg]) printf("  %s  -- %s\n", $cp, $msg);
}
