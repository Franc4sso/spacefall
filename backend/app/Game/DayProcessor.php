<?php

namespace App\Game;

use App\Models\Run;

/**
 * End-of-day processing — the full daily pipeline (Phase 5).
 *
 * Ordered steps, each pure and config-driven (no resource/system/trait name is
 * hard-coded). Run once per day, in this order:
 *
 *   1. Resource consumption      flat daily drain (config game.resources.daily)
 *   2. System degradation        efficiency decays; while a system sits below
 *                                its threshold it bleeds a resource (a failing
 *                                life-support quietly costs oxygen). This is the
 *                                compounding cost of neglect.
 *   3. Hardship stress           survivors gain stress while a resource is
 *                                critically low (scarcity wears people down).
 *   4. Stress-band behaviour     crossing INTO a higher stress band schedules
 *                                that survivor's self-initiated event (once, on
 *                                entry), via the normal scheduled-event path.
 *   5. Advance the day.
 *
 * All resource/efficiency/stress writes clamp to their valid ranges, so state
 * is internally consistent after any number of days.
 */
final class DayProcessor
{
    public function advance(Run $run): Run
    {
        $resources = $run->resources;
        $systems = $run->systems ?? [];
        $characters = $run->characters ?? [];
        $scheduled = $run->scheduled_events ?? [];

        // 1. Resource consumption.
        foreach (config('game.resources') as $code => $def) {
            $value = $resources[$code] ?? $def['start'];
            $resources[$code] = $this->clampResource($value - $def['daily'], $def['max']);
        }

        // 2. System degradation + below-threshold resource penalties.
        [$systems, $resources] = $this->degradeSystems($systems, $resources);

        // 3. Hardship stress from scarce resources.
        $characters = $this->applyHardship($characters, $resources);

        // 4. Stress-band self-initiated behaviour.
        [$characters, $scheduled] = $this->processStress($characters, $scheduled, $run->day);

        $run->resources = $resources;
        $run->systems = $systems;
        $run->characters = $characters;
        $run->scheduled_events = $scheduled;

        // 5. Advance day.
        $run->day = $run->day + 1;
        $run->save();

        return $run;
    }

    /**
     * @return array{0: array<string,array{efficiency:int}>, 1: array<string,int>}
     */
    private function degradeSystems(array $systems, array $resources): array
    {
        foreach (config('game.systems') as $key => $def) {
            $eff = $systems[$key]['efficiency'] ?? $def['start'];
            $eff = $this->clampResource($eff - $def['daily_decay'], 100);
            $systems[$key] = ['efficiency' => $eff];

            // Below threshold: bleed the named resource.
            if ($eff < ($def['penalty_below'] ?? 0) && isset($def['penalty'])) {
                $p = $def['penalty'];
                $code = $p['resource'];
                $max = config("game.resources.$code.max", 100);
                $resources[$code] = $this->clampResource(
                    ($resources[$code] ?? 0) + (int) $p['delta'],
                    $max,
                );
            }
        }

        return [$systems, $resources];
    }

    /**
     * @param  list<array<string,mixed>>  $characters
     * @param  array<string,int>  $resources
     * @return list<array<string,mixed>>
     */
    private function applyHardship(array $characters, array $resources): array
    {
        $totalStress = 0;
        foreach (config('game.hardship') as $rule) {
            if (($resources[$rule['resource']] ?? PHP_INT_MAX) <= $rule['at_or_below']) {
                $totalStress += (int) $rule['stress'];
            }
        }
        if ($totalStress === 0) {
            return $characters;
        }

        foreach ($characters as $i => $c) {
            if ($c['alive'] ?? true) {
                $characters[$i]['stress'] = $this->clampResource(
                    (int) ($c['stress'] ?? 0) + $totalStress,
                    100,
                );
            }
        }

        return $characters;
    }

    /**
     * @param  list<array<string,mixed>>  $characters
     * @param  list<array{key:string,fire_on_day:int}>  $scheduled
     * @return array{0: list<array<string,mixed>>, 1: list<array{key:string,fire_on_day:int}>}
     */
    private function processStress(array $characters, array $scheduled, int $day): array
    {
        $bands = config('game.stress_bands');

        foreach ($characters as $i => $c) {
            if (! ($c['alive'] ?? true)) {
                continue;
            }
            $newBand = $this->bandIndex((int) ($c['stress'] ?? 0), $bands);
            $oldBand = $c['stress_band'] ?? 0;

            if ($newBand > $oldBand && ($spawn = $bands[$newBand]['spawn'] ?? null) !== null) {
                $scheduled[] = ['key' => $spawn, 'fire_on_day' => $day + 1];
            }

            $characters[$i]['stress_band'] = $newBand;
        }

        return [$characters, $scheduled];
    }

    /** Highest band whose `min` the stress meets. */
    private function bandIndex(int $stress, array $bands): int
    {
        $index = 0;
        foreach ($bands as $i => $band) {
            if ($stress >= ($band['min'] ?? 0)) {
                $index = $i;
            }
        }
        return $index;
    }

    private function clampResource(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }
}
