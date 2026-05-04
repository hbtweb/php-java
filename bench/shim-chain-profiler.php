<?php
declare(strict_types=1);

/**
 * Shim-chain depth profiler — measures the architectural ceiling for
 * the M1 (3.59× depth-3 chain) speedup found in
 * bench/php-frontend-meta-falsifier.php.
 *
 * The decisive question after meta-falsification: does the depth-3
 * compounding case fire often in the shim layer we've shipped, or is
 * it theoretical? This is a static-analysis profile of all PHP files
 * under src/Aot/Runtime/. Builds a shim-to-shim callgraph, computes
 * max chain depth per method via DFS (with cycle detection), and
 * histograms.
 *
 * Verdict criterion (set before running):
 *   - If ≥20% of methods have max-depth ≥3, the architecture's
 *     compounding win fires often → spike the unified frontend.
 *   - If <20%, depth-3 is rare in the current shim layer → spike
 *     deferred until more compositional shims ship.
 *
 * Run from repo root:  php bench/shim-chain-profiler.php
 */

const SHIM_ROOTS = [
    __DIR__ . '/../src/Aot/Runtime/bootstrap.php',
    __DIR__ . '/../src/Aot/Runtime/java',
    __DIR__ . '/../src/Aot/Runtime/javax',
    __DIR__ . '/../src/Aot/Runtime/jdk',
    __DIR__ . '/../src/Aot/Runtime/sun',
];

// ─── Walk shim files, collect methods + their static call sites ───────────

function gatherShimFiles(): array {
    $out = [];
    foreach (SHIM_ROOTS as $r) {
        if (\is_file($r)) { $out[] = $r; continue; }
        if (!\is_dir($r)) continue;
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($r));
        foreach ($rii as $f) {
            if ($f->isFile() && \str_ends_with($f->getFilename(), '.php')) {
                $out[] = $f->getPathname();
            }
        }
    }
    return $out;
}

/**
 * Walk tokens, return list of:
 *   [class_fqn, method_name, static_calls[]]
 * where static_calls is [['Class\\Fqn', 'methodName'], ...] resolved to
 * the form the callgraph keys on.
 */
function indexFile(string $path): array
{
    $src = \file_get_contents($path);
    $toks = \array_values(\token_get_all($src));
    $n = \count($toks);

    $namespace = '';
    $classStack = [];   // stack of [classFqn, depth_at_open]
    $depth = 0;
    $methods = [];

    $i = 0;
    while ($i < $n) {
        $t = $toks[$i];

        if (\is_array($t)) {
            switch ($t[0]) {
                case \T_NAMESPACE:
                    $name = '';
                    $j = $i + 1;
                    while ($j < $n) {
                        $u = $toks[$j];
                        if (\is_array($u) && \in_array($u[0], [\T_STRING, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_WHITESPACE], true)) {
                            if ($u[0] !== \T_WHITESPACE) $name .= $u[1];
                            $j++; continue;
                        }
                        break;
                    }
                    $namespace = $name;
                    $i = $j;
                    continue 2;

                case \T_CLASS:
                    // class NAME — find name, then depth tracking
                    $j = $i + 1;
                    while ($j < $n && (!\is_array($toks[$j]) || $toks[$j][0] === \T_WHITESPACE)) $j++;
                    if ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === \T_STRING) {
                        $className = $toks[$j][1];
                        // Skip until '{' starts class body
                        while ($j < $n && $toks[$j] !== '{') $j++;
                        if ($j < $n) {
                            $depth++;
                            $classStack[] = [$namespace ? $namespace . '\\' . $className : $className, $depth];
                            $i = $j + 1;
                            continue 2;
                        }
                    }
                    $i++; continue 2;

                case \T_FUNCTION:
                    if (empty($classStack)) { $i++; continue 2; }
                    [$classFqn, ] = \end($classStack);
                    // function NAME(... ) ... { body }
                    $j = $i + 1;
                    while ($j < $n && (!\is_array($toks[$j]) || $toks[$j][0] === \T_WHITESPACE)) $j++;
                    if ($j < $n && \is_array($toks[$j]) && $toks[$j][0] === \T_STRING) {
                        $methodName = $toks[$j][1];
                        // Skip to '{'
                        while ($j < $n && $toks[$j] !== '{' && $toks[$j] !== ';') $j++;
                        if ($j < $n && $toks[$j] === ';') {
                            // abstract method declaration
                            $methods[] = [$classFqn, $methodName, []];
                            $i = $j + 1;
                            continue 2;
                        }
                        if ($j < $n && $toks[$j] === '{') {
                            // Find matching '}'. PHP's interpolated strings
                            // tokenise `"...{$var}..."` as T_CURLY_OPEN for
                            // the opening brace but a plain `}` for the
                            // close — without counting T_CURLY_OPEN /
                            // T_DOLLAR_OPEN_CURLY_BRACES, the counter
                            // decrements past zero on the first interpolated
                            // string and exits the method body early.
                            $bodyStart = $j;
                            $bd = 1;
                            $k = $j + 1;
                            while ($k < $n && $bd > 0) {
                                $tk = $toks[$k];
                                if ($tk === '{') {
                                    $bd++;
                                } elseif (\is_array($tk) && \in_array($tk[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                                    $bd++;
                                } elseif ($tk === '}') {
                                    $bd--;
                                }
                                if ($bd === 0) break;
                                $k++;
                            }
                            $bodyEnd = $k;
                            $calls = scanStaticCalls($toks, $bodyStart + 1, $bodyEnd, $namespace, $classFqn);
                            $methods[] = [$classFqn, $methodName, $calls];
                            $i = $bodyEnd + 1;
                            continue 2;
                        }
                    }
                    $i++; continue 2;
            }
        }

        // Track top-level depth for class scope.
        if ($t === '{') $depth++;
        if ($t === '}') {
            // If we just closed a class, pop the stack.
            if (!empty($classStack) && $classStack[\count($classStack) - 1][1] === $depth) {
                \array_pop($classStack);
            }
            $depth--;
        }
        $i++;
    }

    return $methods;
}

/**
 * Scan tokens [start, end) for static-call sites of the form
 *   Foo\Bar::method(   or   \Foo\Bar::method(   or   self::method(
 * Returns [[ClassFqn, method], ...] resolved to current namespace
 * when the class was bare.
 */
function scanStaticCalls(array $toks, int $start, int $end, string $currentNs, string $currentClass = ''): array
{
    $out = [];
    $n = $end;
    $i = $start;
    while ($i < $n) {
        $t = $toks[$i];
        // Detect a class-name segment.
        if (\is_array($t) && \in_array($t[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NS_SEPARATOR], true)) {
            $name = '';
            $j = $i;
            while ($j < $n) {
                $u = $toks[$j];
                if (\is_array($u) && \in_array($u[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NS_SEPARATOR], true)) {
                    $name .= $u[1];
                    $j++;
                    continue;
                }
                if ($u === '\\') { $name .= '\\'; $j++; continue; }
                break;
            }
            // Look for ::method(
            if ($j < $n - 2 && \is_array($toks[$j]) && $toks[$j][0] === \T_DOUBLE_COLON) {
                $k = $j + 1;
                if ($k < $n && \is_array($toks[$k]) && $toks[$k][0] === \T_STRING) {
                    $method = $toks[$k][1];
                    $afterMethod = $k + 1;
                    if ($afterMethod < $n && $toks[$afterMethod] === '(') {
                        // Resolve class name. self/static/parent → the
                        // enclosing class (FQN, not just namespace —
                        // the dispatch is to a sibling method, not a
                        // namespace-level function).
                        $cls = $name;
                        if ($cls === 'self' || $cls === 'static' || $cls === 'parent') {
                            $cls = $currentClass !== '' ? '\\' . \ltrim($currentClass, '\\') : ($currentNs ? '\\' . $currentNs : '\\');
                        } elseif ($cls[0] !== '\\') {
                            $cls = $currentNs ? '\\' . $currentNs . '\\' . $cls : '\\' . $cls;
                        }
                        $out[] = [$cls, $method];
                        $i = $afterMethod;
                        continue;
                    }
                }
            }
            $i = $j;
            continue;
        }
        $i++;
    }
    return $out;
}

// ─── Build callgraph + depth analysis ─────────────────────────────────────

function buildCallgraph(array $allMethods): array
{
    // Map of "class\\fqn::method" → list of "class\\fqn::method" callees
    $graph = [];
    $known = [];
    foreach ($allMethods as [$cls, $m, $_]) {
        $known[\ltrim($cls, '\\') . '::' . $m] = true;
    }
    foreach ($allMethods as [$cls, $m, $calls]) {
        $key = \ltrim($cls, '\\') . '::' . $m;
        $callees = [];
        foreach ($calls as [$ccls, $cm]) {
            $ck = \ltrim($ccls, '\\') . '::' . $cm;
            if (isset($known[$ck])) $callees[] = $ck;  // edge into our shim layer
        }
        $graph[$key] = $callees;
    }
    return $graph;
}

function maxDepth(array $graph, string $node, array $visiting = []): int
{
    if (isset($visiting[$node])) return 0;
    if (!isset($graph[$node])) return 1;
    $visiting[$node] = true;
    if (empty($graph[$node])) return 1;  // leaf
    $max = 0;
    foreach ($graph[$node] as $callee) {
        $d = maxDepth($graph, $callee, $visiting);
        if ($d > $max) $max = $d;
    }
    return 1 + $max;
}

function findDeepestPath(array $graph, string $node, array $visiting = []): array
{
    if (isset($visiting[$node])) return [];
    if (empty($graph[$node] ?? [])) return [$node];
    $visiting[$node] = true;
    $best = [];
    foreach ($graph[$node] as $callee) {
        $p = findDeepestPath($graph, $callee, $visiting);
        if (\count($p) > \count($best)) $best = $p;
    }
    return [$node, ...$best];
}

// ─── Run ──────────────────────────────────────────────────────────────────

echo "Shim-chain depth profiler\n";
echo str_repeat('=', 56) . "\n\n";

$files = gatherShimFiles();
\printf("Scanning %d shim files...\n\n", \count($files));

$allMethods = [];
foreach ($files as $f) {
    foreach (indexFile($f) as $row) $allMethods[] = $row;
}
\printf("  total methods indexed: %d\n", \count($allMethods));

$graph = buildCallgraph($allMethods);

$histogram = \array_fill(1, 10, 0);
$samples = [];     // depth → [methodKey, ...]
foreach ($graph as $key => $_) {
    $d = maxDepth($graph, $key);
    if ($d > 9) $d = 9;
    $histogram[$d]++;
    $samples[$d][] = $key;
}

echo "\nMax-chain-depth histogram (per shim method):\n";
$total = \array_sum($histogram);
$cumDepth3plus = 0;
for ($d = 1; $d <= 9; $d++) {
    $count = $histogram[$d];
    if ($count === 0 && $d >= 6) continue;
    $pct = $total ? (100 * $count / $total) : 0;
    $bar = \str_repeat('█', (int) ($pct / 2));
    \printf("  depth %d  %4d  (%5.1f%%)  %s\n", $d, $count, $pct, $bar);
    if ($d >= 3) $cumDepth3plus += $count;
}
\printf("\n  depth ≥3:  %d / %d  (%.1f%%)\n", $cumDepth3plus, $total, 100 * $cumDepth3plus / $total);

echo "\nSample deepest chains (one per depth bucket where present):\n";
foreach ([5, 4, 3, 2] as $d) {
    if (empty($samples[$d])) continue;
    $sample = $samples[$d][0];
    $path = findDeepestPath($graph, $sample);
    \printf("  depth %d:  %s\n", $d, \implode(' → ', $path));
}

echo "\n" . str_repeat('=', 56) . "\n";
$thresholdPct = 20.0;
$verdictPct = 100 * $cumDepth3plus / $total;
if ($verdictPct >= $thresholdPct) {
    \printf("VERDICT: depth ≥3 fires for %.1f%% of methods (≥%.1f%% threshold)\n", $verdictPct, $thresholdPct);
    echo "         M1 win (~3.6× speedup) is real for this shim layer\n";
    echo "         → spike the unified frontend\n";
} else {
    \printf("VERDICT: depth ≥3 fires for %.1f%% of methods (<%.1f%% threshold)\n", $verdictPct, $thresholdPct);
    echo "         M1 win is theoretical for the current surface\n";
    echo "         → defer spike, prioritise bb-fill\n";
}
echo str_repeat('=', 56) . "\n";
