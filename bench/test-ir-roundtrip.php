<?php
// IR roundtrip proof-of-concept: hand-build BenchAdd::sum1k() as IR
// nodes, lower to PHP, require the lowered output, run it, assert
// the result matches.
//
// Validates two claims:
//   1. The IR shape can express BenchAdd's post-erasure emit
//      (semantics-preserving expressiveness)
//   2. The lowerer produces runnable PHP (round-trip correctness)
//
// What's NOT validated by this test alone:
//   - Identical-byte output (the lowerer's whitespace differs from
//     the current emit; that's fine — both are semantically equiv)
//   - Coverage of all 9 fixtures (only BenchAdd here; other shapes
//     need their own IR fixtures)

require_once __DIR__ . '/../vendor/autoload.php';
// Node.php declares multiple IR classes in one file; PSR-4 expects
// one-class-per-file, so explicit require here. Production split
// would be one-class-per-file under src/Aot/Ir/.
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';

use PHPJava\Aot\Ir\BasicBlock;
use PHPJava\Aot\Ir\BinOp;
use PHPJava\Aot\Ir\CondGoto;
use PHPJava\Aot\Ir\Goto_;
use PHPJava\Aot\Ir\IincLocal;
use PHPJava\Aot\Ir\IntLit;
use PHPJava\Aot\Ir\LocalRead;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Method;
use PHPJava\Aot\Ir\Module;
use PHPJava\Aot\Ir\Return_;
use PHPJava\Aot\Ir\StoreLocal;

// Hand-build the IR for BenchAdd::sum1k() at its post-peephole shape:
//   public static function sum1k() {
//     $L = [0, 0];
//     $stack = []; $sp = 0;
//     L_0: $L[0] = 0; $L[1] = 0;
//     L_4: if ($L[1] >= 1000) goto L_21;
//          $L[0] = $L[0] + $L[1];
//          $L[1] += 1;
//          goto L_4;
//     L_21: return $L[0];
//   }

$entryBlock = new BasicBlock(0, [
    new StoreLocal(0, new IntLit(0)),
    new StoreLocal(1, new IntLit(0)),
], new Goto_(4));

$loopHeader = new BasicBlock(4, [], new CondGoto(
    new BinOp('>=', new LocalRead(1), new IntLit(1000)),
    21, // taken — exit
    11, // fall-through — body (no real PC; just unique entry)
));

$loopBody = new BasicBlock(11, [
    new StoreLocal(0, new BinOp('+', new LocalRead(0), new LocalRead(1))),
    new IincLocal(1, 1),
], new Goto_(4));

$exitBlock = new BasicBlock(21, [], new Return_(new LocalRead(0)));

$method = new Method(
    name: 'sum1k',
    descriptor: '()I',
    isStatic: true,
    params: [],
    maxLocals: 2,
    blocks: [
        0  => $entryBlock,
        4  => $loopHeader,
        11 => $loopBody,
        21 => $exitBlock,
    ],
);

$module = new Module(
    namespace: 'PHPJava\\Aot\\Ir\\Generated',
    className: 'BenchAddIR',
    methods: [$method],
);

$lowered = (new Lowerer())->lowerModule($module);

echo "=== Lowered PHP ===\n";
echo $lowered;

$tmp = sys_get_temp_dir() . '/BenchAddIR.php';
file_put_contents($tmp, $lowered);
require_once $tmp;

$result = \PHPJava\Aot\Ir\Generated\BenchAddIR::sum1k();
echo "\n=== Result ===\n";
echo "BenchAddIR::sum1k() = {$result}\n";
echo "Expected:             499500\n";
$pass = $result === 499500;
echo $pass ? "PASS — IR roundtrip works\n" : "FAIL\n";
exit($pass ? 0 : 1);
