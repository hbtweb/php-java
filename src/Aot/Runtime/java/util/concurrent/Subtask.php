<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\Async\VirtualThreadExecutor;

/**
 * java.util.concurrent.StructuredTaskScope.Subtask (Java 21+) —
 * handle returned by StructuredTaskScope.fork().
 *
 * Three states:
 *   - UNAVAILABLE — not yet completed
 *   - SUCCESS     — completed normally; get() returns the value
 *   - FAILED      — completed with exception; exception() returns it
 */
class Subtask
{
    public const STATE_UNAVAILABLE = 0;
    public const STATE_SUCCESS = 1;
    public const STATE_FAILED = 2;

    private int $state = self::STATE_UNAVAILABLE;
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private ?int $futureId = null;

    /** @var callable */
    private $task;
    private StructuredTaskScope $scope;

    public function __construct(callable $task, StructuredTaskScope $scope)
    {
        $this->task = $task;
        $this->scope = $scope;
    }

    public function _start(): void
    {
        $self = $this;
        $task = $this->task;
        $this->futureId = VirtualThreadExecutor::async(static function () use ($self, $task) {
            try {
                $self->result = $task();
                $self->state = self::STATE_SUCCESS;
                $self->scope->_onSubtaskComplete($self);
                return $self->result;
            } catch (\Throwable $e) {
                $self->exception = $e;
                $self->state = self::STATE_FAILED;
                $self->scope->_onSubtaskComplete($self);
                throw $e;
            }
        });
    }

    /** Java: subtask.get() — non-blocking accessor; throws if not yet done or failed. */
    public function get(): mixed
    {
        // Block until the future settles
        $value = VirtualThreadExecutor::await($this->futureId);
        if ($this->state === self::STATE_FAILED) {
            throw $this->exception;
        }
        return $value;
    }

    public function exception(): ?\Throwable { return $this->exception; }
    public function state(): int { return $this->state; }

    /** Internal: returns the cached result without re-awaiting (for use in onSubtaskComplete callbacks). */
    public function _cachedResult(): mixed { return $this->result; }

    public function _cancelIfPending(): void
    {
        if ($this->state === self::STATE_UNAVAILABLE && $this->futureId !== null) {
            VirtualThreadExecutor::cancel($this->futureId);
        }
    }
}
