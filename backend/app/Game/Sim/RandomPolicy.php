<?php

namespace App\Game\Sim;

use App\Game\SeededRng;

/** Picks uniformly at random among the available choices. The floor of skill. */
final class RandomPolicy implements Policy
{
    public function name(): string
    {
        return 'random';
    }

    public function pick(array $choices, SeededRng $rng): int
    {
        $available = array_values(array_filter($choices, fn ($c) => $c['available']));
        $chosen = $available[$rng->nextInt(0, count($available) - 1)];
        return $chosen['index'];
    }
}
