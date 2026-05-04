<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.StructuredTaskScope.ShutdownOnFailure
 * (Java 21+) — shuts down on first subtask failure. Cancels all
 * remaining subtasks. throwIfFailed() rethrows the first exception.
 */
class ShutdownOnFailure extends StructuredTaskScope
{
    private ?\Throwable $firstException = null;

    public function _onSubtaskComplete(Subtask $subtask): void
    {
        if ($subtask->state() === Subtask::STATE_FAILED && $this->firstException === null) {
            $this->firstException = $subtask->exception();
            $this->shutdown();
        }
    }

    public function throwIfFailed(?\Throwable $remap = null): void
    {
        if ($this->firstException !== null) {
            if ($remap !== null) throw $remap;
            throw $this->firstException;
        }
    }

    public function exception(): ?\Throwable { return $this->firstException; }
}
