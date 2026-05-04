<?php
declare(strict_types=1);
namespace PHPJava\Packages\java\util;

use PHPJava\Exceptions\NotImplementedException;

/**
 * Java 21 sequenced-collections (JEP 431). Adds first/last access
 * + reversed view to Collection. Currently a stub interface — no
 * methods declared (existing collection stubs in this dir use the
 * same shape, with method declarations commented out for documentation
 * purposes). When the bb-allowlist fill reaches collections, the
 * SequencedCollection methods get filled there alongside Collection.
 */
interface SequencedCollection extends Collection
{
}
