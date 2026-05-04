<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent\locks;

/**
 * java.util.concurrent.locks.ReadWriteLock — interface for paired
 * read/write locks. Multiple readers may hold concurrently; writers
 * have exclusive access (no readers, no other writers).
 *
 * Implementation: ReentrantReadWriteLock.
 */
interface ReadWriteLock
{
    public function readLock(): Lock;
    public function writeLock(): Lock;
}
