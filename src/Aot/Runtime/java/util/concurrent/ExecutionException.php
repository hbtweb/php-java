<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.ExecutionException — wraps an exception
 * thrown by a Future's task when retrieved via Future.get().
 * Thrown by ThreadPoolExecutor.invokeAny when all tasks fail.
 */
class ExecutionException extends \Exception
{
}
