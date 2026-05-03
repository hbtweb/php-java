<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

// Minimal IR for AOT optimisation. PHP data structures so the
// compiler stays single-language (no FFI, no external IR adoption).
//
// The IR is a 3-address-code over basic blocks. Each Method has an
// ordered list of BasicBlocks, each BB has a list of Stmts followed
// by a Terminator. Expressions are deeply nested values; statements
// are the side-effecting points.
//
// Design choices:
//   - Pure-vs-impure distinction at the Expr level. Pure exprs can
//     be reordered, deduplicated, eliminated. Impure exprs (calls,
//     gets that may trigger init) stay in original order.
//   - Slot-based locals (`LocalRead 0`) mirror JVM's L[N] model.
//     SSA can be added as a later pass that renames slots into
//     versioned vars and inserts phi nodes at merges.
//   - Operand stack is NOT represented in the canonical IR — the
//     bytecode-to-IR builder erases it during construction. Stack
//     mode is a fallback for unrepresentable patterns.
//   - Terminators are a closed set: Goto, CondGoto, Return, Throw,
//     Switch. No fall-through — every BB ends explicitly.

abstract class Expr
{
    /** Pure expressions can be reordered/duplicated/removed. */
    public function isPure(): bool { return true; }
}

final class IntLit extends Expr {
    public function __construct(public readonly int $value) {}
}
final class FloatLit extends Expr {
    public function __construct(public readonly float $value) {}
}
final class StringLit extends Expr {
    public function __construct(public readonly string $value) {}
}
final class NullLit extends Expr {}

/** Read of JVM local slot N. Pure (slot reads have no side effects). */
final class LocalRead extends Expr {
    public function __construct(public readonly int $slot) {}
}

/** Read of method parameter `$__aN` (used pre-prelude in some emits). */
final class ParamRead extends Expr {
    public function __construct(public readonly int $idx) {}
}

/** Binary op. `op` is the PHP operator string ('+', '-', '<', '===', etc). */
final class BinOp extends Expr {
    public function __construct(
        public readonly string $op,
        public readonly Expr $left,
        public readonly Expr $right,
    ) {}
    public function isPure(): bool { return $this->left->isPure() && $this->right->isPure(); }
}

/** Unary op. `op` is '-' or '!' or '~' etc. */
final class UnaryOp extends Expr {
    public function __construct(
        public readonly string $op,
        public readonly Expr $operand,
    ) {}
    public function isPure(): bool { return $this->operand->isPure(); }
}

/** Static method call. Impure (may have side effects). */
final class StaticCall extends Expr {
    public function __construct(
        public readonly string $classFqn,  // '\PHPJava\Aot\...' or 'self'
        public readonly string $method,
        public readonly array $args,       // Expr[]
    ) {}
    public function isPure(): bool { return false; }
}

/**
 * Instance method call: $receiver->method(args). Used for invokevirtual,
 * invokeinterface, and invokespecial-of-non-<init>. Impure.
 */
final class InstanceCall extends Expr {
    public function __construct(
        public readonly Expr $receiver,
        public readonly string $method,
        public readonly array $args,       // Expr[]
    ) {}
    public function isPure(): bool { return false; }
}

/**
 * `new \Class(args...)` — combined alloc+init. Recognised at IR
 * build time from the new+dup+invokespecial<init> bytecode pattern,
 * so we don't need separate UninitObj/dup-materialise machinery.
 * Impure.
 */
final class New_ extends Expr {
    public function __construct(
        public readonly string $classFqn,
        public readonly array $args,       // Expr[]
    ) {}
    public function isPure(): bool { return false; }
}

/**
 * Synthetic Expr representing the exception value present on stack at
 * the entry of an exception-handler basic block. Lowerer renders this
 * as the catch clause's variable (`$__e`).
 */
final class CaughtException extends Expr {}

/** Static field read. Impure (may trigger <clinit>). */
final class StaticFieldRead extends Expr {
    public function __construct(
        public readonly string $classFqn,
        public readonly string $field,
    ) {}
    public function isPure(): bool { return false; }
}

abstract class Stmt {}

/** $L[slot] = $value; */
final class StoreLocal extends Stmt {
    public function __construct(
        public readonly int $slot,
        public readonly Expr $value,
    ) {}
}

/** $L[slot] += $delta; (used for iinc) */
final class IincLocal extends Stmt {
    public function __construct(
        public readonly int $slot,
        public readonly int $delta,
    ) {}
}

/** Side-effecting expression in statement position (e.g., println()). */
final class ExprStmt extends Stmt {
    public function __construct(public readonly Expr $expr) {}
}

/** $Class::$field = $value; */
final class StoreStaticField extends Stmt {
    public function __construct(
        public readonly string $classFqn,
        public readonly string $field,
        public readonly Expr $value,
    ) {}
}

abstract class Terminator {}

final class Goto_ extends Terminator {
    public function __construct(public readonly int $targetPc) {}
}

final class CondGoto extends Terminator {
    public function __construct(
        public readonly Expr $cond,        // boolean expression
        public readonly int $thenPc,       // taken branch
        public readonly int $elsePc,       // fall-through
    ) {}
}

final class Return_ extends Terminator {
    public function __construct(public readonly ?Expr $value = null) {}
}

final class Throw_ extends Terminator {
    public function __construct(public readonly Expr $value) {}
}

/** A basic block is the unit of straight-line code. */
final class BasicBlock {
    public function __construct(
        public readonly int $entryPc,
        /** @var Stmt[] */
        public array $stmts = [],
        public ?Terminator $term = null,
        /**
         * Exception-table entries covering this BB: array of
         * {handlerPc:int, classFqn:string}. Lowerer wraps the
         * BB body in `try { ... } catch (\Class $__e) { goto Lhandler; }`.
         * Empty array = unprotected BB.
         */
        public array $tryProtect = [],
        /**
         * True if this BB is the entry of an exception handler.
         * The lowerer prepends a `$__e` capture so CaughtException
         * Exprs in the body resolve to the catch variable.
         */
        public bool $isHandler = false,
    ) {}
}

/** Field access — instance read of `$obj->field`. Impure (init triggers). */
final class FieldRead extends Expr {
    public function __construct(
        public readonly Expr $receiver,
        public readonly string $field,
    ) {}
    public function isPure(): bool { return false; }
}

/** Stmt for instance field write: `$obj->field = $value;`. */
final class StoreField extends Stmt {
    public function __construct(
        public readonly Expr $receiver,
        public readonly string $field,
        public readonly Expr $value,
    ) {}
}

/**
 * Direct array-element write: `$L[$slot][$index] = $value;`.
 * Used when escape analysis at IR build time confirms the array's
 * source is a LocalRead — bypasses the `(object){v=>...}` wrapper
 * and the ArrayHelper::set call, going straight to PHP-array
 * mutation. Closes the 10× property-access cost the JIT-claims
 * battery surfaced.
 */
final class StoreArrayElement extends Stmt {
    public function __construct(
        public readonly int $slot,
        public readonly Expr $index,
        public readonly Expr $value,
    ) {}
}

/** Direct array-element read: `$L[$slot][$index]`. */
final class ArrayElementRead extends Expr {
    public function __construct(
        public readonly int $slot,
        public readonly Expr $index,
    ) {}
    public function isPure(): bool { return true; }
}

/** Direct count of an array slot: `count($L[$slot])`. */
final class ArrayLengthRead extends Expr {
    public function __construct(public readonly int $slot) {}
    public function isPure(): bool { return true; }
}

/** A method's IR. */
final class Method {
    public function __construct(
        public readonly string $name,
        public readonly string $descriptor,
        public readonly bool $isStatic,
        /** @var string[] */
        public readonly array $params,    // ['$__a0', ...]
        public readonly int $maxLocals,
        /** @var BasicBlock[] keyed by entry PC */
        public array $blocks = [],
    ) {}
}

/** A class's IR — methods + lambda satellites. */
final class Module {
    public function __construct(
        public readonly string $namespace,
        public readonly string $className,
        /** @var Method[] */
        public array $methods = [],
        /** @var array<int, array{fqn:string, php:string}> */
        public array $lambdaClasses = [],
    ) {}
}
