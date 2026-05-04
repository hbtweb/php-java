<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\time\temporal;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.time.temporal.TemporalUnit. Regenerate via
 *   php tools/gen-aot-stubs.php java.time.temporal.TemporalUnit
 */
interface TemporalUnit
{
    public function getDuration();
    public function isDurationEstimated();
    public function isDateBased();
    public function isTimeBased();
    public function isSupportedBy($a0 = null);
    public function addTo($a0 = null, $a1 = null);
    public function between($a0 = null, $a1 = null);
    public function toString();
}
