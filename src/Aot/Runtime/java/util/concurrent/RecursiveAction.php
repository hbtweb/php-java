<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * java.util.concurrent.RecursiveAction — ForkJoinTask that returns
 * void. Subclass implements compute() to do work without producing
 * a value.
 */
abstract class RecursiveAction extends ForkJoinTask
{
    /** Subclasses return void; concrete signature returns null. */
    abstract public function compute(): mixed;
}
