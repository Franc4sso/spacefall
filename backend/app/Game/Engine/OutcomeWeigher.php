<?php

namespace App\Game\Engine;

use App\Game\ThemeConfig;

/**
 * Adjusts outcome-branch weights by the speaker's luck-shifting traits.
 *
 * Each outcome is classified as "good" or "bad" by its net resource effect
 * (net negative => bad). A speaker trait with luck_shift > 1 (e.g. Lucky)
 * multiplies good outcomes' weights and divides bad ones; < 1 (e.g. Reckless)
 * does the reverse. Over many seeds this shifts the realised distribution
 * measurably — a Lucky survivor's gambles pay off more often — with no
 * per-event authoring. Pure and deterministic.
 */
final class OutcomeWeigher
{
    public function __construct(private readonly ThemeConfig $theme)
    {
    }

    /**
     * Return outcome weights (parallel to $outcomes) after applying the
     * speaker's luck shift. Falls back to base weights when no shift applies.
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,mixed>|null   $speaker
     * @return list<int>
     */
    public function weights(array $outcomes, ?array $speaker, string $theme = 'space'): array
    {
        $traits = $this->theme->for($theme)->get('traits', []);
        $shift = $this->luckShift($speaker, $traits);

        $weights = [];
        foreach ($outcomes as $o) {
            $base = max(1, (int) ($o['weight'] ?? 1));
            if ($shift === 1.0) {
                $weights[] = $base;
                continue;
            }
            // good outcome (net resource >= 0): scale up by shift; bad: down.
            $net = $this->netResource($o);
            $factor = $net >= 0 ? $shift : (1.0 / $shift);
            $weights[] = max(1, (int) round($base * $factor));
        }
        return $weights;
    }

    private function luckShift(?array $speaker, array $traits): float
    {
        if ($speaker === null) {
            return 1.0;
        }
        // Combine multiplicatively if a speaker has several luck traits.
        $shift = 1.0;
        foreach ($speaker['traits'] ?? [] as $trait) {
            $shift *= (float) ($traits[$trait]['luck_shift'] ?? 1.0);
        }
        return $shift;
    }

    private function netResource(array $outcome): int
    {
        $net = 0;
        foreach ($outcome['effects'] ?? [] as $e) {
            if (array_key_exists('resource', $e)) {
                $net += (int) ($e['delta'] ?? 0);
            }
        }
        return $net;
    }
}
