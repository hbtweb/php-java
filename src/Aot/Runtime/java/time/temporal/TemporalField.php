<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\time\temporal;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.time.temporal.TemporalField. Regenerate via
 *   php tools/gen-aot-stubs.php java.time.temporal.TemporalField
 */
interface TemporalField
{
    public function getDisplayName($a0 = null);
    public function getBaseUnit();
    public function getRangeUnit();
    public function range();
    public function isDateBased();
    public function isTimeBased();
    public function isSupportedBy($a0 = null);
    public function rangeRefinedBy($a0 = null);
    public function getFrom($a0 = null);
    public function adjustInto($a0 = null, $a1 = null);
    public function resolve($a0 = null, $a1 = null, $a2 = null);
    public function toString();
}
