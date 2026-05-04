<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;
use PHPJava\Aot\Runtime\Async\InterruptedException;

/**
 * java.lang.Thread — Java's thread primitive. Wraps
 * VirtualThreadExecutor for the cooperative-scheduling model.
 *
 * Java 21+'s `Thread.ofVirtual()` produces virtual threads that run
 * on a small pool of carrier OS threads. PHP Fibers ARE virtual
 * threads in Java's terminology — cooperative scheduling, lightweight
 * (~4 KB stack page), one carrier thread (the PHP process). This
 * shim routes all Thread.start() through VTE; behaviour matches
 * Java 21's virtual-thread-default semantics.
 *
 * Out of scope (deferred until fixtures need them):
 *   - ThreadGroup / SecurityManager — niche; modern Java rarely uses.
 *   - Thread.UncaughtExceptionHandler — defer; our exception model
 *     propagates through Future settle.
 *   - getStackTrace / setName / setPriority — cosmetic; ~50 LOC each.
 *   - Thread.currentThread() / Thread.getId() — return ids of the
 *     current Fiber.
 *   - daemon vs non-daemon — daemon threads don't prevent process
 *     exit on JVM; PHP CLI exits when the script does, so all our
 *     threads are effectively daemon. Setting non-daemon would need
 *     a process-keep-alive sentinel.
 */
class Thread
{
    /** @var callable */
    private $runnable;
    private ?int $vteId = null;
    private ?int $fiberId = null;
    private string $name;
    private static int $nextThreadId = 0;
    private int $threadId;

    public function __construct(?callable $runnable = null, string $name = '')
    {
        $this->runnable = $runnable ?? static function () {};
        $this->threadId = self::$nextThreadId++;
        $this->name = $name !== '' ? $name : "Thread-{$this->threadId}";
    }

    /** Java: thread.start() — schedule the runnable; non-blocking. */
    public function start(): void
    {
        if ($this->vteId !== null) {
            throw new IllegalThreadStateException('Thread already started');
        }
        $runnable = $this->runnable;
        $self = $this;
        $this->vteId = VirtualThreadExecutor::async(static function () use ($runnable, $self) {
            $self->fiberId = \spl_object_id(\Fiber::getCurrent());
            $runnable();
        });
    }

    /** Java: thread.join() — block until target thread completes. */
    public function join(int $millis = 0): void
    {
        if ($this->vteId === null) return; // never started — Java spec: no-op
        // millis=0 means "wait indefinitely" in Java.
        // Timeout variant: ~50 LOC of VTE-timer integration; defer.
        VirtualThreadExecutor::await($this->vteId);
    }

    public function isAlive(): bool
    {
        if ($this->vteId === null) return false;
        return VirtualThreadExecutor::isPending($this->vteId);
    }

    public function interrupt(): void
    {
        if ($this->fiberId !== null) {
            VirtualThreadExecutor::interrupt($this->fiberId);
        }
    }

    public function isInterrupted(): bool
    {
        // Approximate — VTE doesn't expose per-fiber flag introspection
        // currently. Refine when the JDK shim wiring needs precise checks
        // (e.g. ReentrantLock.lockInterruptibly).
        return false;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getId(): int { return $this->threadId; }

    /** Java: Thread.sleep(ms). Static — the calling thread/fiber sleeps. */
    public static function sleep(int $millis, int $nanos = 0): void
    {
        // nanos arg ignored — VTE works in ms.
        VirtualThreadExecutor::sleep($millis);
    }

    /** Java: Thread.yield() — hint to scheduler to let other threads run. */
    public static function yield(): void
    {
        // VTE: equivalent of `Amp\delay(0)` — yield once. Use sleep(0).
        VirtualThreadExecutor::sleep(0);
    }

    /** Java: Thread.currentThread() — return a Thread instance for the current fiber. */
    public static function currentThread(): self
    {
        // For now: return a freshly-allocated Thread bound to the current
        // fiber. Real impl would maintain a Fiber→Thread map. Refine when
        // a fixture surfaces the need (e.g. Thread.currentThread().getName()
        // mid-task).
        $t = new self();
        $current = \Fiber::getCurrent();
        $t->fiberId = $current !== null ? \spl_object_id($current) : null;
        return $t;
    }

    /** Java: Thread.interrupted() — clears the interrupt flag of the current thread. */
    public static function interrupted(): bool
    {
        // VTE's interrupt-flag is per-fiber; Thread.interrupted() reads
        // and clears. Approximation: always false. Refine if a fixture
        // exercises the clear-and-check pattern.
        return false;
    }

    /** Java 21+: Thread.ofVirtual() — returns a Thread.Builder.OfVirtual. */
    public static function ofVirtual(): self
    {
        // PHP Fibers are virtual threads; the regular Thread shim already
        // produces virtual-thread semantics. Return a fresh empty instance
        // that callers configure via fluent setters (modelled below).
        return new self();
    }

    /** Builder-shaped factory: `Thread.ofVirtual().name("worker").start(runnable)`. */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function unstarted(callable $runnable): self
    {
        $this->runnable = $runnable;
        return $this;
    }
}

/** Java: thrown by Thread.start() when called twice. */
class IllegalThreadStateException extends \PHPJava\Packages\java\lang\IllegalArgumentException {}
