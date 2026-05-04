<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.StructuredTaskScope.ShutdownOnSuccess
 * (Java 21+) — shuts down on first subtask success. Cancels all
 * remaining subtasks. result() returns the first successful value.
 *
 * Common usage: race multiple equivalent operations (DNS lookups,
 * fallback API calls); take whichever finishes first.
 */
class ShutdownOnSuccess extends StructuredTaskScope
{
    private mixed $firstResult = null;
    private bool $hasResult = false;

    public function _onSubtaskComplete(Subtask $subtask): void
    {
        if ($subtask->state() === Subtask::STATE_SUCCESS && !$this->hasResult) {
            $this->firstResult = $subtask->_cachedResult();
            $this->hasResult = true;
            $this->shutdown();
        }
    }

    public function result(): mixed
    {
        if (!$this->hasResult) {
            throw new \PHPJava\Packages\java\lang\IllegalStateException(
                'No subtask succeeded'
            );
        }
        return $this->firstResult;
    }
}
