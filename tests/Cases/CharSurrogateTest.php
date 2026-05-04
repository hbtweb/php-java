<?php
declare(strict_types=1);
namespace PHPJava\Tests\Cases;

/**
 * Validates Java `String.charAt(i)` returns the i-th UTF-16 code unit
 * — including surrogate pair halves for supplementary chars.
 * Surfaced by the 2026-05-04 audit as ROADMAP gap T6.
 *
 * Pre-fix: src/Aot/Runtime/bootstrap.php's `String_::charAt` did
 * `ord($s[$i])` — single-byte access at byte index. Diverged for
 * any non-ASCII content (multi-byte BMP chars) and produced garbage
 * for supplementary chars stored in CESU-8 (Java class file form).
 */
class CharSurrogateTest extends Base
{
    protected $fixtures = [
        'CharSurrogateTest',
    ];

    private function call(string $name)
    {
        return static::$initiatedJavaClasses['CharSurrogateTest']
            ->getInvoker()
            ->getStatic()
            ->getMethods()
            ->call($name);
    }

    public function testAsciiCharAt0(): void
    {
        $this->assertSame(0x68, $this->call('asciiCharAt0')); // 'h'
    }

    /** "café".charAt(3) — index 3 is é = U+00E9. */
    public function testLatin1CharAt3(): void
    {
        $this->assertSame(0xE9, $this->call('latin1CharAt3'));
    }

    /** "こんにちは".charAt(2) — に = U+306B. */
    public function testMultibyteCharAt2(): void
    {
        $this->assertSame(0x306B, $this->call('multibyteCharAt2'));
    }

    /** "😀".charAt(0) — high surrogate of U+1F600 = U+D83D. */
    public function testSupplementaryCharAt0(): void
    {
        $this->assertSame(0xD83D, $this->call('supplementaryCharAt0'));
    }

    /** "😀".charAt(1) — low surrogate = U+DE00. */
    public function testSupplementaryCharAt1(): void
    {
        $this->assertSame(0xDE00, $this->call('supplementaryCharAt1'));
    }

    public function testMixedSupplementaryCharAt2(): void
    {
        $this->assertSame(0xD83D, $this->call('mixedSupplementaryCharAt2'));
    }

    public function testMixedSupplementaryCharAt3(): void
    {
        $this->assertSame(0xDE00, $this->call('mixedSupplementaryCharAt3'));
    }
}
