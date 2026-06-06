<?php

namespace App\Game;

use App\Models\Profile;
use App\Models\Run;

/**
 * Starts new runs. Reads resource definitions from config/game.php — no
 * resource name is hard-coded here.
 */
final class RunFactory
{
    /**
     * @param  int|null      $seed      Explicit seed for reproducible runs
     *                                  (tests, simulation harness). When null a
     *                                  seed is drawn; random_int is fine because
     *                                  the *seed itself* need not be reproducible.
     * @param  list<string>  $itemKeys  The player's pick. Unknown keys dropped,
     *                                  duplicates collapsed, locked items the
     *                                  profile hasn't unlocked dropped, capped at
     *                                  items_pick.
     * @param  Profile|null  $profile   Owning profile; gates locked items and
     *                                  links the run for cross-run memory.
     */
    public function create(?int $seed = null, array $itemKeys = [], ?Profile $profile = null): Run
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
            'items' => $this->sanitiseItems($itemKeys, $profile),
            'systems' => $this->systems(),
            'profile_id' => $profile?->id,
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
    private function sanitiseItems(array $itemKeys, ?Profile $profile): array
    {
        $pick = (int) config('game.items_pick');
        $available = $this->availableItemKeys($profile);

        $valid = array_values(array_unique(array_filter(
            $itemKeys,
            fn ($k) => in_array($k, $available, true),
        )));

        return array_slice($valid, 0, $pick);
    }

    /**
     * Item keys a profile may pick: all unlocked items, plus locked items whose
     * unlock the profile owns. The single source of truth for both the factory
     * and the /api/items pick screen.
     *
     * @return list<string>
     */
    public function availableItemKeys(?Profile $profile): array
    {
        $unlocked = $profile?->unlocks ?? [];
        // Map: locked item key => unlock key that grants it.
        $grantedBy = [];
        foreach (config('game.unlocks') as $u) {
            if (isset($u['grants_item'])) {
                $grantedBy[$u['grants_item']] = $u['key'];
            }
        }

        $keys = [];
        foreach (config('game.items') as $item) {
            if (! ($item['locked'] ?? false)) {
                $keys[] = $item['key'];
                continue;
            }
            // Locked: include only if its granting unlock is owned.
            $unlockKey = $grantedBy[$item['key']] ?? null;
            if ($unlockKey !== null && in_array($unlockKey, $unlocked, true)) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
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
