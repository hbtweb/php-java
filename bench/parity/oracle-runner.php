<?php
declare(strict_types=1);

/**
 * Path D′ oracle harness — PHP-side runner.
 *
 * The Clojure-side driver invokes this from within the FFM-hosted
 * libphp process to capture PHPJava's AOT-compiled behaviour for a
 * (class, method, args) tuple. Output is JSON on stdout matching the
 * contract documented in bench/parity/README.md.
 *
 * Usage from PHP CLI (for development / testing):
 *
 *   php bench/parity/oracle-runner.php \
 *       --class=java.lang.Math \
 *       --method=abs \
 *       --args='[-42]'
 *
 * Or, the more common path from the Clojure harness:
 *
 *   zend_eval_string(
 *     "require 'bench/parity/oracle-runner.php';
 *      echo runOracle('java.lang.Math', 'abs', [-42]);"
 *   );
 *
 * Both paths converge on `runOracle()` which returns a JSON string.
 *
 * Per docs/LAYERS.md §License posture: this runner is part of the
 * test oracle, not the deliverable. OpenJDK never enters the
 * PHPJava artifact; only captured I/O traces flow back to the
 * Clojure comparator.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPJava\IO\Standard\Output;

/**
 * Capture (return, exception, stdout, stderr) for one PHPJava
 * invocation and return as a JSON string in the parity contract shape.
 *
 * @param string $classFqn  dotted Java FQN, e.g. "java.lang.Math"
 * @param string $methodName  e.g. "abs"
 * @param array  $args        primitive PHP-typed args
 */
function runOracle(string $classFqn, string $methodName, array $args): string
{
    // Ensure a clean output capture surface. PHPJava's PrintStream
    // shim writes via Output::write to a heapspace buffer; reset
    // before each invocation so prior call output doesn't leak.
    Output::clearHeapspace();

    $result = [
        'class'  => $classFqn,
        'method' => $methodName,
        'args'   => $args,
        'result' => null,
    ];

    \ob_start();
    try {
        $rc = \PHPJava\Aot\Compiler::class;
        // Route through Loader::callStatic — same dispatch path as
        // AOT-emitted code uses for cross-class invokestatic. Caller's
        // args are PHP-native per CONTRACTS.md §1.
        $bin = \str_replace('.', '/', $classFqn);
        $return = \PHPJava\Aot\Loader::callStatic($bin, $methodName, ...$args);
        $stdout = \ob_get_clean();
        // Normalise the heapspace-captured Java println output into
        // the same stream as raw PHP echo.
        $heap = Output::getHeapspace() ?: '';
        $result['result'] = [
            'kind'   => 'ok',
            'return' => normaliseReturn($return),
            'stdout' => $stdout . $heap,
            'stderr' => '',
        ];
    } catch (\Throwable $e) {
        \ob_end_clean();
        // Map the PHP exception class back to its Java FQN. AOT-routed
        // exceptions are under \PHPJava\Aot\Runtime\java\lang\…; the
        // shim hierarchy under \PHPJava\Packages\java\lang\… is the
        // legacy form that interp-fallback paths used. Either gets
        // mapped back to the dotted Java FQN.
        $phpClass = \get_class($e);
        $javaClass = \str_replace(
            ['PHPJava\\Aot\\Runtime\\', 'PHPJava\\Packages\\', '\\'],
            ['', '', '.'],
            $phpClass
        );
        $result['result'] = [
            'kind'    => 'exception',
            'class'   => $javaClass,
            'message' => $e->getMessage(),
        ];
    }

    return \json_encode($result, \JSON_UNESCAPED_SLASHES);
}

/**
 * Normalise PHPJava return values into JSON-stable shapes the
 * comparator can deep-equal. Keeps primitives (int/float/string/bool)
 * verbatim; serialises composite shapes (arrays, objects implementing
 * common protocols) into the parity-harness's lingua franca.
 */
function normaliseReturn($v)
{
    if ($v === null || \is_int($v) || \is_string($v) || \is_bool($v)) {
        return $v;
    }
    if (\is_float($v)) {
        if (\is_nan($v)) return ['__nan__' => true];
        if ($v === \INF)  return ['__inf__' => 1];
        if ($v === -\INF) return ['__inf__' => -1];
        return $v;
    }
    if (\is_array($v)) {
        return \array_map('normaliseReturn', $v);
    }
    if (\is_object($v)) {
        // Best-effort serialisation: emit the FQN + scalar field
        // values. Comparator on the Clojure side maps this to its
        // own object shape.
        $rc = new \ReflectionObject($v);
        $fields = [];
        foreach ($rc->getProperties() as $p) {
            $p->setAccessible(true);
            $fields[$p->getName()] = normaliseReturn($p->getValue($v));
        }
        return [
            '__obj__'  => \str_replace('\\', '.', $rc->getName()),
            'fields'   => $fields,
        ];
    }
    return ['__unknown__' => \gettype($v)];
}

// CLI entry — for development without the full Clojure harness.
if (\PHP_SAPI === 'cli' && isset($argv[0]) && \realpath($argv[0]) === __FILE__) {
    $opts = \getopt('', ['class:', 'method:', 'args::']);
    if (!isset($opts['class'], $opts['method'])) {
        \fwrite(\STDERR, "Usage: php oracle-runner.php --class=FQN --method=name --args='[json]'\n");
        exit(2);
    }
    $args = isset($opts['args']) ? \json_decode($opts['args'], true) : [];
    if (!\is_array($args)) {
        \fwrite(\STDERR, "--args must be a JSON array\n");
        exit(2);
    }
    echo runOracle($opts['class'], $opts['method'], $args) . "\n";
}
