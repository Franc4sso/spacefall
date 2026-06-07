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
            + $traitShift;
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
