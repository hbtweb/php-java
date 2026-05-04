<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.BrokenBarrierException — thrown by
 * CyclicBarrier.await() when the barrier was broken by reset(),
 * timeout, or an exception in the barrier action.
 */
class BrokenBarrierException extends \Exception {}
