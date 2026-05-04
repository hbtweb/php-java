<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\stream;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.stream.BaseStream. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.stream.BaseStream
 */
interface BaseStream
{
    public function iterator();
    public function spliterator();
    public function isParallel();
    public function sequential();
    public function parallel();
    public function unordered();
    public function onClose($a0 = null);
    public function close();
}
