<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates the substitution-table API in the AOT compiler. This is a
 * generic FQN remap escape hatch for tests and advanced embedding, not a
 * CLJP `clojure.lang.*` bridge. CLJP owns Clojure semantics directly and
 * crosses into Java through explicit interop adapters.
 *
 * The probe uses a fixture that calls a well-known
 * `java.util.regex.Pattern` static method; substitution remaps that
 * to `\Mock\Substituted\Pattern`, and we verify the emitted PHP
 * contains the substituted FQN, not the default
 * `\PHPJava\Aot\Runtime\java\util\regex\Pattern`.
 */
class AotSubstitutionMapTest extends Base
{
    protected $fixtures = [
        'AotSubstitutionMapTest',
    ];

    /** Compile-time substitution: emitted PHP should reference the mapped FQN. */
    public function testSubstitutionRedirectsClassFqn(): void
    {
        \PHPJava\Aot\Compiler::clearCompileCache();

        $bytes = \file_get_contents(__DIR__ . '/caches/AotSubstitutionMapTest.class');
        $this->assertNotFalse($bytes);

        $compiler = new \PHPJava\Aot\Compiler();

        // Default emit — no substitution.
        $defaultOut = $compiler->compileBytes('AotSubstitutionMapTest', $bytes);
        $this->assertStringContainsString(
            '\\PHPJava\\Aot\\Runtime\\java\\util\\regex\\Pattern',
            $defaultOut
        );

        // Now with substitution. Different cache key (per the mapHash
        // in the key), so we get a fresh compile.
        $substituted = $compiler->compileBytes(
            'AotSubstitutionMapTest',
            $bytes,
            ['java/util/regex/Pattern' => '\\Mock\\Substituted\\Pattern']
        );
        $this->assertStringContainsString('\\Mock\\Substituted\\Pattern', $substituted);
        $this->assertStringNotContainsString(
            '\\PHPJava\\Aot\\Runtime\\java\\util\\regex\\Pattern',
            $substituted
        );
    }

    public function testSubstitutionMapAffectsCacheKey(): void
    {
        \PHPJava\Aot\Compiler::clearCompileCache();
        \PHPJava\Aot\Compiler::resetCacheStats();

        $bytes = \file_get_contents(__DIR__ . '/caches/AotSubstitutionMapTest.class');
        $compiler = new \PHPJava\Aot\Compiler();

        $compiler->compileBytes('AotSubstitutionMapTest', $bytes);
        $compiler->compileBytes('AotSubstitutionMapTest', $bytes); // hit
        $compiler->compileBytes(
            'AotSubstitutionMapTest',
            $bytes,
            ['java/util/regex/Pattern' => '\\Other\\Pattern']
        ); // miss — different map → different cache key

        $stats = \PHPJava\Aot\Compiler::cacheStats();
        $this->assertSame(2, $stats['compileBytes']['misses']);
        $this->assertSame(1, $stats['compileBytes']['hits']);
        $this->assertSame(2, $stats['compileBytes']['size']);
    }
}
