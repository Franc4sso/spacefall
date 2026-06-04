<?php

namespace App\Game;

use App\Models\Run;

/**
 * End-of-day processing.
 *
 * Pipeline (one method, one day) — thickened as phases land:
 *   1. Resource consumption (config daily drain), clamped [0, max].
 *   2. Stress bands → self-initiated survivor behaviour: when a living survivor
 *      crosses INTO a higher stress band, schedule that band's event (it fires
 *      through the normal scheduled-event path — no special-casing). Tracked via
 *      a per-survivor `stress_band` so it triggers on entry, not every day.
 *   3. Advance the day counter.
 *
 * Phase 5 adds system degradation here. Resource/character/stress names are all
 * config-driven; no name is hard-coded.
 */
final class DayProcessor
{
    public function advance(Run $run): Run
    {
        // 1. Resource consumption.
        $resources = $run->resources;
        foreach (config('game.resources') as $code => $def) {
            $value = $resources[$code] ?? $def['start'];
            $resources[$code] = $this->clamp($value - $def['daily'], $def['max']);
        }
        $run->resources = $resources;

        // 2. Stress-driven self-initiated behaviour.
        [$characters, $scheduled] = $this->processStress(
            $run->characters ?? [],
            $run->scheduled_events ?? [],
            $run->day,
        );
        $run->characters = $characters;
        $run->scheduled_events = $scheduled;

        // 3. Advance day.
        $run->day = $run->day + 1;
        $run->save();

        return $run;
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

            // Crossed UP into a new band that has a behaviour: schedule it.
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

    private function clamp(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }
}
