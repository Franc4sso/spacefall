<?php

namespace App\Game\Engine;

use App\Game\SeededRng;

/**
 * Decides how an expedition turns out. Pure and deterministic given the RNG.
 *
 * `score()` is a risk number (lower = safer) from the destination danger, the
 * expeditioner's state (stress/hunger), gear carried, the duration, and traits.
 * `resolve()` maps that risk onto weighted outcome tiers and draws one.
 */
final class ExpeditionResolver
{
    private const TIERS = ['rich', 'modest', 'wounded', 'lost', 'discovery'];

    /** Items that make a trip safer. */
    private const GEAR = ['spacesuit', 'scanner', 'drone', 'medkit'];

    /** Lower = safer. */
    public function score(string $who, int $days, int $danger, RunState $state): int
    {
        $char = $this->findByName($who, $state);
        $stress = (int) ($char['stress'] ?? 0);
        $hunger = (int) ($char['hunger'] ?? 0);
        $traits = $char['traits'] ?? [];

        $gear = 0;
        foreach (self::GEAR as $item) {
            if (in_array($item, $state->items, true)) {
                $gear++;
            }
        }

        $traitShift = 0;
        if (in_array('lucky', $traits, true)) {
            $traitShift -= 4;
        }
        if (in_array('reckless', $traits, true)) {
            $traitShift += 4;
        }

        return ($danger * 6)
            + (int) (($stress + $hunger) / 10)
            + max(0, $days - 2) * 2
            - ($gear * 4)
            + $traitShift
            + $this->relationshipRisk($who, $state);
    }

    /**
     * Risk nudge from the expeditioner's relationships with crew who stay behind.
     * Hatred with a stayer frays the ship (risk up); a bond steadies it (risk
     * down). Summed over staying members; neutral pairs contribute nothing, so an
     * all-neutral run scores exactly as before.
     */
    private function relationshipRisk(string $who, RunState $state): int
    {
        $mag = (int) config('game.relationships.expedition_risk', 0);
        if ($mag === 0) {
            return 0;
        }

        $risk = 0;
        foreach ($state->relationships as $rel) {
            $other = $this->otherInPair($rel, $who);
            if ($other === null) {
                continue;
            }
            $band = $this->relationshipBand((int) ($rel['value'] ?? 0));
            $risk += match ($band) {
                'hatred' => $mag * 2,
                'tension' => $mag,
                'bond' => -$mag,
                'devotion' => -$mag * 2,
                default => 0,
            };
        }
        return $risk;
    }

    /** If $who is in the pair, return the other member's name; else null. */
    private function otherInPair(array $rel, string $who): ?string
    {
        if (($rel['a'] ?? null) === $who) {
            return $rel['b'] ?? null;
        }
        if (($rel['b'] ?? null) === $who) {
            return $rel['a'] ?? null;
        }
        return null;
    }

    /** Band name for a relationship value. Mirrors ConditionEvaluator::relationshipBand. */
    private function relationshipBand(int $value): string
    {
        return match (true) {
            $value < -40 => 'hatred',
            $value < -10 => 'tension',
            $value > 40 => 'devotion',
            $value > 10 => 'bond',
            default => 'neutral',
        };
    }

    /** Draw an outcome tier weighted by the risk score. */
    public function resolve(string $who, int $days, int $danger, RunState $state, SeededRng $rng): string
    {
        $risk = $this->score($who, $days, $danger, $state);

        // Base weights at neutral risk, then bend by risk: high risk pushes
        // toward wounded/lost, low risk toward rich/discovery.
        $weights = [
            'rich'      => max(1, 6 - $risk),
            'modest'    => 5,
            'wounded'   => max(1, 2 + (int) ($risk / 2)),
            'lost'      => max(1, 1 + (int) ($risk / 3)),
            'discovery' => max(1, 4 - (int) ($risk / 2)),
        ];

        return $rng->weightedPick($weights);
    }

    /** @return array<string,mixed> */
    private function findByName(string $who, RunState $state): array
    {
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $who) {
                return $c;
            }
        }
        return [];
    }
}
