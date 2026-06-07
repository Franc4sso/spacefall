<?php

namespace App\Game\Engine;

use App\Game\SeededRng;

/**
 * Applies a declarative list of effects to a RunState.
 *
 * Deterministic given the RNG: any effect that needs a random target (e.g.
 * `character: "random"`) draws from the passed SeededRng, so the same seed +
 * cursor reproduces the same result. Resource deltas clamp to [0, max] (the
 * gate decision: state never goes negative; death is a separate Condition).
 *
 * Effects whose subsystems arrive in later phases (characters, relationships,
 * systems, recruit/kill, research points) are implemented defensively: they
 * operate on whatever state exists and no-op safely when it's empty, so an
 * event authored against future content can already be seeded without crashing.
 */
final class EffectApplier
{
    public function __construct(
        private readonly array $resourceMeta, // code => ['max' => int, ...]
    ) {
    }

    /**
     * @param  list<array<string,mixed>>  $effects
     */
    public function apply(array $effects, RunState $state, SeededRng $rng): void
    {
        foreach ($effects as $effect) {
            $this->applyOne($effect, $state, $rng);
        }
    }

    private function applyOne(array $effect, RunState $state, SeededRng $rng): void
    {
        if (array_key_exists('resource', $effect)) {
            $code = $effect['resource'];
            $max = $this->resourceMeta[$code]['max'] ?? 100;
            $current = $state->resources[$code] ?? 0;
            $state->resources[$code] = $this->clamp($current + (int) ($effect['delta'] ?? 0), $max);
            return;
        }

        if (array_key_exists('set_flag', $effect)) {
            $scope = $effect['scope'] ?? 'run';
            $value = $effect['value'] ?? true;
            if ($scope === 'profile') {
                $state->profileFlags[$effect['set_flag']] = $value;
            } else {
                $state->flags[$effect['set_flag']] = $value;
            }
            return;
        }

        if (array_key_exists('spawn_event', $effect)) {
            $spec = $effect['spawn_event'];
            $state->scheduledEvents[] = [
                'key' => $spec['key'],
                'fire_on_day' => $state->day + (int) ($spec['in_days'] ?? 1),
            ];
            return;
        }

        if (array_key_exists('character', $effect)) {
            $this->applyCharacter($effect, $state, $rng);
            return;
        }

        if (array_key_exists('relationship', $effect)) {
            $this->applyRelationship($effect['relationship'], $state);
            return;
        }

        if (array_key_exists('damage_system', $effect)) {
            $code = $effect['damage_system'];
            $eff = $state->systems[$code]['efficiency'] ?? 100;
            $state->systems[$code]['efficiency'] = $this->clamp($eff - (int) ($effect['amount'] ?? 0), 100);
            return;
        }

        if (array_key_exists('recruit', $effect)) {
            $state->characters[] = [
                'role' => $effect['recruit']['role'] ?? 'survivor',
                'alive' => true,
                'stress' => 0,
                'hunger' => 0,
                'traits' => [],
            ];
            return;
        }

        if (array_key_exists('kill', $effect)) {
            $this->applyKill($effect['kill'], $state, $rng);
            return;
        }

        if (array_key_exists('grant_research_points', $effect)) {
            // Profile-scoped meta currency lands in Phase 7; stash on state so
            // the daily pipeline can persist it then. No-op-safe until then.
            $state->profileFlags['__research_points'] =
                ($state->profileFlags['__research_points'] ?? 0) + (int) $effect['grant_research_points'];
            return;
        }

        if (array_key_exists('consume_item', $effect)) {
            $key = $effect['consume_item'];
            $state->items = array_values(array_filter($state->items, fn ($k) => $k !== $key));
            return;
        }

        if (array_key_exists('grant_item', $effect)) {
            if (! in_array($effect['grant_item'], $state->items, true)) {
                $state->items[] = $effect['grant_item'];
            }
            return;
        }

        if (array_key_exists('modify_trust', $effect)) {
            $current = (int) ($state->flags['crew_trust'] ?? 60);
            $state->flags['crew_trust'] = max(0, min(100, $current + (int) $effect['modify_trust']));
            return;
        }

        if (array_key_exists('modify_standing', $effect)) {
            $spec = $effect['modify_standing'];
            $key = 'standing_' . strtolower((string) ($spec['who'] ?? ''));
            $current = (int) ($state->flags[$key] ?? 0);
            $state->flags[$key] = $this->clampSigned($current + (int) ($spec['delta'] ?? 0), 100);
            return;
        }

        // Unknown effect: ignore (total, never throws). Malformed content is
        // caught at seed time by the validator, not at runtime.
    }

    private function applyCharacter(array $effect, RunState $state, SeededRng $rng): void
    {
        // "all" hits every living survivor — the rationing primitive: one swipe
        // weighs on the whole crew, and weighs MORE the more mouths there are.
        $indices = $effect['character'] === 'all'
            ? $this->livingIndices($state)
            : array_filter([$this->resolveTarget($effect['character'], $state, $rng)], fn ($i) => $i !== null);

        foreach ($indices as $index) {
            if (array_key_exists('stress', $effect)) {
                $cur = $state->characters[$index]['stress'] ?? 0;
                $state->characters[$index]['stress'] = $this->clamp($cur + (int) $effect['stress'], 100);
            }
            if (array_key_exists('hunger', $effect)) {
                $cur = $state->characters[$index]['hunger'] ?? 0;
                $state->characters[$index]['hunger'] = $this->clamp($cur + (int) $effect['hunger'], 100);
            }
        }
    }

    /** Present = alive and not currently away on an expedition. */
    private function isPresent(array $c, RunState $state): bool
    {
        return ($c['alive'] ?? true) && (int) ($c['away_until'] ?? 0) <= $state->day;
    }

    /** @return list<int> indices of present survivors (alive, not away) */
    private function livingIndices(RunState $state): array
    {
        $out = [];
        foreach ($state->characters as $i => $c) {
            if ($this->isPresent($c, $state)) {
                $out[] = $i;
            }
        }
        return $out;
    }

    private function applyKill(string $selector, RunState $state, SeededRng $rng): void
    {
        $index = $this->resolveTarget($selector, $state, $rng);
        if ($index !== null) {
            $state->characters[$index]['alive'] = false;
        }
    }

    /**
     * Resolve a character selector to an index into $state->characters, or null
     * if there is no living candidate.
     *   "random" | "lowest_loyalty" | "highest_stress" | "<name>"
     */
    private function resolveTarget(string $selector, RunState $state, SeededRng $rng): ?int
    {
        $living = [];
        foreach ($state->characters as $i => $c) {
            if ($this->isPresent($c, $state)) {
                $living[$i] = $c;
            }
        }
        if ($living === []) {
            return null;
        }

        return match ($selector) {
            'random' => array_keys($living)[$rng->nextInt(0, count($living) - 1)],
            'highest_stress' => $this->pickBy($living, fn ($c) => $c['stress'] ?? 0, max: true),
            'lowest_loyalty' => $this->pickBy($living, fn ($c) => $c['loyalty'] ?? 0, max: false),
            'hungriest' => $this->pickBy($living, fn ($c) => $c['hunger'] ?? 0, max: true),
            default => $this->pickByName($living, $selector),
        };
    }

    private function pickBy(array $living, callable $metric, bool $max): int
    {
        $bestIndex = array_key_first($living);
        $bestVal = $metric($living[$bestIndex]);
        foreach ($living as $i => $c) {
            $v = $metric($c);
            if (($max && $v > $bestVal) || (! $max && $v < $bestVal)) {
                $bestVal = $v;
                $bestIndex = $i;
            }
        }
        return $bestIndex;
    }

    private function pickByName(array $living, string $name): ?int
    {
        foreach ($living as $i => $c) {
            if (($c['name'] ?? null) === $name) {
                return $i;
            }
        }
        return null;
    }

    private function applyRelationship(array $spec, RunState $state): void
    {
        $a = $spec['a'] ?? null;
        $b = $spec['b'] ?? null;
        $delta = (int) ($spec['delta'] ?? 0);
        if ($a === null || $b === null) {
            return;
        }

        foreach ($state->relationships as $i => $rel) {
            if ($this->samePair($rel, $a, $b)) {
                $state->relationships[$i]['value'] = $this->clampSigned(($rel['value'] ?? 0) + $delta, 100);
                return;
            }
        }
        $state->relationships[] = ['a' => $a, 'b' => $b, 'value' => $this->clampSigned($delta, 100)];
    }

    private function samePair(array $rel, string $a, string $b): bool
    {
        return (($rel['a'] ?? null) === $a && ($rel['b'] ?? null) === $b)
            || (($rel['a'] ?? null) === $b && ($rel['b'] ?? null) === $a);
    }

    private function clamp(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }

    private function clampSigned(int $value, int $bound): int
    {
        return max(-$bound, min($bound, $value));
    }
}
