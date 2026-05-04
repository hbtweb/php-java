<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\stream;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.stream.IntStream. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.stream.IntStream
 */
interface IntStream
{
    public function filter($a0 = null);
    public function map($a0 = null);
    public function mapToObj($a0 = null);
    public function mapToLong($a0 = null);
    public function mapToDouble($a0 = null);
    public function flatMap($a0 = null);
    public function mapMulti($a0 = null);
    public function distinct();
    public function sorted();
    public function peek($a0 = null);
    public function limit($a0 = null);
    public function skip($a0 = null);
    public function takeWhile($a0 = null);
    public function dropWhile($a0 = null);
    public function forEach($a0 = null);
    public function forEachOrdered($a0 = null);
    public function toArray();
    public function reduce($a0 = null, $a1 = null);
    public function collect($a0 = null, $a1 = null, $a2 = null);
    public function sum();
    public function min();
    public function max();
    public function count();
    public function average();
    public function summaryStatistics();
    public function anyMatch($a0 = null);
    public function allMatch($a0 = null);
    public function noneMatch($a0 = null);
    public function findFirst();
    public function findAny();
    public function asLongStream();
    public function asDoubleStream();
    public function boxed();
    public function sequential();
    public function parallel();
    public function iterator();
    public function spliterator();
    public function builder();
    public function empty();
    public function of($a0 = null);
    public function iterate($a0 = null, $a1 = null, $a2 = null);
    public function generate($a0 = null);
    public function range($a0 = null, $a1 = null);
    public function rangeClosed($a0 = null, $a1 = null);
    public function concat($a0 = null, $a1 = null);
}
