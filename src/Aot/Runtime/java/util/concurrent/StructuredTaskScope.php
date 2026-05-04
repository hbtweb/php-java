<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.StructuredTaskScope (Java 21+) — structured
 * concurrency primitive. A scope is a try-with-resources block
 * holding a set of forked subtasks; close() joins all subtasks
 * before the block exits, ensuring no orphaned work.
 *
 * Subclasses provide failure / shutdown policies:
 *   - StructuredTaskScope (this class) — joins all subtasks; no
 *     auto-cancellation.
 *   - ShutdownOnFailure — cancels remaining subtasks on first
 *     failure; throwIfFailed() rethrows the first exception.
 *   - ShutdownOnSuccess — cancels remaining subtasks on first
 *     success; result() returns the first successful value.
 *
 * Java's reference impl uses virtual threads; PHP Fibers ARE virtual
 * threads in Java 21's terminology, so this maps directly to VTE.
 */
class StructuredTaskScope
{
    /** @var Subtask[] */
    private array $subtasks = [];
    private bool $closed = false;
    private bool $shutdownRequested = false;
    private string $name;

    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    /**
     * Java: scope.fork(Callable<V>) — schedules a subtask and returns
     * a Subtask handle. The handle's get() blocks until the subtask
     * completes and returns its value (or throws).
     */
    public function fork(callable $task): Subtask
    {
        if ($this->closed) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException('Scope closed');
        }
        $subtask = new Subtask($task, $this);
        $subtask->_start();
        $this->subtasks[] = $subtask;
        return $subtask;
    }

    /**
     * Java: scope.join() — block until all subtasks complete or the
     * scope is shut down. Returns this for chaining.
     */
    public function join(): static
    {
        foreach ($this->subtasks as $st) {
            try {
                $st->get();
            } catch (\Throwable) {
                // Errors handled by subclasses (ShutdownOnFailure)
            }
            if ($this->shutdownRequested) break;
        }
        return $this;
    }

    /** Java: scope.shutdown() — cancel all in-flight subtasks. */
    public function shutdown(): void
    {
        $this->shutdownRequested = true;
        foreach ($this->subtasks as $st) {
            $st->_cancelIfPending();
        }
    }

    /** Java: scope.close() — join all subtasks and release resources. */
    public function close(): void
    {
        if ($this->closed) return;
        $this->join();
        $this->closed = true;
    }

    public function getName(): string { return $this->name; }
    public function isShutdown(): bool { return $this->shutdownRequested; }

    /** Internal: subclasses inspect this when subtasks complete. */
    public function _onSubtaskComplete(Subtask $subtask): void
    {
        // Base StructuredTaskScope: no policy, just track
    }
}
