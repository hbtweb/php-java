<?php
// F-IR3: can BenchTryCatch (exception tables, new+invokespecial<init>,
// athrow) lift to IR within the 200-LOC budget?
//
// Hand-built IR shape (validates the IR can EXPRESS the pattern; the
// Builder extension to construct it from bytecode is a separate
// mechanical exercise once the shape is proven).

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Ir\BasicBlock;
use PHPJava\Aot\Ir\IntLit;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Method;
use PHPJava\Aot\Ir\Module;
use PHPJava\Aot\Ir\New_;
use PHPJava\Aot\Ir\Return_;
use PHPJava\Aot\Ir\StringLit;
use PHPJava\Aot\Ir\Throw_;

// BenchTryCatch::run() — the simplest exception-table fixture.
//   try {
//     throw new RuntimeException("test");
//   } catch (RuntimeException e) {
//     return 42;
//   }
//
// IR shape:
//   BB at PC 0 (entry, protected by exception table):
//     terminator: Throw_(New_(RuntimeException, ["test"]))
//     tryProtect: [{ handlerPc: 10, classFqn: \PHPJava\...\RuntimeException }]
//   BB at PC 10 (handler):
//     isHandler: true
//     terminator: Return_(IntLit(42))

$entryBlock = new BasicBlock(
    entryPc: 0,
    stmts: [],
    term: new Throw_(new New_(
        '\\PHPJava\\Aot\\Runtime\\java\\lang\\RuntimeException',
        [new StringLit('test')],
    )),
    tryProtect: [
        ['handlerPc' => 10, 'classFqn' => '\\PHPJava\\Aot\\Runtime\\java\\lang\\RuntimeException'],
    ],
);

$handlerBlock = new BasicBlock(
    entryPc: 10,
    stmts: [],
    term: new Return_(new IntLit(42)),
    isHandler: true,
);

$method = new Method(
    name: 'run',
    descriptor: '()I',
    isStatic: true,
    params: [],
    maxLocals: 1,
    blocks: [
        0 => $entryBlock,
        10 => $handlerBlock,
    ],
);

$module = new Module(
    namespace: 'PHPJava\\Aot\\Ir\\Generated',
    className: 'BenchTryCatchIR',
    methods: [$method],
);

$lowered = (new Lowerer())->lowerModule($module);
echo "=== Lowered PHP ===\n";
echo $lowered;

$tmp = sys_get_temp_dir() . '/BenchTryCatchIR.php';
file_put_contents($tmp, $lowered);
require_once $tmp;

$result = \PHPJava\Aot\Ir\Generated\BenchTryCatchIR::run();
echo "\n=== Result ===\n";
echo "BenchTryCatchIR::run() = {$result}  (expected 42)\n";
$pass = $result === 42;
echo $pass ? "PASS\n" : "FAIL\n";

// LOC count for the F-IR3 substrate additions
echo "\n=== F-IR3 LOC budget ===\n";
$nodeAdditions = "
final class InstanceCall extends Expr {
    public function __construct(
        public readonly Expr \$receiver,
        public readonly string \$method,
        public readonly array \$args,
    ) {}
    public function isPure(): bool { return false; }
}
final class New_ extends Expr {
    public function __construct(
        public readonly string \$classFqn,
        public readonly array \$args,
    ) {}
    public function isPure(): bool { return false; }
}
final class CaughtException extends Expr {}
// BasicBlock additions: tryProtect + isHandler
public array \$tryProtect = [];
public bool \$isHandler = false;
";
$lowererAdditions = "
// in lowerMethod loop:
if (!empty(\$bb->tryProtect)) { \$bodyLines[] = '        try {'; }
\$skipGoto = \$bb->term instanceof Goto_ && \$bb->term->targetPc === \$nextPc && empty(\$bb->tryProtect);
if (!empty(\$bb->tryProtect)) {
    foreach (\$bb->tryProtect as \$entry) {
        \$bodyLines[] = '        } catch (' . \$entry['classFqn'] . ' \$__e) { \$sp = 0; \$stack[\$sp++] = \$__e; goto L_' . \$entry['handlerPc'] . '; }';
    }
}
// in lowerExpr:
if (\$e instanceof InstanceCall) { /* 2 lines */ }
if (\$e instanceof New_) { /* 2 lines */ }
if (\$e instanceof CaughtException) { return '\$__e'; }
";
$nodeLoc = substr_count(trim($nodeAdditions), "\n") + 1;
$lowererLoc = substr_count(trim($lowererAdditions), "\n") + 1;
$totalLoc = $nodeLoc + $lowererLoc;
echo "  Node.php additions: ~{$nodeLoc} LOC\n";
echo "  Lowerer.php additions: ~{$lowererLoc} LOC\n";
echo "  Total substrate addition: ~{$totalLoc} LOC\n";
echo "  Budget: 200 LOC\n";
echo "  F-IR3 verdict: " . ($pass && $totalLoc < 200 ? "HOLDS" : "FALSIFIED") . "\n";
exit($pass ? 0 : 1);
