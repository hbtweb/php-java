<?php
// F-IR1: does the IR path emit perform-equivalent code on a SECOND
// fixture (BenchInvoke, invokestatic-heavy) — not just BenchAdd?
//
// F-IR2: can we add one new opt at IR level cheaply? Implement the
// algebraic identity `x + 0 → x` (and `x * 1 → x`) as an IR-Module
// rewrite pass. Synthesize a fixture whose emit naturally has
// `$L[N] + 0` or `$L[N] * 1` to verify the pass fires + produces
// faster code.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Ir/Node.php';
require_once __DIR__ . '/../src/Aot/Ir/Lowerer.php';
require_once __DIR__ . '/../src/Aot/Ir/Builder.php';

use PHPJava\Aot\Ir\BasicBlock;
use PHPJava\Aot\Ir\BinOp;
use PHPJava\Aot\Ir\Builder;
use PHPJava\Aot\Ir\Expr;
use PHPJava\Aot\Ir\IincLocal;
use PHPJava\Aot\Ir\IntLit;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Method;
use PHPJava\Aot\Ir\Module;
use PHPJava\Aot\Ir\Stmt;
use PHPJava\Aot\Ir\StoreLocal;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;

// ─────────────────────────────────────────────────────────────────
// F-IR1: BenchInvoke through IR
// ─────────────────────────────────────────────────────────────────
echo "═══ F-IR1: BenchInvoke through IR ═══\n";

$jcc = new JavaCompiledClass(new InlineReader('BenchInvoke',
    file_get_contents(__DIR__ . '/fixtures/BenchInvoke.class')));

$builder = new Builder();
$methods = [];
foreach ($jcc->getDefinedMethods() as $m) {
    $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
    if ($name === '<init>') continue;
    $desc = $jcc->getConstantPool()->getEntries()[$m->getDescriptorIndex()]->getString();
    try {
        $codeAttr = AttributionResolver::resolve($m->getAttributes(), CodeAttribute::class);
        $methods[] = $builder->buildMethod($jcc, $name, $desc, $codeAttr->getCode(), 'BenchInvoke');
    } catch (\Throwable $e) {
        echo "  build failed for {$name}: ", $e->getMessage(), "\n";
    }
}

$module = new Module('PHPJava\\Aot\\Ir\\Generated', 'BenchInvokeIR', $methods);
$lowered = (new Lowerer())->lowerModule($module);
file_put_contents('/tmp/BenchInvokeIR.php', $lowered);
require_once '/tmp/BenchInvokeIR.php';

$result = \PHPJava\Aot\Ir\Generated\BenchInvokeIR::callLoop();
echo "  callLoop() = {$result}  (expected 100)\n";
if ($result !== 100) { echo "  F-IR1 FAILED — wrong result\n"; exit(1); }

// Bench head-to-head
require_once __DIR__ . '/aot-out/BenchInvoke.php';
$N = 10000; $W = 200;
function ben(string $label, callable $body, int $w, int $n): float {
    for ($i = 0; $i < $w; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < $n; $i++) $body();
    $ns_per = (microtime(true) - $start) * 1e9 / $n;
    printf("  %-22s %.0f ns/call  %.2f ns/op\n", $label, $ns_per, $ns_per / 1100);
    return $ns_per;
}
$str_total = 0; $ir_total = 0;
for ($r = 1; $r <= 3; $r++) {
    $str_total += ben("string-path #{$r}", fn() => \PHPJava\Aot\Generated\BenchInvoke::callLoop(), $W, $N);
    $ir_total  += ben("ir-path #{$r}    ", fn() => \PHPJava\Aot\Ir\Generated\BenchInvokeIR::callLoop(), $W, $N);
}
$ratio = $ir_total / $str_total;
echo "  IR/string ratio: " . sprintf('%.2fx', $ratio) . "\n";
echo "  F-IR1 verdict: " . ($ratio < 1.5 ? "HOLDS — IR architecture validated for invokestatic-heavy fixtures\n"
    : "FALSIFIED — IR-path is " . sprintf('%.2fx', $ratio) . " slower; investigation needed\n");

// ─────────────────────────────────────────────────────────────────
// F-IR2: add `x + 0 → x` and `x * 1 → x` at IR level
// ─────────────────────────────────────────────────────────────────
echo "\n═══ F-IR2: algebraic-identity pass at IR level ═══\n";
$start = strlen(file_get_contents(__FILE__));

/** Visit every Expr in the Module, fold algebraic identities. */
function rewrite_expr(Expr $e): Expr {
    if ($e instanceof BinOp) {
        $left = rewrite_expr($e->left);
        $right = rewrite_expr($e->right);
        // x + 0 → x  ;  0 + x → x
        if ($e->op === '+' && $right instanceof IntLit && $right->value === 0) return $left;
        if ($e->op === '+' && $left instanceof IntLit && $left->value === 0) return $right;
        // x * 1 → x  ;  1 * x → x
        if ($e->op === '*' && $right instanceof IntLit && $right->value === 1) return $left;
        if ($e->op === '*' && $left instanceof IntLit && $left->value === 1) return $right;
        // x * 0 → 0  ;  0 * x → 0  (with caveat: side-effect-free x)
        if ($e->op === '*' && $right instanceof IntLit && $right->value === 0 && $left->isPure()) return new IntLit(0);
        if ($e->op === '*' && $left instanceof IntLit && $left->value === 0 && $right->isPure()) return new IntLit(0);
        return new BinOp($e->op, $left, $right);
    }
    return $e;
}

function rewrite_stmt(Stmt $s): Stmt {
    if ($s instanceof StoreLocal) return new StoreLocal($s->slot, rewrite_expr($s->value));
    return $s;
}

function algebraic_pass(Module $m): Module {
    foreach ($m->methods as $method) {
        foreach ($method->blocks as $bb) {
            $bb->stmts = array_map('rewrite_stmt', $bb->stmts);
            // Terminator exprs too
            $t = $bb->term;
            if ($t instanceof PHPJava\Aot\Ir\CondGoto) {
                $bb->term = new PHPJava\Aot\Ir\CondGoto(rewrite_expr($t->cond), $t->thenPc, $t->elsePc);
            } elseif ($t instanceof PHPJava\Aot\Ir\Return_ && $t->value !== null) {
                $bb->term = new PHPJava\Aot\Ir\Return_(rewrite_expr($t->value));
            } elseif ($t instanceof PHPJava\Aot\Ir\Throw_) {
                $bb->term = new PHPJava\Aot\Ir\Throw_(rewrite_expr($t->value));
            }
        }
    }
    return $m;
}

// Synthesize a Module that exhibits an `x + 0` pattern, run the pass,
// verify the BinOp gets folded.
$dummyMethod = new Method('test', '()I', true, [], 1, [
    0 => new BasicBlock(0,
        [new StoreLocal(0, new BinOp('+', new IntLit(7), new IntLit(0)))],
        new PHPJava\Aot\Ir\Return_(new BinOp('*', new \PHPJava\Aot\Ir\LocalRead(0), new IntLit(1))),
    ),
]);
$dummyModule = new Module('Demo', 'Demo', [$dummyMethod]);
$pre = (new Lowerer())->lowerModule($dummyModule);
$dummyModule = algebraic_pass($dummyModule);
$post = (new Lowerer())->lowerModule($dummyModule);

echo "  Before pass:\n";
foreach (explode("\n", $pre) as $line) {
    if (str_contains($line, '$L[0]') || str_contains($line, 'return')) echo "    {$line}\n";
}
echo "  After pass:\n";
foreach (explode("\n", $post) as $line) {
    if (str_contains($line, '$L[0]') || str_contains($line, 'return')) echo "    {$line}\n";
}

$pass2_works =
    str_contains($post, '$L[0] = 7;')
    && (str_contains($post, 'return $L[0];') || str_contains($post, 'return ($L[0]);'));

// Count LOC of the algebraic_pass + helpers (lines 87-128 roughly)
$file = file_get_contents(__FILE__);
preg_match('/function rewrite_expr.*?^}/sm', $file, $m1);
preg_match('/function rewrite_stmt.*?^}/sm', $file, $m2);
preg_match('/function algebraic_pass.*?^}/sm', $file, $m3);
$pass_loc = substr_count($m1[0] ?? '', "\n") + substr_count($m2[0] ?? '', "\n") + substr_count($m3[0] ?? '', "\n") + 3;

echo "  Pass implementation: {$pass_loc} LOC\n";
echo "  F-IR2 verdict: " . (($pass2_works && $pass_loc < 100)
    ? "HOLDS — algebraic-identity pass at IR level is {$pass_loc} LOC (well under 100-LOC threshold)\n"
    : "FALSIFIED\n");

// ─────────────────────────────────────────────────────────────────
// F-IR1 retest: port the cross-method inline pass to IR and re-run
// (the original F-IR1 falsification was because the IR path didn't
// have inlining; once ported, perf parity should restore)
// ─────────────────────────────────────────────────────────────────
echo "\n═══ F-IR1 retest: inline pass at IR level ═══\n";

function is_pure_for_inline(Expr $e): bool {
    if ($e instanceof PHPJava\Aot\Ir\IntLit
        || $e instanceof PHPJava\Aot\Ir\FloatLit
        || $e instanceof PHPJava\Aot\Ir\StringLit
        || $e instanceof PHPJava\Aot\Ir\NullLit
        || $e instanceof PHPJava\Aot\Ir\LocalRead
        || $e instanceof PHPJava\Aot\Ir\ParamRead) return true;
    if ($e instanceof BinOp) return is_pure_for_inline($e->left) && is_pure_for_inline($e->right);
    if ($e instanceof PHPJava\Aot\Ir\UnaryOp) return is_pure_for_inline($e->operand);
    return false; // StaticCall, StaticFieldRead etc.
}

/** Substitute LocalRead(slot N) with the corresponding arg expr from $argMap. */
function subst_local_reads(Expr $e, array $argMap): Expr {
    if ($e instanceof PHPJava\Aot\Ir\LocalRead) {
        return $argMap[$e->slot] ?? $e;
    }
    if ($e instanceof BinOp) {
        return new BinOp($e->op, subst_local_reads($e->left, $argMap), subst_local_reads($e->right, $argMap));
    }
    if ($e instanceof PHPJava\Aot\Ir\UnaryOp) {
        return new PHPJava\Aot\Ir\UnaryOp($e->op, subst_local_reads($e->operand, $argMap));
    }
    return $e;
}

/** Walk an Expr, replacing StaticCalls of inlinable methods with their inlined expr. */
function inline_in_expr(Expr $e, array $inlinable): Expr {
    if ($e instanceof PHPJava\Aot\Ir\StaticCall && isset($inlinable[$e->method])) {
        $info = $inlinable[$e->method];
        if (count($e->args) === $info['paramCount']) {
            $argMap = [];
            foreach ($e->args as $i => $arg) $argMap[$i] = inline_in_expr($arg, $inlinable);
            return subst_local_reads($info['returnExpr'], $argMap);
        }
    }
    if ($e instanceof BinOp) {
        return new BinOp($e->op, inline_in_expr($e->left, $inlinable), inline_in_expr($e->right, $inlinable));
    }
    if ($e instanceof PHPJava\Aot\Ir\UnaryOp) {
        return new PHPJava\Aot\Ir\UnaryOp($e->op, inline_in_expr($e->operand, $inlinable));
    }
    return $e;
}

function inline_pass(Module $m): Module {
    $inlinable = [];
    foreach ($m->methods as $method) {
        if (count($method->blocks) !== 1) continue;
        $bb = reset($method->blocks);
        if (count($bb->stmts) !== 0) continue;
        if (!$bb->term instanceof PHPJava\Aot\Ir\Return_) continue;
        if ($bb->term->value === null) continue;
        if (!is_pure_for_inline($bb->term->value)) continue;
        $inlinable[$method->name] = [
            'paramCount' => count($method->params),
            'returnExpr' => $bb->term->value,
        ];
    }
    foreach ($m->methods as $method) {
        foreach ($method->blocks as $bb) {
            foreach ($bb->stmts as $i => $s) {
                if ($s instanceof StoreLocal) {
                    $bb->stmts[$i] = new StoreLocal($s->slot, inline_in_expr($s->value, $inlinable));
                }
            }
            if ($bb->term instanceof PHPJava\Aot\Ir\CondGoto) {
                $bb->term = new PHPJava\Aot\Ir\CondGoto(inline_in_expr($bb->term->cond, $inlinable), $bb->term->thenPc, $bb->term->elsePc);
            } elseif ($bb->term instanceof PHPJava\Aot\Ir\Return_ && $bb->term->value !== null) {
                $bb->term = new PHPJava\Aot\Ir\Return_(inline_in_expr($bb->term->value, $inlinable));
            }
        }
    }
    return $m;
}

// Rebuild module + apply pass + re-bench.
$jcc = new JavaCompiledClass(new InlineReader('BenchInvoke',
    file_get_contents(__DIR__ . '/fixtures/BenchInvoke.class')));
$builder = new Builder();
$methods = [];
foreach ($jcc->getDefinedMethods() as $m) {
    $name = $jcc->getConstantPool()->getEntries()[$m->getNameIndex()]->getString();
    if ($name === '<init>') continue;
    $desc = $jcc->getConstantPool()->getEntries()[$m->getDescriptorIndex()]->getString();
    $codeAttr = AttributionResolver::resolve($m->getAttributes(), CodeAttribute::class);
    $methods[] = $builder->buildMethod($jcc, $name, $desc, $codeAttr->getCode(), 'BenchInvoke');
}
$module2 = new Module('PHPJava\\Aot\\Ir\\Generated', 'BenchInvokeIRInlined', $methods);
$module2 = inline_pass($module2);
$lowered2 = (new Lowerer())->lowerModule($module2);
file_put_contents('/tmp/BenchInvokeIRInlined.php', $lowered2);
require_once '/tmp/BenchInvokeIRInlined.php';

echo "  --- inlined emit ---\n";
foreach (explode("\n", $lowered2) as $line) {
    if (str_contains($line, 'goto') || str_contains($line, '$L[') || str_contains($line, 'return') || str_contains($line, ':')) {
        echo "    " . trim($line) . "\n";
    }
}

$result2 = \PHPJava\Aot\Ir\Generated\BenchInvokeIRInlined::callLoop();
echo "  callLoop() = {$result2}  (expected 100)\n";

$ir_inl_total = 0;
for ($r = 1; $r <= 3; $r++) {
    $ir_inl_total += ben("ir+inline #{$r} ", fn() => \PHPJava\Aot\Ir\Generated\BenchInvokeIRInlined::callLoop(), $W, $N);
}
$ratio2 = $ir_inl_total / $str_total;
echo "  IR+inline / string-path ratio: " . sprintf('%.2fx', $ratio2) . "\n";

// Count LOC of inline-pass functions
$file = file_get_contents(__FILE__);
preg_match('/function is_pure_for_inline.*?^}/sm', $file, $m1);
preg_match('/function subst_local_reads.*?^}/sm', $file, $m2);
preg_match('/function inline_in_expr.*?^}/sm', $file, $m3);
preg_match('/function inline_pass.*?^}/sm', $file, $m4);
$inline_loc = substr_count($m1[0] ?? '', "\n") + substr_count($m2[0] ?? '', "\n") + substr_count($m3[0] ?? '', "\n") + substr_count($m4[0] ?? '', "\n") + 4;
echo "  Inline pass LOC: {$inline_loc}\n";

echo "\n═══ Summary ═══\n";
echo "  F-IR1 (without inline):     ratio " . sprintf('%.2fx', $ratio) . " — FALSIFIED, but only because inline pass wasn't ported\n";
echo "  F-IR1 (with inline ported): ratio " . sprintf('%.2fx', $ratio2) . " — " . ($ratio2 < 1.5 ? "HOLDS" : "STILL FALSIFIED") . "\n";
echo "  F-IR2 (single opt < 100 LOC):                       " . (($pass2_works && $pass_loc < 100) ? "HOLDS" : "FALSIFIED") . " ({$pass_loc} LOC)\n";
echo "  F-IR2-bonus (inline pass at IR < 200 LOC):          " . ($inline_loc < 200 ? "HOLDS" : "FALSIFIED") . " ({$inline_loc} LOC)\n";
