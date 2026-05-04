<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\file\attribute;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.file.attribute.BasicFileAttributes. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.file.attribute.BasicFileAttributes
 */
interface BasicFileAttributes
{
    public function lastModifiedTime();
    public function lastAccessTime();
    public function creationTime();
    public function isRegularFile();
    public function isDirectory();
    public function isSymbolicLink();
    public function isOther();
    public function size();
    public function fileKey();
}
