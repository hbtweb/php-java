<?php
// Profile the IR pipeline: split build from lower, measure each.
// Identifies the ~19 µs per method into actionable phases.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Builder.php';
require_once __DIR__ . '/../src/Aot/Ir/ArrayHelper.php';

use PHPJava\Aot\Ir\Builder;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Module;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;

$bytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');
$jcc = new JavaCompiledClass(new InlineReader('B', $bytes));
$method = null;
foreach ($jcc->getDefinedMethods() as $m) {
    $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
    if ($name === 'sum1k') { $method = $m; break; }
}
$desc = $jcc->getConstantPool()->getEntries()[$method->getDescriptorIndex()]->getString();
$codeAttr = AttributionResolver::resolve($method->getAttributes(), CodeAttribute::class);
$code = $codeAttr->getCode();
$exTables = $codeAttr->getExceptionTables();

const N = 5000;
const W = 500;

// ── Phase 1: build only ────────────────────────────────────────
$builder = new Builder();
$ir = null;
for ($i = 0; $i < W; $i++) $ir = $builder->buildMethod($jcc, 'sum1k', $desc, $code, 'BenchAdd', $exTables);
$start = microtime(true);
for ($i = 0; $i < N; $i++) $ir = $builder->buildMethod($jcc, 'sum1k', $desc, $code, 'BenchAdd', $exTables);
$build_ns = (microtime(true) - $start) * 1e9 / N;

// ── Phase 2: lower only (build once, lower N times) ────────────
$module = new Module('Demo', 'Demo', [$ir]);
$lowerer = new Lowerer();
for ($i = 0; $i < W; $i++) $lowerer->lowerModule($module);
$start = microtime(true);
for ($i = 0; $i < N; $i++) $lowerer->lowerModule($module);
$lower_ns = (microtime(true) - $start) * 1e9 / N;

// ── Phase 3: BB-traversal cost (inside Lowerer) ────────────────
// Count how many BBs/Stmts/Expr-nodes we're processing per method.
$bbCount = count($ir->blocks);
$stmtCount = 0; $exprCount = 0;
foreach ($ir->blocks as $bb) {
    $stmtCount += count($bb->stmts);
    foreach ($bb->stmts as $s) $exprCount += count_exprs_in_stmt($s);
    if ($bb->term) $exprCount += count_exprs_in_term($bb->term);
}

function count_exprs_in_stmt($s): int {
    if ($s instanceof PHPJava\Aot\Ir\StoreLocal) return count_exprs($s->value);
    if ($s instanceof PHPJava\Aot\Ir\IincLocal) return 0;
    if ($s instanceof PHPJava\Aot\Ir\ExprStmt) return count_exprs($s->expr);
    return 0;
}
function count_exprs_in_term($t): int {
    if ($t instanceof PHPJava\Aot\Ir\Goto_) return 0;
    if ($t instanceof PHPJava\Aot\Ir\CondGoto) return count_exprs($t->cond);
    if ($t instanceof PHPJava\Aot\Ir\Return_ && $t->value) return count_exprs($t->value);
    if ($t instanceof PHPJava\Aot\Ir\Throw_) return count_exprs($t->value);
    return 0;
}
function count_exprs($e): int {
    $c = 1;
    if ($e instanceof PHPJava\Aot\Ir\BinOp) $c += count_exprs($e->left) + count_exprs($e->right);
    elseif ($e instanceof PHPJava\Aot\Ir\UnaryOp) $c += count_exprs($e->operand);
    elseif ($e instanceof PHPJava\Aot\Ir\StaticCall || $e instanceof PHPJava\Aot\Ir\InstanceCall || $e instanceof PHPJava\Aot\Ir\New_) {
        foreach ($e->args ?? [] as $a) $c += count_exprs($a);
        if (isset($e->receiver)) $c += count_exprs($e->receiver);
    }
    return $c;
}

echo "PHP " . PHP_VERSION . " | jit=" . (ini_get('opcache.jit') ?: 'off')
   . " | buffer=" . ini_get('opcache.jit_buffer_size') . "\n";
echo "Method: BenchAdd::sum1k() — {$bbCount} BBs, {$stmtCount} stmts, {$exprCount} expr nodes\n\n";

echo str_pad('Phase', 24) . "ns/call    µs/call   ns/expr-node\n";
echo str_repeat('-', 60) . "\n";
printf("%-24s%9.0f  %7.2f   %7.0f\n", 'Build (bytecode → IR)', $build_ns, $build_ns / 1000, $build_ns / max(1,$exprCount));
printf("%-24s%9.0f  %7.2f   %7.0f\n", 'Lower (IR → PHP src)',  $lower_ns, $lower_ns / 1000, $lower_ns / max(1,$exprCount));
printf("%-24s%9.0f  %7.2f\n", 'Total (build+lower)',   $build_ns + $lower_ns, ($build_ns + $lower_ns) / 1000);

echo "\n10× target: " . sprintf('%.0f ns/call', ($build_ns + $lower_ns) / 10) . "\n";
echo "Current ratio: 1×; need to get to ~" . sprintf('%.2fx', ($build_ns + $lower_ns) / 1900) . " of current\n";
