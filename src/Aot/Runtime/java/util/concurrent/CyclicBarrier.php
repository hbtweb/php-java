<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.CyclicBarrier — N parties meet at a barrier;
 * when the Nth arrives, all are released and (optionally) a barrier
 * action runs first. Reusable: cycles back to "0 arrived" automatically.
 *
 * Common usage: phased-batch parallel computation where each phase
 * needs all workers to reach the synchronisation point before any
 * proceeds (matrix multiply rows, simulation timestep, MapReduce
 * intermediate flush).
 */
class CyclicBarrier
{
    private int $parties;
    private int $arrived = 0;
    private int $generation = 0;
    /** @var \Fiber[] */
    private array $waiters = [];
    /** @var callable|null */
    private $barrierAction;
    private bool $broken = false;

    public function __construct(int $parties, ?callable $barrierAction = null)
    {
        if ($parties < 1) {
            throw new \PHPJava\Packages\java\lang\IllegalArgumentException('parties < 1');
        }
        $this->parties = $parties;
        $this->barrierAction = $barrierAction;
    }

    /**
     * Java: await() — blocks until all parties have arrived. Returns
     * the arrival index (parties-1 to 0). The party that triggers the
     * barrier (last arrival) is the one that runs the barrier action.
     */
    public function await(): int
    {
        if ($this->broken) {
            throw new BrokenBarrierException();
        }
        $myGeneration = $this->generation;
        $arrivalIndex = $this->parties - $this->arrived - 1;
        $this->arrived++;

        if ($this->arrived === $this->parties) {
            // Last party — run barrier action, advance generation, wake all.
            try {
                if ($this->barrierAction !== null) ($this->barrierAction)();
            } catch (\Throwable $e) {
                $this->breakBarrier();
                throw $e;
            }
            $this->arrived = 0;
            $this->generation++;
            $waiters = $this->waiters;
            $this->waiters = [];
            foreach ($waiters as $fiber) {
                if (!$fiber->isTerminated()) $fiber->resume();
            }
            return 0;
        }

        // Not last — park.
        $current = \Fiber::getCurrent();
        if ($current === null) {
            throw new \LogicException(
                'CyclicBarrier.await(): not last party and no fiber to park. Deadlock.'
            );
        }
        $this->waiters[] = $current;
        \Fiber::suspend();

        // Resumed — check broken
        if ($this->broken && $this->generation === $myGeneration) {
            throw new BrokenBarrierException();
        }
        return $arrivalIndex;
    }

    public function reset(): void
    {
        $this->breakBarrier();
        $this->generation++;
        $this->arrived = 0;
        $this->broken = false;
    }

    private function breakBarrier(): void
    {
        $this->broken = true;
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $fiber) {
            if (!$fiber->isTerminated()) $fiber->resume();
        }
    }

    public function getParties(): int { return $this->parties; }
    public function getNumberWaiting(): int { return $this->arrived; }
    public function isBroken(): bool { return $this->broken; }
}
