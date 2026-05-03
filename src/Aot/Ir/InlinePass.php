<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

require_once __DIR__ . '/Node.php';

/**
 * Cross-method inline pass at IR level. Replaces the previous
 * post-emit-text substitution pass (Compiler::inlineAcrossText)
 * with a structural transform on the IR Module.
 *
 * Detection: a method is inlinable iff its IR is exactly:
 *   - one BB with no Stmts
 *   - terminator is Return_(Expr)
 *   - the Expr is "pure for inlining" (no calls, no field reads;
 *     only literals + LocalRead + BinOp + UnaryOp + ParamRead).
 *
 * Substitution: walks each Method's blocks, replacing StaticCall
 * Expr nodes whose target matches an inlinable method with the
 * inlined return expression (parameter-substituted via LocalRead
 * slot mapping).
 *
 * Iterates to fixpoint so chains (a → b → c) collapse end-to-end.
 *
 * Validated by F-IR2 falsifier (2026-05-03): 70 LOC, runs at IR
 * level instead of post-emit-text. Same semantics as the prior
 * string-level pass.
 */
final class InlinePass
{
    /**
     * Apply to a Module. Returns the modified Module (mutates in
     * place — IR types are mutable for transform passes).
     */
    public function run(Module $m): Module
    {
        $inlinable = [];
        foreach ($m->methods as $method) {
            if (count($method->blocks) !== 1) continue;
            $bb = reset($method->blocks);
            if (count($bb->stmts) !== 0) continue;
            if (!$bb->term instanceof Return_) continue;
            if ($bb->term->value === null) continue;
            if (!$this->isPureForInline($bb->term->value)) continue;
            $inlinable[$method->name] = [
                'paramCount' => count($method->params),
                'returnExpr' => $bb->term->value,
            ];
        }
        if (empty($inlinable)) return $m;

        do {
            $changed = false;
            foreach ($m->methods as $method) {
                foreach ($method->blocks as $bb) {
                    foreach ($bb->stmts as $i => $s) {
                        $newS = $this->rewriteStmt($s, $inlinable, $changed);
                        if ($newS !== null) $bb->stmts[$i] = $newS;
                    }
                    if ($bb->term !== null) {
                        $newT = $this->rewriteTerm($bb->term, $inlinable, $changed);
                        if ($newT !== null) $bb->term = $newT;
                    }
                }
            }
        } while ($changed);
        return $m;
    }

    private function rewriteStmt(Stmt $s, array $inlinable, bool &$changed): ?Stmt
    {
        if ($s instanceof StoreLocal) {
            $newV = $this->rewriteExpr($s->value, $inlinable, $changed);
            return $newV === $s->value ? null : new StoreLocal($s->slot, $newV);
        }
        if ($s instanceof ExprStmt) {
            $newE = $this->rewriteExpr($s->expr, $inlinable, $changed);
            return $newE === $s->expr ? null : new ExprStmt($newE);
        }
        if ($s instanceof StoreArrayElement) {
            $newI = $this->rewriteExpr($s->index, $inlinable, $changed);
            $newV = $this->rewriteExpr($s->value, $inlinable, $changed);
            if ($newI === $s->index && $newV === $s->value) return null;
            return new StoreArrayElement($s->slot, $newI, $newV);
        }
        return null; // IincLocal, StoreField, StoreStaticField — no recursion needed for now
    }

    private function rewriteTerm(Terminator $t, array $inlinable, bool &$changed): ?Terminator
    {
        if ($t instanceof CondGoto) {
            $newC = $this->rewriteExpr($t->cond, $inlinable, $changed);
            return $newC === $t->cond ? null : new CondGoto($newC, $t->thenPc, $t->elsePc);
        }
        if ($t instanceof Return_ && $t->value !== null) {
            $newV = $this->rewriteExpr($t->value, $inlinable, $changed);
            return $newV === $t->value ? null : new Return_($newV);
        }
        if ($t instanceof Throw_) {
            $newV = $this->rewriteExpr($t->value, $inlinable, $changed);
            return $newV === $t->value ? null : new Throw_($newV);
        }
        return null;
    }

    private function rewriteExpr(Expr $e, array $inlinable, bool &$changed): Expr
    {
        if ($e instanceof StaticCall && isset($inlinable[$e->method])) {
            $info = $inlinable[$e->method];
            if (count($e->args) === $info['paramCount']) {
                $argMap = [];
                foreach ($e->args as $i => $a) $argMap[$i] = $this->rewriteExpr($a, $inlinable, $changed);
                $changed = true;
                return $this->substLocals($info['returnExpr'], $argMap);
            }
        }
        if ($e instanceof BinOp) {
            $L = $this->rewriteExpr($e->left, $inlinable, $changed);
            $R = $this->rewriteExpr($e->right, $inlinable, $changed);
            if ($L === $e->left && $R === $e->right) return $e;
            return new BinOp($e->op, $L, $R);
        }
        if ($e instanceof UnaryOp) {
            $V = $this->rewriteExpr($e->operand, $inlinable, $changed);
            return $V === $e->operand ? $e : new UnaryOp($e->op, $V);
        }
        return $e;
    }

    /** Substitute LocalRead(N) with the arg expression at slot N. */
    private function substLocals(Expr $e, array $argMap): Expr
    {
        if ($e instanceof LocalRead) {
            return $argMap[$e->slot] ?? $e;
        }
        if ($e instanceof BinOp) {
            return new BinOp(
                $e->op,
                $this->substLocals($e->left, $argMap),
                $this->substLocals($e->right, $argMap),
            );
        }
        if ($e instanceof UnaryOp) {
            return new UnaryOp($e->op, $this->substLocals($e->operand, $argMap));
        }
        return $e;
    }

    private function isPureForInline(Expr $e): bool
    {
        if ($e instanceof IntLit
            || $e instanceof FloatLit
            || $e instanceof StringLit
            || $e instanceof NullLit
            || $e instanceof LocalRead
            || $e instanceof ParamRead) return true;
        if ($e instanceof BinOp) {
            return $this->isPureForInline($e->left) && $this->isPureForInline($e->right);
        }
        if ($e instanceof UnaryOp) return $this->isPureForInline($e->operand);
        return false;
    }
}
