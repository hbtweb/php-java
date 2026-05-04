<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates the AOT compiler cache observability hooks
 * (Compiler::cacheStats / resetCacheStats) shipped under
 * ROADMAP §Refinement #2. Daemon deployments need hit/miss/eviction
 * counters to detect cache thrashing when the working set exceeds
 * `PHPJAVA_AOT_CACHE_MAX`.
 */
class AotCacheStatsTest extends Base
{
    public function testCacheStatsTracksHitsAndMisses(): void
    {
        \PHPJava\Aot\Compiler::clearCompileCache();
        \PHPJava\Aot\Compiler::resetCacheStats();

        // Trivial bytecode probe: compile a known class twice. First call
        // is a miss; second is a hit.
        $bytes = \file_get_contents(__DIR__ . '/caches/BoundaryValueTypeForLongTest.class');
        $this->assertNotFalse($bytes);

        $compiler = new \PHPJava\Aot\Compiler();
        $compiler->compileBytes('BoundaryValueTypeForLongTest', $bytes);
        $compiler->compileBytes('BoundaryValueTypeForLongTest', $bytes);

        $stats = \PHPJava\Aot\Compiler::cacheStats();
        $this->assertSame(1, $stats['compileBytes']['misses']);
        $this->assertSame(1, $stats['compileBytes']['hits']);
        $this->assertSame(1, $stats['compileBytes']['size']);
        $this->assertSame(0, $stats['compileBytes']['evictions']);
    }

    public function testResetClearsCounters(): void
    {
        \PHPJava\Aot\Compiler::clearCompileCache();
        \PHPJava\Aot\Compiler::resetCacheStats();

        $bytes = \file_get_contents(__DIR__ . '/caches/BoundaryValueTypeForLongTest.class');
        (new \PHPJava\Aot\Compiler())->compileBytes('BoundaryValueTypeForLongTest', $bytes);

        $stats = \PHPJava\Aot\Compiler::cacheStats();
        $this->assertGreaterThan(0, $stats['compileBytes']['misses']);

        \PHPJava\Aot\Compiler::resetCacheStats();
        $stats = \PHPJava\Aot\Compiler::cacheStats();
        $this->assertSame(0, $stats['compileBytes']['hits']);
        $this->assertSame(0, $stats['compileBytes']['misses']);
        $this->assertSame(0, $stats['compileBytes']['evictions']);
    }
}
