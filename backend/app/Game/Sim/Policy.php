<?php

namespace App\Game\Sim;

/**
 * A simulation policy decides which choice the auto-player picks on a card,
 * given the visible choices (label/hint/available). Policies see only what a
 * human sees — never hidden weights — so the harness measures the game as
 * actually played.
 */
interface Policy
{
    public function name(): string;

    /**
     * @param  list<array{index:int,label:string,hint:?string,available:bool}>  $choices
     * @param  \App\Game\SeededRng  $rng  the run's RNG (so policy choices are
     *                                    reproducible per seed)
     * @return int  the chosen choice index (must be an available one)
     */
    public function pick(array $choices, \App\Game\SeededRng $rng): int;
}
