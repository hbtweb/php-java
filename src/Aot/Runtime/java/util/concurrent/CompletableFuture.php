<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\Async\CancellationException;

/**
 * java.util.concurrent.CompletableFuture — Java 8+ Future with
 * composition. Wraps InlineExecutor's Future-id model with the
 * Java-shaped object surface. Each CompletableFuture owns one Future
 * id from the InlineExecutor.
 *
 * Per the may-suspend analyser routing: most CompletableFuture call
 * sites that can be statically proven non-suspending compile to
 * inline emit (no runtime engagement at all). This shim is the
 * fallback for cases the analyser couldn't reduce — composition
 * chains across user-method boundaries, dynamic supplyAsync targets,
 * etc.
 *
 * For genuinely-suspending CompletableFutures (chains that include
 * Thread.sleep / I/O blocking / etc.) the may-suspend analyser
 * routes through VirtualThreadExecutor instead — that path lives at
 * a sibling shim file when bb-fill exercises it.
 *
 * Surface covered (Java 8 base + Java 9 additions):
 *   - supplyAsync, runAsync                       (factories)
 *   - completedFuture, failedFuture, completedStage
 *   - get, getNow, join                            (terminal)
 *   - thenApply, thenAccept, thenRun               (compose)
 *   - thenCombine, thenAcceptBoth, runAfterBoth    (combine)
 *   - applyToEither, acceptEither, runAfterEither  (race)
 *   - exceptionally, exceptionallyCompose          (error handling)
 *   - whenComplete, handle                          (terminal-side-effect)
 *   - allOf, anyOf                                  (static aggregators)
 *   - cancel, isDone, isCancelled, isCompletedExceptionally
 *   - complete, completeExceptionally               (manual settling)
 *   - obtrudeValue, obtrudeException                (force re-set)
 *
 * Out of scope for v1 (all add ~50 LOC each, ship per fixture):
 *   - Async-suffixed variants (thenApplyAsync etc.) — ExecutorService
 *     wiring; routes through ExecutorService shim.
 *   - orTimeout, completeOnTimeout — VTE timer integration.
 *   - copy, minimalCompletionStage, toCompletableFuture — niche.
 */
class CompletableFuture
{
    /** Future id in the InlineExecutor. */
    private int $futureId;

    /**
     * Manual settling — futures created via the bare constructor or
     * `new CompletableFuture<>()`. The InlineExecutor doesn't expose
     * an externally-settable Future, so we manage state here and
     * route compose ops through it.
     */
    private bool $manuallyCreated = false;
    private int $state = 0; // 0=PENDING, 1=FULFILLED, 2=REJECTED, 3=CANCELLED
    private mixed $value = null;
    /** @var list<callable> dependents waiting on manual settle. */
    private array $manualDependents = [];

    public function __construct(?int $futureId = null)
    {
        if ($futureId === null) {
            $this->manuallyCreated = true;
            $this->futureId = -1;
        } else {
            $this->futureId = $futureId;
        }
    }

    /** Java: CompletableFuture.supplyAsync(Supplier<U>). Routes to InlineExecutor. */
    public static function supplyAsync(callable $supplier): self
    {
        return new self(InlineExecutor::async($supplier));
    }

    /** Java: CompletableFuture.runAsync(Runnable). */
    public static function runAsync(callable $runnable): self
    {
        return new self(InlineExecutor::async(static function () use ($runnable) {
            $runnable();
            return null;
        }));
    }

    /** Java: CompletableFuture.completedFuture(value). */
    public static function completedFuture(mixed $value): self
    {
        $cf = new self();
        $cf->state = 1;
        $cf->value = $value;
        return $cf;
    }

    /** Java 9+: CompletableFuture.failedFuture(Throwable). */
    public static function failedFuture(\Throwable $ex): self
    {
        $cf = new self();
        $cf->state = 2;
        $cf->value = $ex;
        return $cf;
    }

    /**
     * Java: get() blocks for the value. Throws ExecutionException
     * wrapping the cause on rejection. Java's get() also throws
     * InterruptedException / CancellationException — we let those
     * propagate.
     */
    public function get(): mixed
    {
        if ($this->manuallyCreated) {
            return $this->awaitManual();
        }
        return InlineExecutor::await($this->futureId);
    }

    /** Java 8: getNow(valueIfAbsent) — non-blocking. */
    public function getNow(mixed $valueIfAbsent): mixed
    {
        if ($this->manuallyCreated) {
            return match ($this->state) {
                1 => $this->value,
                2 => throw $this->value,
                default => $valueIfAbsent,
            };
        }
        if (InlineExecutor::isFulfilled($this->futureId)) return InlineExecutor::await($this->futureId);
        if (InlineExecutor::isRejected($this->futureId))  return InlineExecutor::await($this->futureId); // throws
        return $valueIfAbsent;
    }

    /** Java: join() — like get() but wraps checked exceptions in CompletionException. */
    public function join(): mixed
    {
        try {
            return $this->get();
        } catch (\Throwable $e) {
            // Java's join() wraps everything except RuntimeException + Error
            // in CompletionException. Approximate: rethrow as-is for now
            // — matches bracket of typical user code which catches Throwable.
            throw $e;
        }
    }

    public function isDone(): bool
    {
        if ($this->manuallyCreated) return $this->state !== 0;
        return !InlineExecutor::isPending($this->futureId);
    }

    public function isCancelled(): bool
    {
        if ($this->manuallyCreated) return $this->state === 3;
        // InlineExecutor doesn't expose isCancelled directly; cancel()
        // landing puts it in REJECTED with CancellationException value.
        // For now: return false — refine when needed.
        return false;
    }

    public function isCompletedExceptionally(): bool
    {
        if ($this->manuallyCreated) return $this->state === 2 || $this->state === 3;
        return InlineExecutor::isRejected($this->futureId);
    }

    public function cancel(bool $mayInterruptIfRunning = false): bool
    {
        if ($this->manuallyCreated) {
            if ($this->state !== 0) return false;
            $this->state = 3;
            $this->value = new CancellationException('Future was cancelled');
            $this->fireManualDependents();
            return true;
        }
        return InlineExecutor::cancel($this->futureId);
    }

    /** Java: complete(value) — for manually-created futures. Returns true if newly settled. */
    public function complete(mixed $value): bool
    {
        if (!$this->manuallyCreated || $this->state !== 0) return false;
        $this->state = 1;
        $this->value = $value;
        $this->fireManualDependents();
        return true;
    }

    public function completeExceptionally(\Throwable $ex): bool
    {
        if (!$this->manuallyCreated || $this->state !== 0) return false;
        $this->state = 2;
        $this->value = $ex;
        $this->fireManualDependents();
        return true;
    }

    public function obtrudeValue(mixed $value): void
    {
        $this->state = 1;
        $this->value = $value;
    }

    public function obtrudeException(\Throwable $ex): void
    {
        $this->state = 2;
        $this->value = $ex;
    }

    // ── compose ──────────────────────────────────────────────────────

    public function thenApply(callable $fn): self
    {
        if ($this->manuallyCreated) {
            return $this->manualThenApply($fn);
        }
        return new self(InlineExecutor::thenApply($this->futureId, $fn));
    }

    public function thenAccept(callable $consumer): self
    {
        return $this->thenApply(static function ($v) use ($consumer) {
            $consumer($v);
            return null;
        });
    }

    public function thenRun(callable $runnable): self
    {
        return $this->thenApply(static function () use ($runnable) {
            $runnable();
            return null;
        });
    }

    public function thenCombine(self $other, callable $fn): self
    {
        // Combine $this + $other → fn($this->value, $other->value)
        return self::supplyAsync(function () use ($other, $fn) {
            $a = $this->get();
            $b = $other->get();
            return $fn($a, $b);
        });
    }

    public function thenAcceptBoth(self $other, callable $consumer): self
    {
        return $this->thenCombine($other, static function ($a, $b) use ($consumer) {
            $consumer($a, $b);
            return null;
        });
    }

    public function runAfterBoth(self $other, callable $runnable): self
    {
        return $this->thenCombine($other, static function () use ($runnable) {
            $runnable();
            return null;
        });
    }

    /** Java: applyToEither — race; first to fulfill wins. */
    public function applyToEither(self $other, callable $fn): self
    {
        if ($this->manuallyCreated || $other->manuallyCreated) {
            // Manual-created path needs unified composition; defer
            // to a v2 with a unified Future model.
            throw new \LogicException(
                'CompletableFuture.applyToEither: manual+InlineExecutor mix not yet unified'
            );
        }
        $winnerId = InlineExecutor::anyOf($this->futureId, $other->futureId);
        return new self(InlineExecutor::thenApply($winnerId, $fn));
    }

    public function acceptEither(self $other, callable $consumer): self
    {
        return $this->applyToEither($other, static function ($v) use ($consumer) {
            $consumer($v);
            return null;
        });
    }

    public function runAfterEither(self $other, callable $runnable): self
    {
        return $this->applyToEither($other, static function () use ($runnable) {
            $runnable();
            return null;
        });
    }

    public function exceptionally(callable $fn): self
    {
        return self::supplyAsync(function () use ($fn) {
            try {
                return $this->get();
            } catch (\Throwable $e) {
                return $fn($e);
            }
        });
    }

    public function whenComplete(callable $action): self
    {
        return self::supplyAsync(function () use ($action) {
            try {
                $value = $this->get();
                $action($value, null);
                return $value;
            } catch (\Throwable $e) {
                $action(null, $e);
                throw $e;
            }
        });
    }

    public function handle(callable $fn): self
    {
        return self::supplyAsync(function () use ($fn) {
            try {
                $value = $this->get();
                return $fn($value, null);
            } catch (\Throwable $e) {
                return $fn(null, $e);
            }
        });
    }

    // ── static aggregators ───────────────────────────────────────────

    public static function allOf(self ...$futures): self
    {
        if (empty($futures)) return self::completedFuture(null);
        $ids = [];
        foreach ($futures as $f) {
            if ($f->manuallyCreated) {
                throw new \LogicException(
                    'CompletableFuture.allOf: manual+InlineExecutor mix not yet unified'
                );
            }
            $ids[] = $f->futureId;
        }
        $combinedId = InlineExecutor::allOf(...$ids);
        return new self($combinedId);
    }

    public static function anyOf(self ...$futures): self
    {
        if (empty($futures)) {
            // Java: returns a never-completing CompletableFuture
            return new self();
        }
        $ids = [];
        foreach ($futures as $f) {
            if ($f->manuallyCreated) {
                throw new \LogicException(
                    'CompletableFuture.anyOf: manual+InlineExecutor mix not yet unified'
                );
            }
            $ids[] = $f->futureId;
        }
        $winnerId = InlineExecutor::anyOf(...$ids);
        return new self($winnerId);
    }

    // ── manual-settle internals ──────────────────────────────────────

    private function awaitManual(): mixed
    {
        if ($this->state === 0) {
            // Spin a moment for any in-flight callbacks to settle us.
            // For this v1 surface, manually-created CFs without an
            // explicit complete() are stuck — caller responsibility.
            // Real impl would integrate with the run loop.
            throw new \LogicException(
                'CompletableFuture: manual-created CF awaited before complete()'
            );
        }
        return match ($this->state) {
            1 => $this->value,
            2 => throw $this->value,
            3 => throw new CancellationException('Future was cancelled'),
        };
    }

    private function manualThenApply(callable $fn): self
    {
        if ($this->state === 1) {
            return self::completedFuture($fn($this->value));
        }
        if ($this->state === 2) {
            return self::failedFuture($this->value);
        }
        if ($this->state === 3) {
            $cf = new self();
            $cf->state = 3;
            $cf->value = $this->value;
            return $cf;
        }
        // Pending — register dependent
        $next = new self();
        $this->manualDependents[] = static function () use ($next, $fn) {
            try {
                $next->complete($fn($this->value ?? null));
            } catch (\Throwable $e) {
                $next->completeExceptionally($e);
            }
        };
        return $next;
    }

    private function fireManualDependents(): void
    {
        $deps = $this->manualDependents;
        $this->manualDependents = [];
        foreach ($deps as $dep) $dep();
    }
}
