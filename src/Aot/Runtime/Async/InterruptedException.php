<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\Async;

/**
 * Java's `java.lang.InterruptedException` — thrown by suspend
 * primitives (sleep, await, lock) when the calling fiber's
 * interrupt flag was set via `interrupt()`. Mirrors the JDK class,
 * which extends `Exception` (checked). We extend PHP's `\Exception`
 * for the same propagation shape; checked-exception declaration
 * is a `javac` concern, runtime semantics match.
 */
final class InterruptedException extends \Exception {}
