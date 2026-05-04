<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

use PHPJava\Aot\Runtime\java\lang\Thread;

/**
 * java.util.concurrent.TimeUnit — enum representing time-granularity
 * units. Used by timed-await methods to disambiguate magnitudes.
 *
 * Java models this as an enum with seven values; we model as a class
 * with seven static instances (constants would suffice for value
 * semantics, but instance methods like toMillis() are part of the
 * Java contract).
 */
final class TimeUnit
{
    /** Internal scale factor: nanoseconds per unit. */
    private int $nanosPerUnit;
    private string $name;

    public static TimeUnit $NANOSECONDS;
    public static TimeUnit $MICROSECONDS;
    public static TimeUnit $MILLISECONDS;
    public static TimeUnit $SECONDS;
    public static TimeUnit $MINUTES;
    public static TimeUnit $HOURS;
    public static TimeUnit $DAYS;

    private function __construct(string $name, int $nanosPerUnit)
    {
        $this->name = $name;
        $this->nanosPerUnit = $nanosPerUnit;
    }

    public static function _init(): void
    {
        self::$NANOSECONDS  = new self('NANOSECONDS',  1);
        self::$MICROSECONDS = new self('MICROSECONDS', 1_000);
        self::$MILLISECONDS = new self('MILLISECONDS', 1_000_000);
        self::$SECONDS      = new self('SECONDS',      1_000_000_000);
        self::$MINUTES      = new self('MINUTES',      60 * 1_000_000_000);
        self::$HOURS        = new self('HOURS',        3_600 * 1_000_000_000);
        self::$DAYS         = new self('DAYS',         86_400 * 1_000_000_000);
    }

    public function name(): string { return $this->name; }
    public function __toString(): string { return $this->name; }

    public function toNanos(int $duration): int   { return $duration * $this->nanosPerUnit; }
    public function toMicros(int $duration): int  { return \intdiv($this->toNanos($duration), 1_000); }
    public function toMillis(int $duration): int  { return \intdiv($this->toNanos($duration), 1_000_000); }
    public function toSeconds(int $duration): int { return \intdiv($this->toNanos($duration), 1_000_000_000); }
    public function toMinutes(int $duration): int { return \intdiv($this->toSeconds($duration), 60); }
    public function toHours(int $duration): int   { return \intdiv($this->toMinutes($duration), 60); }
    public function toDays(int $duration): int    { return \intdiv($this->toHours($duration), 24); }

    public function convert(int $sourceDuration, self $sourceUnit): int
    {
        return \intdiv($sourceDuration * $sourceUnit->nanosPerUnit, $this->nanosPerUnit);
    }

    public function sleep(int $duration): void
    {
        if ($duration <= 0) return;
        $millis = $this->toMillis($duration);
        Thread::sleep(\max(1, $millis));
    }

    public static function values(): array
    {
        return [
            self::$NANOSECONDS, self::$MICROSECONDS, self::$MILLISECONDS,
            self::$SECONDS, self::$MINUTES, self::$HOURS, self::$DAYS,
        ];
    }

    public static function valueOf(string $name): self
    {
        return match ($name) {
            'NANOSECONDS'  => self::$NANOSECONDS,
            'MICROSECONDS' => self::$MICROSECONDS,
            'MILLISECONDS' => self::$MILLISECONDS,
            'SECONDS'      => self::$SECONDS,
            'MINUTES'      => self::$MINUTES,
            'HOURS'        => self::$HOURS,
            'DAYS'         => self::$DAYS,
            default => throw new \PHPJava\Packages\java\lang\IllegalArgumentException("No enum constant TimeUnit.{$name}"),
        };
    }
}

TimeUnit::_init();
