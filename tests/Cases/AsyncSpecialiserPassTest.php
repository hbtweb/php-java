<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

use PHPJava\Aot\Ir\AsyncSpecialiserPass;
use PHPJava\Aot\Ir\BasicBlock;
use PHPJava\Aot\Ir\ExprStmt;
use PHPJava\Aot\Ir\InstanceCall;
use PHPJava\Aot\Ir\IntLit;
use PHPJava\Aot\Ir\InvokeCallable;
use PHPJava\Aot\Ir\LocalRead;
use PHPJava\Aot\Ir\Lowerer;
use PHPJava\Aot\Ir\Method;
use PHPJava\Aot\Ir\Module;
use PHPJava\Aot\Ir\Return_;
use PHPJava\Aot\Ir\StaticCall;
use PHPJava\Aot\Ir\StoreLocal;

require_once __DIR__ . '/../../src/Aot/Ir/Node.php';

/**
 * Validates AsyncSpecialiserPass — IR-level peephole that collapses
 * `CompletableFuture::supplyAsync(s)->get()` patterns to direct
 * callable invocation, skipping the runtime executor.
 */
class AsyncSpecialiserPassTest extends \PHPUnit\Framework\TestCase
{
    private const CF_FQN = '\\PHPJava\\Aot\\Runtime\\java\\util\\concurrent\\CompletableFuture';

    private function moduleWith(BasicBlock $bb): Module
    {
        $m = new Method('test', '()V', false, [], 4, [$bb]);
        return new Module('PHPJava\\Test', 'TestCls', [$m]);
    }

    private function pass(Module $m): Module
    {
        return (new AsyncSpecialiserPass())->run($m);
    }

    public function testCollapsesSupplyAsyncGet(): void
    {
        // ExprStmt(InstanceCall(StaticCall(CF, supplyAsync, [LocalRead(0)]), get, []))
        $supplier = new LocalRead(0);
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [$supplier]);
        $getCall = new InstanceCall($supplyCall, 'get', []);
        $bb = new BasicBlock(0, [new ExprStmt($getCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertInstanceOf(ExprStmt::class, $stmt);
        $this->assertInstanceOf(InvokeCallable::class, $stmt->expr);
        $this->assertSame($supplier, $stmt->expr->callable);
        $this->assertSame([], $stmt->expr->args);
    }

    public function testCollapsesRunAsyncJoin(): void
    {
        $runnable = new LocalRead(2);
        $runCall = new StaticCall(self::CF_FQN, 'runAsync', [$runnable]);
        $joinCall = new InstanceCall($runCall, 'join', []);
        $bb = new BasicBlock(0, [new ExprStmt($joinCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertInstanceOf(InvokeCallable::class, $stmt->expr);
        $this->assertSame($runnable, $stmt->expr->callable);
    }

    public function testNoEscapeStoreLocalThenGetCollapses(): void
    {
        // var cf = CF::supplyAsync($s);  cf.get();
        // → store the supplier directly, invoke at the .get() site.
        $supplier = new LocalRead(1);
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [$supplier]);
        $store = new StoreLocal(0, $supplyCall);
        $getCall = new InstanceCall(new LocalRead(0), 'get', []);
        $bb = new BasicBlock(0, [$store, new ExprStmt($getCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmts = $module->methods[0]->blocks[0]->stmts;

        // First stmt: StoreLocal now holds the supplier itself (slot 1 read)
        $this->assertInstanceOf(StoreLocal::class, $stmts[0]);
        $this->assertSame(0, $stmts[0]->slot);
        $this->assertSame($supplier, $stmts[0]->value);
        // Second stmt: InvokeCallable on LocalRead(0)
        $this->assertInstanceOf(InvokeCallable::class, $stmts[1]->expr);
        $this->assertInstanceOf(LocalRead::class, $stmts[1]->expr->callable);
        $this->assertSame(0, $stmts[1]->expr->callable->slot);
    }

    public function testNoEscapeWithRunAsyncJoinInTerminator(): void
    {
        // var cf = CF::runAsync($r);  return cf.join();
        $runnable = new LocalRead(2);
        $runCall = new StaticCall(self::CF_FQN, 'runAsync', [$runnable]);
        $store = new StoreLocal(3, $runCall);
        $joinCall = new InstanceCall(new LocalRead(3), 'join', []);
        $bb = new BasicBlock(0, [$store], new Return_($joinCall));

        $module = $this->pass($this->moduleWith($bb));
        $blk = $module->methods[0]->blocks[0];

        $this->assertInstanceOf(StoreLocal::class, $blk->stmts[0]);
        $this->assertSame($runnable, $blk->stmts[0]->value);
        $this->assertInstanceOf(Return_::class, $blk->term);
        $this->assertInstanceOf(InvokeCallable::class, $blk->term->value);
        $this->assertSame(3, $blk->term->value->callable->slot);
    }

    public function testMultipleGetsOnSameSlotDoesNotCollapse(): void
    {
        // var cf = CF::supplyAsync($s);  cf.get();  cf.get();
        // The original Future caches the value; collapsing would
        // re-invoke the supplier on the second .get(). Skip.
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(1)]);
        $store = new StoreLocal(0, $supplyCall);
        $get1 = new InstanceCall(new LocalRead(0), 'get', []);
        $get2 = new InstanceCall(new LocalRead(0), 'get', []);
        $bb = new BasicBlock(0, [$store, new ExprStmt($get1), new ExprStmt($get2)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmts = $module->methods[0]->blocks[0]->stmts;
        // StoreLocal value still the StaticCall — not collapsed
        $this->assertSame($store, $stmts[0]);
        $this->assertInstanceOf(InstanceCall::class, $stmts[1]->expr);
        $this->assertInstanceOf(InstanceCall::class, $stmts[2]->expr);
    }

    public function testEscapeAsCallArgDoesNotCollapse(): void
    {
        // var cf = CF::supplyAsync($s);  someOther($cf);
        // The local escapes — could be passed to allOf / thenApply.
        // Skip.
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(1)]);
        $store = new StoreLocal(0, $supplyCall);
        $escape = new InstanceCall(new LocalRead(2), 'consume', [new LocalRead(0)]);
        $bb = new BasicBlock(0, [$store, new ExprStmt($escape)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmts = $module->methods[0]->blocks[0]->stmts;
        $this->assertSame($store, $stmts[0]);
    }

    public function testRebindOfSlotDoesNotCollapse(): void
    {
        // var cf = CF::supplyAsync($s);  cf = somethingElse;  cf.get();
        // Two stores into slot 0 — analysis bails.
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(1)]);
        $store1 = new StoreLocal(0, $supplyCall);
        $store2 = new StoreLocal(0, new IntLit(42));
        $getCall = new InstanceCall(new LocalRead(0), 'get', []);
        $bb = new BasicBlock(0, [$store1, $store2, new ExprStmt($getCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmts = $module->methods[0]->blocks[0]->stmts;
        // Original supplyAsync StoreLocal preserved
        $this->assertSame($store1, $stmts[0]);
        // .get() not rewritten
        $this->assertInstanceOf(InstanceCall::class, $stmts[2]->expr);
    }

    public function testCrossBlockNoEscapeCollapses(): void
    {
        // BB0: var cf = CF::supplyAsync($s); goto BB1
        // BB1: return cf.get();
        // Method-wide single-use — should collapse across blocks.
        $supplier = new LocalRead(1);
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [$supplier]);
        $store = new StoreLocal(0, $supplyCall);
        $bb0 = new BasicBlock(0, [$store], new \PHPJava\Aot\Ir\Goto_(1));

        $getCall = new InstanceCall(new LocalRead(0), 'get', []);
        $bb1 = new BasicBlock(1, [], new Return_($getCall));

        $method = new Method('test', '()V', false, [], 4, [$bb0, $bb1]);
        $module = new Module('PHPJava\\Test', 'TestCls', [$method]);
        $module = $this->pass($module);

        // BB0 store now holds the supplier
        $this->assertSame($supplier, $module->methods[0]->blocks[0]->stmts[0]->value);
        // BB1 terminator's return value is now InvokeCallable
        $term = $module->methods[0]->blocks[1]->term;
        $this->assertInstanceOf(Return_::class, $term);
        $this->assertInstanceOf(InvokeCallable::class, $term->value);
    }

    public function testGetOnNonAsyncCallNotCollapsed(): void
    {
        // InstanceCall(StaticCall(SomeOther, build, [...]), get, [])
        $other = new StaticCall('\\Some\\Other\\Cls', 'build', [new IntLit(1)]);
        $call = new InstanceCall($other, 'get', []);
        $bb = new BasicBlock(0, [new ExprStmt($call)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertInstanceOf(InstanceCall::class, $stmt->expr);
    }

    public function testSupplyAsyncWithoutAwaitNotCollapsed(): void
    {
        // ExprStmt(StaticCall(CF, supplyAsync, [...])) — no .get() chain
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(0)]);
        $bb = new BasicBlock(0, [new ExprStmt($supplyCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertInstanceOf(StaticCall::class, $stmt->expr);
    }

    public function testTimedGetVariantNotCollapsed(): void
    {
        // InstanceCall(supplyAsync(s), get, [timeout, unit]) — Java's
        // Future.get(timeout, unit) overload. Don't collapse — the
        // semantics include time-bounded waiting.
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(0)]);
        $getTimed = new InstanceCall($supplyCall, 'get', [new IntLit(100), new LocalRead(1)]);
        $bb = new BasicBlock(0, [new ExprStmt($getTimed)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertInstanceOf(InstanceCall::class, $stmt->expr);
    }

    public function testNestedRewriteCollapsesInnerPattern(): void
    {
        // ExprStmt(InstanceCall(other, doSomething, [supplyAsync(s)->get()]))
        // The inner pattern should collapse even though wrapped by
        // an unrelated InstanceCall.
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(0)]);
        $innerGet = new InstanceCall($supplyCall, 'get', []);
        $outer = new InstanceCall(new LocalRead(1), 'doSomething', [$innerGet]);
        $bb = new BasicBlock(0, [new ExprStmt($outer)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $expr = $module->methods[0]->blocks[0]->stmts[0]->expr;
        $this->assertInstanceOf(InstanceCall::class, $expr);
        $this->assertCount(1, $expr->args);
        $this->assertInstanceOf(InvokeCallable::class, $expr->args[0]);
    }

    public function testLowererEmitsInvokeCallable(): void
    {
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(3)]);
        $getCall = new InstanceCall($supplyCall, 'get', []);
        $bb = new BasicBlock(0, [new ExprStmt($getCall)], new Return_(null));
        $module = $this->pass($this->moduleWith($bb));

        $php = (new Lowerer())->lowerMethod($module->methods[0]);
        // The collapsed shape: `($L[3])()` — direct invocation of the
        // local (the supplier). No CompletableFuture::, no get().
        $this->assertStringContainsString('($L[3])()', $php);
        $this->assertStringNotContainsString('CompletableFuture', $php);
        $this->assertStringNotContainsString('->get()', $php);
    }

    public function testLowererEmitsNoEscapeCollapse(): void
    {
        // var cf = CF::supplyAsync($L[5]);  cf.get();
        // → $L[0] = $L[5]; ($L[0])();
        $supplier = new LocalRead(5);
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [$supplier]);
        $store = new StoreLocal(0, $supplyCall);
        $getCall = new InstanceCall(new LocalRead(0), 'get', []);
        $bb = new BasicBlock(0, [$store, new ExprStmt($getCall)], new Return_(null));
        $module = $this->pass($this->moduleWith($bb));

        $php = (new Lowerer())->lowerMethod($module->methods[0]);
        // StoreLocal value rewritten to the supplier itself (slot 5)
        $this->assertStringContainsString('$L[0] = $L[5]', $php);
        // Consuming use rewritten to InvokeCallable on the local
        $this->assertStringContainsString('($L[0])()', $php);
        // No runtime executor surface in the lowered output
        $this->assertStringNotContainsString('CompletableFuture', $php);
        $this->assertStringNotContainsString('supplyAsync', $php);
        $this->assertStringNotContainsString('->get()', $php);
    }

    public function testIdempotentOnAlreadyCollapsedIr(): void
    {
        // InvokeCallable should pass through unchanged.
        $invoke = new InvokeCallable(new LocalRead(0), []);
        $bb = new BasicBlock(0, [new ExprStmt($invoke)], new Return_(null));
        $module = $this->pass($this->moduleWith($bb));
        $stmt = $module->methods[0]->blocks[0]->stmts[0];
        $this->assertSame($invoke, $stmt->expr);
    }
}
