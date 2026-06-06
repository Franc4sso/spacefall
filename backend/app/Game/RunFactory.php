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
    /**
     * @param  list<string>  $itemKeys  the player's pick. Unknown keys are
     *                                   dropped, duplicates collapsed, and the
     *                                   list is capped at config items_pick.
     */
    public function create(?int $seed = null, array $itemKeys = []): Run
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
            'characters' => $this->roster(),
            'relationships' => [],
            'items' => $this->sanitiseItems($itemKeys),
            'systems' => $this->systems(),
        ]);
    }

    /**
     * Initialise station systems at their configured starting efficiency.
     *
     * @return array<string,array{efficiency:int}>
     */
    private function systems(): array
    {
        $systems = [];
        foreach (config('game.systems') as $key => $def) {
            $systems[$key] = ['efficiency' => $def['start']];
        }
        return $systems;
    }

    /**
     * Keep only known item keys, de-duplicated, capped at the pick count. The
     * factory is the single gate that guarantees a run never holds a bogus or
     * over-sized inventory, so the engine can trust `has_item` blindly.
     *
     * @param  list<string>  $itemKeys
     * @return list<string>
     */
    private function sanitiseItems(array $itemKeys): array
    {
        $known = array_column(config('game.items'), 'key');
        $pick = (int) config('game.items_pick');

        $valid = array_values(array_unique(array_filter(
            $itemKeys,
            fn ($k) => in_array($k, $known, true),
        )));

        return array_slice($valid, 0, $pick);
    }

    /**
     * Build the starting survivors from config. Stress starts at 0, everyone
     * alive. Relationships start empty (neutral) and form through play.
     *
     * @return list<array<string,mixed>>
     */
    private function roster(): array
    {
        $roster = [];
        foreach (config('game.roster') as $member) {
            $roster[] = [
                'name' => $member['name'],
                'role' => $member['role'],
                'traits' => $member['traits'] ?? [],
                'stress' => 0,
                'alive' => true,
            ];
        }
        return $roster;
    }
}
