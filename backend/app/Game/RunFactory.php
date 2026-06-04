<?php

namespace App\Game;

use App\Models\Run;

/**
 * Starts new runs. Reads resource definitions from config/game.php — no
 * resource name is hard-coded here.
 */
final class RunFactory
{
    /**
     * @param  int|null  $seed  Explicit seed for reproducible runs (tests,
     *                          simulation harness). When null a seed is drawn;
     *                          random_int is fine here because the *seed itself*
     *                          need not be reproducible — everything derived
     *                          from it is.
     */
    public function create(?int $seed = null): Run
    {
        $seed ??= random_int(PHP_INT_MIN, PHP_INT_MAX);

        $resources = [];
        foreach (config('game.resources') as $code => $def) {
            $resources[$code] = $def['start'];
        }

        return Run::create([
            'seed' => $seed,
            'rng_cursor' => 0,
            'day' => 1,
            'resources' => $resources,
            'status' => 'active',
        ]);
    }
}
