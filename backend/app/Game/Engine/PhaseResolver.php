<?php

namespace App\Game\Engine;

/**
 * Computes the current run phase. Pure and config-driven: the phase is the
 * highest (most advanced) of three inputs — the day band, the resource-pressure
 * band, and a persisted monotonic floor. A run that recovers never drops back to
 * a calmer phase. No phase name or threshold is hard-coded; all live in
 * config('game.phases').
 */
final class PhaseResolver
{
    /** @return list<string> canonical phase order */
    private function order(): array
    {
        return config('game.phases.order', ['isolation']);
    }

    public function indexOf(string $phase): int
    {
        $i = array_search($phase, $this->order(), true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * @param  array<string,int>  $resources  code => value
     */
    public function resolve(int $day, array $resources, string $floor): string
    {
        $candidates = [
            $this->dayBand($day),
            $this->pressureBand($resources),
            $floor,
        ];

        $best = 0;
        foreach ($candidates as $phase) {
            $best = max($best, $this->indexOf($phase));
        }

        return $this->order()[$best] ?? $this->order()[0];
    }

    private function dayBand(int $day): string
    {
        $phase = $this->order()[0];
        foreach (config('game.phases.day_bands', []) as $band) {
            if ($day >= (int) ($band['from_day'] ?? 1)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }

    /**
     * @param  array<string,int>  $resources
     */
    private function pressureBand(array $resources): string
    {
        $cfg = config('game.phases.pressure', []);
        $threshold = (int) ($cfg['critical_at_or_below'] ?? 0);

        $critical = 0;
        foreach ($resources as $value) {
            if ((int) $value <= $threshold) {
                $critical++;
            }
        }

        $phase = $this->order()[0];
        foreach ($cfg['bands'] ?? [] as $band) {
            if ($critical >= (int) ($band['min_critical'] ?? PHP_INT_MAX)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }
}
