<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\regex;

/**
 * java.util.regex.Pattern — bb-allowlist fill (12/80).
 *
 * Compiled-regex container. Java's Pattern syntax aligns closely
 * with PCRE (PHP's preg_*); the shim wraps the regex with `~...~`
 * delimiters and forwards.
 */
final class Pattern
{
    public const UNIX_LINES               = 0x01;
    public const CASE_INSENSITIVE         = 0x02;
    public const COMMENTS                 = 0x04;
    public const MULTILINE                = 0x08;
    public const LITERAL                  = 0x10;
    public const DOTALL                   = 0x20;
    public const UNICODE_CASE             = 0x40;
    public const CANON_EQ                 = 0x80;
    public const UNICODE_CHARACTER_CLASS  = 0x100;

    private string $regex;
    private int $flags;

    private function __construct(string $regex, int $flags = 0)
    {
        $this->regex = $regex;
        $this->flags = $flags;
    }

    public static function compile(string $regex, int $flags = 0): self
    {
        return new self($regex, $flags);
    }

    public function pattern(): string  { return $this->regex; }
    public function toString(): string { return $this->regex; }
    public function flags(): int       { return $this->flags; }

    public function matcher(string $input): Matcher
    {
        return new Matcher($this->regex, $input, $this->flags);
    }

    public function split(string $input, int $limit = 0): array
    {
        $delim = self::buildDelim($this->regex, $this->flags);
        $r = \preg_split($delim, $input, $limit > 0 ? $limit : -1);
        return $r === false ? [$input] : $r;
    }

    /** Convenience: Pattern.compile(regex).matcher(input).matches(). */
    public static function matches(string $regex, string $input): bool
    {
        $delim = self::buildDelim('^(?:' . $regex . ')$', 0);
        return (bool) \preg_match($delim, $input);
    }

    /**
     * Java's \Q...\E literal-escape sequence. Pattern.quote(s) wraps
     * s so any meta-characters are treated literally. PCRE supports
     * \Q...\E directly. The break-and-resume trick handles s
     * containing the literal `\E` token (very rare).
     */
    public static function quote(string $s): string
    {
        if (\strpos($s, '\\E') === false) {
            return '\\Q' . $s . '\\E';
        }
        return '\\Q' . \str_replace('\\E', '\\E\\\\E\\Q', $s) . '\\E';
    }

    /** Build a PCRE delimited regex from the Java pattern + flags. */
    public static function buildDelim(string $regex, int $flags = 0): string
    {
        $modifiers = '';
        if ($flags & self::CASE_INSENSITIVE)        $modifiers .= 'i';
        if ($flags & self::MULTILINE)               $modifiers .= 'm';
        if ($flags & self::DOTALL)                  $modifiers .= 's';
        if ($flags & self::COMMENTS)                $modifiers .= 'x';
        if ($flags & self::UNICODE_CHARACTER_CLASS) $modifiers .= 'u';
        $escaped = \str_replace('~', '\\~', $regex);
        return '~' . $escaped . '~' . $modifiers;
    }
}
