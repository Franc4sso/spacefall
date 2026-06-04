<?php

namespace App\Game;

use App\Models\Run;

/**
 * End-of-day processing.
 *
 * Phase 1 scope: subtract each resource's daily consumption (from
 * config/game.php), clamp to [0, max], advance the day counter. Deterministic
 * and seed-independent for now — no randomness in flat consumption — but the
 * pipeline shape (one method that mutates the run for one day) is what
 * Phase 5 thickens with character updates, system degradation, and delayed
 * events.
 *
 * Resource names are never hard-coded; the loop is driven by config keys.
 */
final class DayProcessor
{
    /**
     * Advance the run by one day. Mutates and persists the run.
     */
    public function advance(Run $run): Run
    {
        $resources = $run->resources;

        foreach (config('game.resources') as $code => $def) {
            $value = $resources[$code] ?? $def['start'];
            $resources[$code] = $this->clamp($value - $def['daily'], $def['max']);
        }

        $run->resources = $resources;
        $run->day = $run->day + 1;
        $run->save();

        return $run;
    }

    private function clamp(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }
}
