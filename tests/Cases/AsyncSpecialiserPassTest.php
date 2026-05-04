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

    public function testStoreLocalBetweenDoesNotCollapse(): void
    {
        // StoreLocal(0, supplyAsync(...)); ExprStmt(LocalRead(0)->get())
        $supplyCall = new StaticCall(self::CF_FQN, 'supplyAsync', [new LocalRead(1)]);
        $store = new StoreLocal(0, $supplyCall);
        $getCall = new InstanceCall(new LocalRead(0), 'get', []);
        $bb = new BasicBlock(0, [$store, new ExprStmt($getCall)], new Return_(null));

        $module = $this->pass($this->moduleWith($bb));
        $stmts = $module->methods[0]->blocks[0]->stmts;
        // First stmt unchanged (StoreLocal of supplyAsync)
        $this->assertSame($store, $stmts[0]);
        // Second stmt: get() on LocalRead — NOT collapsible
        $this->assertInstanceOf(InstanceCall::class, $stmts[1]->expr);
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
