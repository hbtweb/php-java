<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.TimeoutException — thrown by timed-await
 * variants (Future.get(timeout), latch.await(timeout), etc.) when
 * the wait expires before the operation completes.
 */
class TimeoutException extends \Exception
{
}
