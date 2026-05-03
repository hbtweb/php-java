<?php
declare(strict_types=1);

// Contract gate — committed snapshots of AOT compiler output so unintended
// drift in emitted PHP shows up as a small text diff in PR review.
//
// Pattern ported from cljp.tools.contract (~/GitHub/ClojurePHP/src/clj/cljp/
// tools/contract.cljc). Why a snapshot, not a schema:
//
//   A schema says "this output is shape-correct."
//   A snapshot says "this output is unchanged."
//   Both matter. They bound the compiler structurally and historically.
//
// Pipeline:  fixture .class → AOT compile → normalise → record → diff
//
// The single test asserts current snapshot == frozen snapshot. To accept a
// change: `php bench/contract.php --update`. The diff in the next commit
// IS the compiler change being reviewed.
//
// Usage:
//   php bench/contract.php             # check; exits 1 on drift
//   php bench/contract.php --update    # rewrite snapshot from current emit
//   php bench/contract.php --print     # print snapshot to stdout
//
// Hollow stance: snapshots capture shape + identifiers, not semantic
// equivalence. Runtime correctness is verified separately by the bench
// harnesses (aot-compile.php runs the emitted PHP and asserts results).
// The gate catches "what changed in the compiler", not "is it correct".

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

// ─── inputs ──────────────────────────────────────────────────────────────
//
// One entry per fixture .class file. Add an entry whenever a new opcode or
// emission pattern needs frozen coverage. The :id is the snapshot key; the
// :class is what the compiler walks.

const INPUTS = [
    ['id' => 'BenchAdd',      'class' => 'BenchAdd'],
    ['id' => 'BenchArray',    'class' => 'BenchArray'],
    ['id' => 'BenchConcat',   'class' => 'BenchConcat'],
    ['id' => 'BenchEmpty',    'class' => 'BenchEmpty'],
    ['id' => 'BenchInvoke',   'class' => 'BenchInvoke'],
    ['id' => 'BenchLambda',   'class' => 'BenchLambda'],
    ['id' => 'BenchRunner',   'class' => 'BenchRunner'],
    ['id' => 'BenchTryCatch', 'class' => 'BenchTryCatch'],
    ['id' => 'HelloWorld',    'class' => 'HelloWorld'],
];

const SNAPSHOT_PATH = __DIR__ . '/contract-snapshots.json';

// ─── normalisation ──────────────────────────────────────────────────────
//
// Mask noise that doesn't represent a compiler-meaningful change: trailing
// whitespace, runs of blank lines. cljp's analogue also collapses gensym
// counter drift; the AOT compiler doesn't gensym today, so that's not in
// scope yet (add when emit introduces named temps with counter suffixes).

function normalise(string $php): string {
    $php = preg_replace('/[ \t]+\n/', "\n", $php);
    $php = preg_replace('/\n{3,}/', "\n\n", $php);
    return trim($php);
}

// Coarse PHP tokeniser for the structural signature. Identifiers, $vars,
// brackets, operators — drops whitespace and string content. Two outputs
// that disagree on tokens disagree structurally; agreement on tokens but
// raw-text disagreement is pure formatting drift.
function tokenise(string $php): array {
    preg_match_all(
        '/<\?php|\?>|\$[A-Za-z_][A-Za-z0-9_]*|[A-Za-z_][A-Za-z0-9_\\\\]*|[\(\)\[\]\{\}=;,]|->|::|=>|<=|>=|==|===|!=|!==|&&|\|\||\+|-|\*|\/|%/',
        $php,
        $m
    );
    return $m[0] ?? [];
}

// ─── snapshot one entry ────────────────────────────────────────────────

function snapshotEntry(array $entry): array {
    try {
        $compiler = new Compiler();
        $php = $compiler->compileClass($entry['class']);
        $norm = normalise($php);
        return [
            'id'  => $entry['id'],
            'php' => $norm,
            'len' => strlen($norm),
            'sig' => tokenise($norm),
        ];
    } catch (\Throwable $e) {
        return [
            'id'    => $entry['id'],
            'error' => $e->getMessage(),
        ];
    }
}

function snapshot(array $inputs): array {
    return array_map('snapshotEntry', $inputs);
}

// ─── diff ────────────────────────────────────────────────────────────────

function diffSnapshots(array $old, array $new): array {
    $oldById = [];
    foreach ($old as $e) $oldById[$e['id']] = $e;
    $newById = [];
    foreach ($new as $e) $newById[$e['id']] = $e;

    $allIds = array_unique(array_merge(array_keys($oldById), array_keys($newById)));
    sort($allIds);

    $report = ['added' => [], 'removed' => [], 'changed' => [], 'stable' => 0];
    foreach ($allIds as $id) {
        $a = $oldById[$id] ?? null;
        $b = $newById[$id] ?? null;
        if ($a === null) {
            $report['added'][] = $id;
        } elseif ($b === null) {
            $report['removed'][] = $id;
        } elseif ($a == $b) {
            $report['stable']++;
        } else {
            $report['changed'][] = ['id' => $id, 'before' => $a, 'after' => $b];
        }
    }
    return $report;
}

// ─── I/O ─────────────────────────────────────────────────────────────────

function readSnapshot(string $path): array {
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    return json_decode($raw, true) ?? [];
}

function writeSnapshot(string $path, array $snap): void {
    file_put_contents(
        $path,
        json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

// ─── CLI ─────────────────────────────────────────────────────────────────

$mode = $argv[1] ?? '';
$current = snapshot(INPUTS);

switch ($mode) {
    case '--update':
        writeSnapshot(SNAPSHOT_PATH, $current);
        printf("wrote %s (%d entries)\n", SNAPSHOT_PATH, count($current));
        exit(0);

    case '--print':
        echo json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);

    default:
        $frozen = readSnapshot(SNAPSHOT_PATH);
        $report = diffSnapshots($frozen, $current);
        printf(
            "contract: %d stable, %d changed, %d added, %d removed\n",
            $report['stable'],
            count($report['changed']),
            count($report['added']),
            count($report['removed'])
        );
        if ($report['changed']) {
            echo "\nchanged:\n";
            foreach ($report['changed'] as $c) {
                printf(
                    "  ~ %s  len %d → %d  sig %d → %d toks\n",
                    $c['id'],
                    $c['before']['len'] ?? 0,
                    $c['after']['len']  ?? 0,
                    count($c['before']['sig'] ?? []),
                    count($c['after']['sig']  ?? [])
                );
            }
        }
        if ($report['added'])   echo "\nadded:    ", implode(' ', $report['added']),   "\n";
        if ($report['removed']) echo "\nremoved:  ", implode(' ', $report['removed']), "\n";
        exit($report['changed'] || $report['removed'] ? 1 : 0);
}
