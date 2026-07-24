<?php

declare(strict_types=1);

/**
 * Ratchet harness — ROADMAP §Next-work plan, Phase 0c.
 *
 * Progress on the bb-fill effort lives in numbers this prints, not in
 * prose. Prose drifts: T3 concurrency shipped 5,507 LOC while a table
 * still called it "4-6 weeks of future work". A number can't.
 *
 * Five metrics, each with a direction:
 *
 *   R1  parity cases green ................ up-ratchet (more is better)
 *   R2  files under src/Aot/Runtime that
 *       still throw NotImplementedException  down-ratchet
 *   R3  files under src/Aot/Runtime coupled
 *       to the legacy \PHPJava\Packages tree down-ratchet
 *   R4  distinct classes with a committed
 *       parity battery ..................... up-ratchet
 *   R5  shim methods at call-chain depth >=3 TRIGGER, not a ratchet:
 *       expected to RISE as compositional
 *       classes land; crossing 20% fires the
 *       unified-frontend spike decision
 *       (ROADMAP §"What's not on the roadmap")
 *
 * A ratchet metric moving the wrong way vs the committed baseline is a
 * failure (exit 1). R5 only warns. R1 is the slow one (runs the Clojure
 * parity driver over every committed battery) and is gated behind
 * --parity; the structural metrics R2/R3/R4/R5 are fast and always run.
 *
 * Usage:
 *   php bench/ratchet.php                 structural metrics vs baseline
 *   php bench/ratchet.php --parity        also run parity batteries (slow)
 *   php bench/ratchet.php --update        write current values as baseline
 *   php bench/ratchet.php --json          machine-readable output
 *
 * The baseline lives in bench/ratchet-baseline.json and is committed, so
 * "did this branch regress a metric" is answerable without prior state.
 */

const ROOT     = __DIR__ . '/..';
const RUNTIME  = ROOT . '/src/Aot/Runtime';
const CASES    = ROOT . '/bench/parity/cases';
const BASELINE = ROOT . '/bench/ratchet-baseline.json';

/** @return list<string> every .php under a directory */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

/** R2 — runtime files still carrying a NotImplementedException. */
function countNie(): int
{
    $n = 0;
    foreach (phpFiles(RUNTIME) as $f) {
        if (\str_contains(\file_get_contents($f), 'NotImplementedException')) {
            $n++;
        }
    }
    return $n;
}

/** R3 — runtime files still coupled to the legacy \PHPJava\Packages tree. */
function countPackagesCoupling(): int
{
    $n = 0;
    foreach (phpFiles(RUNTIME) as $f) {
        if (\preg_match('/PHPJava\\\\Packages/', \file_get_contents($f))) {
            $n++;
        }
    }
    return $n;
}

/** R4 — distinct classes with a committed parity battery (.json / .gen.json, not holdout). */
function countBatteries(): int
{
    $classes = [];
    foreach (\glob(CASES . '/*.json') as $f) {
        $b = \basename($f);
        if (\str_contains($b, 'holdout') || \str_starts_with($b, 'example-')) {
            continue;
        }
        // java.lang.Integer.json and java.lang.Integer.gen.json -> one class
        $classes[\preg_replace('/\.(gen\.)?json$/', '', $b)] = true;
    }
    return \count($classes);
}

/** R5 — percentage of shim methods whose static call chain reaches depth >=3. */
function shimChainDepth(): array
{
    $out = \shell_exec('php ' . \escapeshellarg(ROOT . '/bench/shim-chain-profiler.php') . ' 2>&1');
    if ($out !== null && \preg_match('/depth \S+3:\s+(\d+)\s*\/\s*(\d+)/u', $out, $m)) {
        $deep = (int) $m[1];
        $tot  = (int) $m[2];
        return ['deep' => $deep, 'total' => $tot, 'pct' => $tot > 0 ? $deep / $tot * 100 : 0.0];
    }
    return ['deep' => -1, 'total' => -1, 'pct' => -1.0];
}

/**
 * R1 — parity cases green, across every committed battery. Slow: shells
 * out to the Clojure oracle driver once per battery.
 * @return array{green:int, total:int, perBattery:array<string,array{green:int,total:int}>}
 */
function parityGreen(): array
{
    $green = 0;
    $total = 0;
    $per   = [];
    foreach (\glob(CASES . '/*.json') as $f) {
        $b = \basename($f);
        if (\str_contains($b, 'holdout') || \str_starts_with($b, 'example-')) {
            continue;
        }
        $cmd = 'cd ' . \escapeshellarg(ROOT) . ' && '
             . 'clj -Sdeps \'{:paths ["bench/parity"] :deps {org.clojure/data.json {:mvn/version "2.5.0"}}}\' '
             . '-M -m oracle-driver --case ' . \escapeshellarg('bench/parity/cases/' . $b) . ' 2>&1 | tail -3';
        $res = \shell_exec($cmd) ?? '';
        if (\preg_match('/(\d+)\/(\d+)\s+match/', $res, $m)) {
            $g = (int) $m[1];
            $t = (int) $m[2];
            $green += $g;
            $total += $t;
            $per[$b] = ['green' => $g, 'total' => $t];
        } else {
            $per[$b] = ['green' => 0, 'total' => 0, 'error' => \trim($res)];
        }
    }
    return ['green' => $green, 'total' => $total, 'perBattery' => $per];
}

// ─── run ─────────────────────────────────────────────────────────────────────

$opts    = $argv;
$doParity = \in_array('--parity', $opts, true);
$doUpdate = \in_array('--update', $opts, true);
$asJson   = \in_array('--json', $opts, true);

$metrics = [
    'R2_nie_files'          => countNie(),
    'R3_packages_coupling'  => countPackagesCoupling(),
    'R4_batteries'          => countBatteries(),
    'R5_depth3'             => shimChainDepth(),
];
if ($doParity) {
    $metrics['R1_parity'] = parityGreen();
}

// direction: +1 = up-ratchet (must not decrease), -1 = down-ratchet
// (must not increase), 0 = trigger/report-only.
$directions = [
    'R1_parity'            => +1,
    'R2_nie_files'         => -1,
    'R3_packages_coupling' => -1,
    'R4_batteries'         => +1,
    'R5_depth3'            => 0,
];

$baseline = \is_file(BASELINE)
    ? \json_decode(\file_get_contents(BASELINE), true)
    : null;

if ($doUpdate) {
    $store = $metrics;
    // Preserve a prior R1 when this run didn't measure it, so --update
    // without --parity doesn't wipe the parity baseline.
    if (!$doParity && isset($baseline['R1_parity'])) {
        $store['R1_parity'] = $baseline['R1_parity'];
    }
    \file_put_contents(BASELINE, \json_encode($store, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    echo "baseline written to bench/ratchet-baseline.json\n";
    exit(0);
}

if ($asJson) {
    echo \json_encode($metrics, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// scalar view of each metric for comparison
$scalar = static function (string $k, $v) {
    return match ($k) {
        'R1_parity' => $v['green'],
        'R5_depth3' => $v['pct'],
        default     => $v,
    };
};

$regressed = false;
$warned    = false;
echo "Ratchet — " . \date('Y-m-d') . "\n";
echo \str_repeat('─', 66) . "\n";
foreach ($metrics as $k => $v) {
    $cur  = $scalar($k, $v);
    $base = $baseline[$k] ?? null;
    $prev = $base === null ? null : $scalar($k, $base);
    $dir  = $directions[$k];

    $label = match ($k) {
        'R1_parity'            => 'R1 parity cases green',
        'R2_nie_files'         => 'R2 NIE-bearing files',
        'R3_packages_coupling' => 'R3 Packages coupling',
        'R4_batteries'         => 'R4 parity batteries',
        'R5_depth3'            => 'R5 depth>=3 (trigger)',
    };

    $shown = $k === 'R1_parity'
        ? \sprintf('%d/%d', $v['green'], $v['total'])
        : ($k === 'R5_depth3' ? \sprintf('%.1f%% (%d/%d)', $v['pct'], $v['deep'], $v['total']) : (string) $cur);

    $flag = '';
    if ($prev !== null) {
        $delta = $cur - $prev;
        if ($dir === -1 && $delta > 0) { $flag = "  ✗ REGRESSED (was {$prev})"; $regressed = true; }
        elseif ($dir === +1 && $delta < 0) { $flag = "  ✗ REGRESSED (was {$prev})"; $regressed = true; }
        elseif ($delta !== 0) { $flag = \sprintf('  (%+g vs baseline)', $delta); }
    }
    // R5 trigger check
    if ($k === 'R5_depth3' && $v['pct'] >= 20.0) {
        $flag .= '  ⚠ crossed 20% — revisit unified-frontend spike';
        $warned = true;
    }

    \printf("  %-24s %-18s%s\n", $label, $shown, $flag);
}
echo \str_repeat('─', 66) . "\n";
if (!$doParity) {
    echo "  (R1 skipped — pass --parity to run the batteries)\n";
}
if ($regressed) {
    echo "  RATCHET BROKEN — a metric moved the wrong way.\n";
    exit(1);
}
echo $warned ? "  OK (with trigger warning)\n" : "  OK\n";
exit(0);
