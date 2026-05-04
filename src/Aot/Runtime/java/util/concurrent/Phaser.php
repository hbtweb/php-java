<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.Phaser — flexible barrier with dynamic
 * registration. Generalises CyclicBarrier (fixed party count) and
 * CountDownLatch (single-shot countdown).
 *
 * Each Phaser cycle: registered parties arrive (via arriveAndAwait
 * or arrive); when all arrive, phase advances and all waiting
 * parties unblock.
 *
 * Three lifecycle methods:
 *   - register: add a party
 *   - arrive: signal arrival WITHOUT waiting (returns the phase
 *     that was current at arrival)
 *   - arriveAndAwaitAdvance: signal arrival AND wait for advance
 *   - arriveAndDeregister: signal arrival AND remove self from
 *     party count
 *
 * Optional: hierarchical phasers (parent-child) — defer to v2 unless
 * a fixture surfaces the need; rare in modern Java.
 */
class Phaser
{
    private int $registeredParties = 0;
    private int $arrivedParties = 0;
    private int $phase = 0;
    private bool $terminated = false;
    /** @var \Fiber[] */
    private array $waiters = [];
    /** @var callable|null overridable hook for custom per-phase action. */
    private $onAdvance;

    public function __construct(int $parties = 0)
    {
        if ($parties < 0) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('parties < 0');
        }
        $this->registeredParties = $parties;
    }

    public function register(): int
    {
        if ($this->terminated) return $this->phase;
        $this->registeredParties++;
        return $this->phase;
    }

    public function bulkRegister(int $count): int
    {
        if ($count < 0) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('count < 0');
        }
        if ($this->terminated) return $this->phase;
        $this->registeredParties += $count;
        return $this->phase;
    }

    public function arrive(): int
    {
        if ($this->terminated) return -1;
        $myPhase = $this->phase;
        $this->arrivedParties++;
        if ($this->arrivedParties === $this->registeredParties) {
            $this->advancePhase();
        }
        return $myPhase;
    }

    public function arriveAndDeregister(): int
    {
        if ($this->terminated) return -1;
        $myPhase = $this->phase;
        $this->arrivedParties++;
        $this->registeredParties--;
        if ($this->arrivedParties >= $this->registeredParties && $this->registeredParties > 0) {
            $this->advancePhase();
        } elseif ($this->registeredParties === 0) {
            $this->terminated = true;
            $this->wakeAll();
        }
        return $myPhase;
    }

    public function arriveAndAwaitAdvance(): int
    {
        if ($this->terminated) return -1;
        $myPhase = $this->phase;
        $this->arrivedParties++;
        if ($this->arrivedParties === $this->registeredParties) {
            $this->advancePhase();
            return $this->phase; // post-advance phase
        }
        // Wait for advance
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException(
                'Phaser.arriveAndAwaitAdvance: not last party and no fiber to park.'
            );
        }
        $this->waiters[] = $current;
        while ($this->phase === $myPhase && !$this->terminated) {
            \Fiber::suspend();
        }
        return $this->terminated ? -1 : $this->phase;
    }

    public function awaitAdvance(int $phase): int
    {
        if ($phase < 0 || $this->terminated) return -1;
        if ($this->phase !== $phase) return $this->phase;
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException(
                'Phaser.awaitAdvance: phase pending and no fiber to park.'
            );
        }
        $this->waiters[] = $current;
        while ($this->phase === $phase && !$this->terminated) {
            \Fiber::suspend();
        }
        return $this->terminated ? -1 : $this->phase;
    }

    public function getPhase(): int { return $this->phase; }
    public function getRegisteredParties(): int { return $this->registeredParties; }
    public function getArrivedParties(): int { return $this->arrivedParties; }
    public function getUnarrivedParties(): int { return $this->registeredParties - $this->arrivedParties; }
    public function isTerminated(): bool { return $this->terminated; }

    public function forceTermination(): void
    {
        $this->terminated = true;
        $this->wakeAll();
    }

    /** Subclass hook — called when the phase advances. Returning true terminates. */
    public function onAdvance(int $phase, int $registeredParties): bool
    {
        if ($this->onAdvance !== null) {
            return (bool) ($this->onAdvance)($phase, $registeredParties);
        }
        return $registeredParties === 0;
    }

    public function setOnAdvanceHook(callable $hook): void
    {
        $this->onAdvance = $hook;
    }

    private function advancePhase(): void
    {
        $oldPhase = $this->phase;
        $this->phase++;
        $this->arrivedParties = 0;
        $shouldTerminate = $this->onAdvance($oldPhase, $this->registeredParties);
        if ($shouldTerminate) $this->terminated = true;
        $this->wakeAll();
    }

    private function wakeAll(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $f) {
            if (!$f->isTerminated()) $f->resume();
        }
    }
}
