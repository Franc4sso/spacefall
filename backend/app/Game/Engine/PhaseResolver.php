<?php

namespace App\Game\Engine;

use App\Game\ThemeConfig;

/**
 * Computes the current run phase. Pure and config-driven: the phase is the
 * highest (most advanced) of three inputs — the day band, the resource-pressure
 * band, and a persisted monotonic floor. A run that recovers never drops back to
 * a calmer phase. No phase name or threshold is hard-coded; all live in
 * config('game.phases').
 */
final class PhaseResolver
{
    public function __construct(
        private readonly ThemeConfig $theme = new ThemeConfig(),
    ) {
    }

    /** @return list<string> canonical phase order */
    private function order(string $theme = 'space'): array
    {
        return $this->theme->for($theme)->get('phases.order', ['isolation']);
    }

    public function indexOf(string $phase, string $theme = 'space'): int
    {
        $i = array_search($phase, $this->order($theme), true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * @param  array<string,int>  $resources  code => value
     */
    public function resolve(int $day, array $resources, string $floor, string $theme = 'space'): string
    {
        $candidates = [
            $this->dayBand($day, $theme),
            $this->pressureBand($resources, $theme),
            $floor,
        ];

        $best = 0;
        foreach ($candidates as $phase) {
            $best = max($best, $this->indexOf($phase, $theme));
        }

        return $this->order($theme)[$best] ?? $this->order($theme)[0];
    }

    private function dayBand(int $day, string $theme = 'space'): string
    {
        $phase = $this->order($theme)[0];
        foreach ($this->theme->for($theme)->get('phases.day_bands', []) as $band) {
            if ($day >= (int) ($band['from_day'] ?? 1)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }

    /**
     * @param  array<string,int>  $resources
     */
    private function pressureBand(array $resources, string $theme = 'space'): string
    {
        $cfg = $this->theme->for($theme)->get('phases.pressure', []);
        $threshold = (int) ($cfg['critical_at_or_below'] ?? 0);

        $critical = 0;
        foreach ($resources as $value) {
            if ((int) $value <= $threshold) {
                $critical++;
            }
        }

        $phase = $this->order($theme)[0];
        foreach ($cfg['bands'] ?? [] as $band) {
            if ($critical >= (int) ($band['min_critical'] ?? PHP_INT_MAX)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }
}
