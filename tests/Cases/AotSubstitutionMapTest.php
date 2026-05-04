<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates the substitution-table API in the AOT compiler — the
 * cljp dual-runtime hook (see `~/GitHub/ClojurePHP/docs/CLJP-POSITIONING.md`
 * §"The substitution table is the load-bearing piece"). When AOT-
 * compiling a JAR that references `clojure.lang.X`, the substitution
 * map redirects the FQN at translate time so the emitted PHP calls
 * `cljp.lang.X` directly — both halves run on the same Zend heap
 * with the same `zval` shape, so cross-runtime invocation is
 * a direct method dispatch with no marshalling cost.
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
