<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\file\attribute;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.file.attribute.UserDefinedFileAttributeView. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.file.attribute.UserDefinedFileAttributeView
 */
interface UserDefinedFileAttributeView
{
    public function name();
    public function list();
    public function size($a0 = null);
    public function read($a0 = null, $a1 = null);
    public function write($a0 = null, $a1 = null);
    public function delete($a0 = null);
}
