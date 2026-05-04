<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates Java `String.length()` returns UTF-16 code unit count
 * (not byte count, not codepoint count). Surfaced by the 2026-05-04
 * audit as ROADMAP gap T4.
 *
 * Pre-fix: `bootstrap.php:93` returned `strlen($s)` which is byte
 * count under PHP's UTF-8 strings. For ASCII the values match; for
 * any multi-byte content, divergence. For supplementary chars (above
 * the BMP), Java returns 2 per char (surrogate pair) which neither
 * byte count nor codepoint count gives.
 *
 * The remaining String_ surface (charAt / indexOf / substring /
 * hashCode) is still byte-indexed and needs the same fix — tracked
 * under ROADMAP §Build "String_ fill" (~1 week).
 */
class StringUtf16LengthTest extends Base
{
    protected $fixtures = [
        'StringUtf16LengthTest',
    ];

    private function call(string $name)
    {
        return static::$initiatedJavaClasses['StringUtf16LengthTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name);
    }

    public function testAsciiLength(): void
    {
        $this->assertSame(5, $this->call('asciiLength'));
    }

    /** "café" — 5 bytes UTF-8, 4 UTF-16 units. */
    public function testLatin1Length(): void
    {
        $this->assertSame(4, $this->call('latin1Length'));
    }

    /** Japanese — 15 bytes UTF-8, 5 UTF-16 units. */
    public function testMultibyteLength(): void
    {
        $this->assertSame(5, $this->call('multibyteLength'));
    }

    /** Supplementary char (U+1F600) — 4 bytes UTF-8, 2 UTF-16 units. */
    public function testSupplementaryLength(): void
    {
        $this->assertSame(2, $this->call('supplementaryLength'));
    }

    /** "aé😀" — mix of ASCII, latin-1, supplementary. */
    public function testMixedLength(): void
    {
        $this->assertSame(4, $this->call('mixedLength'));
    }
}
