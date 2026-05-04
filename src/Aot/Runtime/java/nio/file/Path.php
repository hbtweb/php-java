<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\file;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.file.Path. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.file.Path
 */
interface Path
{
    public function of($a0 = null, $a1 = null);
    public function getFileSystem();
    public function isAbsolute();
    public function getRoot();
    public function getFileName();
    public function getParent();
    public function getNameCount();
    public function getName($a0 = null);
    public function subpath($a0 = null, $a1 = null);
    public function startsWith($a0 = null);
    public function endsWith($a0 = null);
    public function normalize();
    public function resolve($a0 = null, $a1 = null);
    public function resolveSibling($a0 = null);
    public function relativize($a0 = null);
    public function toUri();
    public function toAbsolutePath();
    public function toRealPath($a0 = null);
    public function toFile();
    public function register($a0 = null, $a1 = null, $a2 = null);
    public function iterator();
    public function compareTo($a0 = null);
    public function equals($a0 = null);
    public function hashCode();
    public function toString();
}
