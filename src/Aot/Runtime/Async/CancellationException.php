<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\Async;

/**
 * Java's `java.util.concurrent.CancellationException` — thrown by a
 * Future's `get()` (i.e. `await()` here) when the awaited Future was
 * cancelled before settling. Mirrors the JDK class, which extends
 * `IllegalStateException` → `RuntimeException`. We extend
 * PHP's `\RuntimeException` for the same un-checked propagation
 * shape.
 */
final class CancellationException extends \RuntimeException {}
