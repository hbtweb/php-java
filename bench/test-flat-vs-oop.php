<?php
// Head-to-head: OOP IR Lowerer vs flat-array Lowerer on the same IR shape.
// Tests the "10× faster" hypothesis for the lower phase.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Flat.php';

use PHPJava\Aot\Ir as Oop;
use PHPJava\Aot\Ir\Flat as Fl;

// Hand-build BenchAdd::sum1k() in BOTH IRs.

// OOP
$oopMethod = new Oop\Method('sum1k', '()I', true, [], 2, [
    0 => new Oop\BasicBlock(0, [
        new Oop\StoreLocal(0, new Oop\IntLit(0)),
        new Oop\StoreLocal(1, new Oop\IntLit(0)),
    ], new Oop\Goto_(4)),
    4 => new Oop\BasicBlock(4, [], new Oop\CondGoto(
        new Oop\BinOp('>=', new Oop\LocalRead(1), new Oop\IntLit(1000)),
        21, 11,
    )),
    11 => new Oop\BasicBlock(11, [
        new Oop\StoreLocal(0, new Oop\BinOp('+', new Oop\LocalRead(0), new Oop\LocalRead(1))),
        new Oop\IincLocal(1, 1),
    ], new Oop\Goto_(4)),
    21 => new Oop\BasicBlock(21, [], new Oop\Return_(new Oop\LocalRead(0))),
]);
$oopModule = new Oop\Module('Demo', 'Demo', [$oopMethod]);

// Flat-array equivalent
$flatMethod = [
    'name' => 'sum1k',
    'params' => [],
    'maxLocals' => 2,
    'blocks' => [
        0 => [
            'stmts' => [Fl\storeLocal(0, Fl\intLit(0)), Fl\storeLocal(1, Fl\intLit(0))],
            'term' => Fl\gotoTerm(4),
        ],
        4 => [
            'stmts' => [],
            'term' => Fl\condGotoTerm(Fl\binOp('>=', Fl\localRead(1), Fl\intLit(1000)), 21, 11),
        ],
        11 => [
            'stmts' => [
                Fl\storeLocal(0, Fl\binOp('+', Fl\localRead(0), Fl\localRead(1))),
                Fl\iincLocal(1, 1),
            ],
            'term' => Fl\gotoTerm(4),
        ],
        21 => [
            'stmts' => [],
            'term' => Fl\returnTerm(Fl\localRead(0)),
        ],
    ],
];
$flatModule = ['namespace' => 'Demo', 'className' => 'Demo', 'methods' => [$flatMethod]];

// Bench both Lowerers.
const N = 10000;
const W = 1000;

$oopLowerer = new Oop\Lowerer();
$flatLowerer = new Fl\FlatLowerer();

for ($i = 0; $i < W; $i++) $oopLowerer->lowerModule($oopModule);
$start = microtime(true);
for ($i = 0; $i < N; $i++) $oopLowerer->lowerModule($oopModule);
$oop_ns = (microtime(true) - $start) * 1e9 / N;

for ($i = 0; $i < W; $i++) $flatLowerer->lower($flatModule);
$start = microtime(true);
for ($i = 0; $i < N; $i++) $flatLowerer->lower($flatModule);
$flat_ns = (microtime(true) - $start) * 1e9 / N;

echo "PHP " . PHP_VERSION . " | jit=" . (ini_get('opcache.jit') ?: 'off')
   . " | buffer=" . ini_get('opcache.jit_buffer_size') . "\n\n";

printf("OOP Lowerer:        %8.0f ns/call  (%.2f µs)\n", $oop_ns, $oop_ns / 1000);
printf("Flat-array Lowerer: %8.0f ns/call  (%.2f µs)\n", $flat_ns, $flat_ns / 1000);
printf("Speedup: %.2fx\n", $oop_ns / $flat_ns);

echo "\nNow construction speed (build-time, no lowering):\n";

// Build OOP IR Module from scratch each call
$buildOop = function() {
    return new Oop\Method('sum1k', '()I', true, [], 2, [
        0 => new Oop\BasicBlock(0, [
            new Oop\StoreLocal(0, new Oop\IntLit(0)),
            new Oop\StoreLocal(1, new Oop\IntLit(0)),
        ], new Oop\Goto_(4)),
        4 => new Oop\BasicBlock(4, [], new Oop\CondGoto(
            new Oop\BinOp('>=', new Oop\LocalRead(1), new Oop\IntLit(1000)),
            21, 11,
        )),
        11 => new Oop\BasicBlock(11, [
            new Oop\StoreLocal(0, new Oop\BinOp('+', new Oop\LocalRead(0), new Oop\LocalRead(1))),
            new Oop\IincLocal(1, 1),
        ], new Oop\Goto_(4)),
        21 => new Oop\BasicBlock(21, [], new Oop\Return_(new Oop\LocalRead(0))),
    ]);
};
$buildFlat = function() {
    return [
        'name' => 'sum1k',
        'params' => [],
        'maxLocals' => 2,
        'blocks' => [
            0 => ['stmts' => [Fl\storeLocal(0, Fl\intLit(0)), Fl\storeLocal(1, Fl\intLit(0))],
                  'term' => Fl\gotoTerm(4)],
            4 => ['stmts' => [],
                  'term' => Fl\condGotoTerm(Fl\binOp('>=', Fl\localRead(1), Fl\intLit(1000)), 21, 11)],
            11 => ['stmts' => [Fl\storeLocal(0, Fl\binOp('+', Fl\localRead(0), Fl\localRead(1))),
                               Fl\iincLocal(1, 1)],
                   'term' => Fl\gotoTerm(4)],
            21 => ['stmts' => [], 'term' => Fl\returnTerm(Fl\localRead(0))],
        ],
    ];
};

for ($i = 0; $i < W; $i++) $buildOop();
$start = microtime(true);
for ($i = 0; $i < N; $i++) $buildOop();
$oop_build_ns = (microtime(true) - $start) * 1e9 / N;

for ($i = 0; $i < W; $i++) $buildFlat();
$start = microtime(true);
for ($i = 0; $i < N; $i++) $buildFlat();
$flat_build_ns = (microtime(true) - $start) * 1e9 / N;

printf("OOP construction:   %8.0f ns/call  (%.2f µs)\n", $oop_build_ns, $oop_build_ns / 1000);
printf("Flat construction:  %8.0f ns/call  (%.2f µs)\n", $flat_build_ns, $flat_build_ns / 1000);
printf("Construction speedup: %.2fx\n", $oop_build_ns / $flat_build_ns);

echo "\n=== End-to-end ===\n";
$oop_total = $oop_ns + $oop_build_ns;
$flat_total = $flat_ns + $flat_build_ns;
printf("OOP construct + lower:  %.2f µs\n", $oop_total / 1000);
printf("Flat construct + lower: %.2f µs\n", $flat_total / 1000);
printf("Total speedup: %.2fx\n", $oop_total / $flat_total);
