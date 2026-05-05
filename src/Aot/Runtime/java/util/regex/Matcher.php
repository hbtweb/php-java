<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\regex;

use PHPJava\Aot\Runtime\java\lang\IllegalStateException;

/**
 * java.util.regex.Matcher — bb-allowlist fill (13/80).
 *
 * Stateful matcher. find() advances an internal cursor; matches()
 * requires whole-input match. group/start/end return the last
 * successful match's data.
 */
final class Matcher
{
    private string $regex;
    private string $input;
    private int $flags;
    /** @var array<int|string, array{0: string, 1: int}|null> */
    private array $matchGroups = [];
    private bool $matchValid = false;
    private int $findFrom = 0;

    public function __construct(string $regex, string $input, int $flags = 0)
    {
        $this->regex = $regex;
        $this->input = $input;
        $this->flags = $flags;
    }

    public function matches(): bool
    {
        $delim = Pattern::buildDelim('^(?:' . $this->regex . ')$', $this->flags);
        $r = \preg_match($delim, $this->input, $m, \PREG_OFFSET_CAPTURE);
        if ($r === 1) {
            $this->matchGroups = $m;
            $this->matchValid = true;
            return true;
        }
        $this->matchGroups = [];
        $this->matchValid = false;
        return false;
    }

    public function lookingAt(): bool
    {
        $delim = Pattern::buildDelim('^(?:' . $this->regex . ')', $this->flags);
        $r = \preg_match($delim, $this->input, $m, \PREG_OFFSET_CAPTURE);
        if ($r === 1) {
            $this->matchGroups = $m;
            $this->matchValid = true;
            return true;
        }
        return false;
    }

    public function find(): bool
    {
        $delim = Pattern::buildDelim($this->regex, $this->flags);
        $r = \preg_match($delim, $this->input, $m, \PREG_OFFSET_CAPTURE, $this->findFrom);
        if ($r === 1) {
            $this->matchGroups = $m;
            $this->matchValid = true;
            // Advance past the match (at least one char to avoid
            // infinite loops on zero-width matches).
            $matchEnd = $m[0][1] + \strlen($m[0][0]);
            $this->findFrom = $matchEnd > $this->findFrom ? $matchEnd : $this->findFrom + 1;
            return true;
        }
        $this->matchValid = false;
        return false;
    }

    public function group(int|string $idx = 0): ?string
    {
        $this->requireMatch();
        $g = $this->matchGroups[$idx] ?? null;
        if ($g === null) return null;
        if ($g[1] === -1) return null;
        return $g[0];
    }

    public function start(int|string $idx = 0): int
    {
        $this->requireMatch();
        $g = $this->matchGroups[$idx] ?? null;
        return $g === null ? -1 : $g[1];
    }

    public function end(int|string $idx = 0): int
    {
        $this->requireMatch();
        $g = $this->matchGroups[$idx] ?? null;
        if ($g === null || $g[1] === -1) return -1;
        return $g[1] + \strlen($g[0]);
    }

    public function groupCount(): int
    {
        // Java returns count of capture groups excluding group 0
        // (the full match). PREG_OFFSET_CAPTURE keys are mixed int /
        // named-string; we count distinct integer keys > 0. PHP also
        // duplicates named-group entries under their integer key, so
        // counting integer-only is correct.
        $n = 0;
        foreach ($this->matchGroups as $k => $_) {
            if (\is_int($k) && $k > 0) $n++;
        }
        return $n;
    }

    public function replaceAll(string $replacement): string
    {
        $delim = Pattern::buildDelim($this->regex, $this->flags);
        $r = \preg_replace($delim, $replacement, $this->input);
        return $r === null ? $this->input : $r;
    }

    public function replaceFirst(string $replacement): string
    {
        $delim = Pattern::buildDelim($this->regex, $this->flags);
        $r = \preg_replace($delim, $replacement, $this->input, 1);
        return $r === null ? $this->input : $r;
    }

    public function reset(?string $newInput = null): self
    {
        if ($newInput !== null) $this->input = $newInput;
        $this->findFrom = 0;
        $this->matchGroups = [];
        $this->matchValid = false;
        return $this;
    }

    public function pattern(): Pattern
    {
        return Pattern::compile($this->regex, $this->flags);
    }

    private function requireMatch(): void
    {
        if (!$this->matchValid) {
            throw new IllegalStateException('No match available');
        }
    }
}
