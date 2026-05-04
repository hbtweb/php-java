<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\file;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.file.FileSystem. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.file.FileSystem
 */
abstract class FileSystem
{
    public function __construct()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function provider();

    abstract public function close();

    abstract public function isOpen();

    abstract public function isReadOnly();

    abstract public function getSeparator();

    abstract public function getRootDirectories();

    abstract public function getFileStores();

    abstract public function supportedFileAttributeViews();

    abstract public function getPath($a0 = null, $a1 = null);

    abstract public function getPathMatcher($a0 = null);

    abstract public function getUserPrincipalLookupService();

    abstract public function newWatchService();
}
