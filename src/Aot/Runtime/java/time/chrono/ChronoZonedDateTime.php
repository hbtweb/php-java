<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\time\chrono;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.time.chrono.ChronoZonedDateTime. Regenerate via
 *   php tools/gen-aot-stubs.php java.time.chrono.ChronoZonedDateTime
 */
interface ChronoZonedDateTime
{
    public function timeLineOrder();
    public function from($a0 = null);
    public function range($a0 = null);
    public function get($a0 = null);
    public function getLong($a0 = null);
    public function toLocalDate();
    public function toLocalTime();
    public function toLocalDateTime();
    public function getChronology();
    public function getOffset();
    public function getZone();
    public function withEarlierOffsetAtOverlap();
    public function withLaterOffsetAtOverlap();
    public function withZoneSameLocal($a0 = null);
    public function withZoneSameInstant($a0 = null);
    public function isSupported($a0 = null);
    public function with($a0 = null, $a1 = null);
    public function plus($a0 = null, $a1 = null);
    public function minus($a0 = null, $a1 = null);
    public function query($a0 = null);
    public function format($a0 = null);
    public function toInstant();
    public function toEpochSecond();
    public function compareTo($a0 = null);
    public function isBefore($a0 = null);
    public function isAfter($a0 = null);
    public function isEqual($a0 = null);
    public function equals($a0 = null);
    public function hashCode();
    public function toString();
}
