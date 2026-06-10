<?php

namespace App\Game\Engine;

use InvalidArgumentException;

/**
 * Seed-time validator for event content. Content lives in data, so the safety
 * net is a loud, structural check at seed time: a malformed event row fails the
 * seeder rather than producing a silent runtime surprise. This guards Prime
 * Directive #4 (no fake/broken data ships) without putting validation on the
 * hot path.
 *
 * It checks shape and references the known vocabulary (resource codes, ops,
 * effect/condition kinds). It does NOT check game balance — that's the
 * simulation harness's job (Phase 10).
 */
final class EventSchema
{
    private const OPS = ['<', '<=', '=', '==', '!=', '>=', '>'];

    private const EFFECT_KEYS = [
        'resource', 'set_flag', 'spawn_event', 'character', 'relationship',
        'damage_system', 'recruit', 'kill', 'grant_research_points',
        'consume_item', 'grant_item', 'modify_trust', 'modify_standing', 'end_expedition',
    ];

    private const CONDITION_KEYS = [
        'all', 'any', 'not', 'resource', 'day', 'flag', 'has_item',
        'has_role', 'trait_present', 'relationship', 'system',
        'chosen', 'chosen_tag', 'not_chosen', 'standing', 'crew_hunger',
        'phase', 'phase_index', 'living_crew',
    ];

    /** @param list<string> $resourceCodes */
    public function __construct(private readonly array $resourceCodes)
    {
    }

    /**
     * Validate one event's raw attributes. Throws InvalidArgumentException with
     * the offending key on the first problem found.
     *
     * @param array<string,mixed> $event
     */
    public function validate(array $event): void
    {
        $key = $event['key'] ?? '(missing key)';

        foreach (['key', 'title', 'body', 'choices'] as $required) {
            if (! array_key_exists($required, $event)) {
                throw new InvalidArgumentException("Event {$key}: missing required field '{$required}'.");
            }
        }

        // Silent events (no choices) auto-advance in the frontend — allow empty array.
        if (! is_array($event['choices'])) {
            throw new InvalidArgumentException("Event {$key}: 'choices' must be an array.");
        }

        if (array_key_exists('requires', $event) && $event['requires'] !== null) {
            $this->validateCondition($event['requires'], $key);
        }

        foreach ($event['choices'] as $i => $choice) {
            $this->validateChoice($choice, $key, $i);
        }
    }

    private function validateChoice(mixed $choice, string $key, int $i): void
    {
        if (! is_array($choice) || ! isset($choice['label'])) {
            throw new InvalidArgumentException("Event {$key} choice {$i}: needs a 'label'.");
        }
        if (! isset($choice['outcomes']) || ! is_array($choice['outcomes']) || $choice['outcomes'] === []) {
            throw new InvalidArgumentException("Event {$key} choice {$i}: needs at least one outcome.");
        }
        if (isset($choice['requires'])) {
            $this->validateCondition($choice['requires'], $key);
        }
        foreach ($choice['outcomes'] as $j => $outcome) {
            if (! is_array($outcome)) {
                throw new InvalidArgumentException("Event {$key} choice {$i} outcome {$j}: not an object.");
            }
            foreach ($outcome['effects'] ?? [] as $k => $effect) {
                $this->validateEffect($effect, $key, "{$i}.{$j}.{$k}");
            }
        }
    }

    private function validateEffect(mixed $effect, string $key, string $path): void
    {
        if (! is_array($effect) || $effect === []) {
            throw new InvalidArgumentException("Event {$key} effect {$path}: not an object.");
        }
        $kind = array_key_first($effect);
        if (! in_array($kind, self::EFFECT_KEYS, true)) {
            throw new InvalidArgumentException("Event {$key} effect {$path}: unknown effect '{$kind}'.");
        }
        if ($kind === 'resource' && ! in_array($effect['resource'], $this->resourceCodes, true)) {
            throw new InvalidArgumentException("Event {$key} effect {$path}: unknown resource '{$effect['resource']}'.");
        }
    }

    private function validateCondition(mixed $cond, string $key): void
    {
        if (! is_array($cond) || $cond === []) {
            return; // empty/null is "always true" — valid.
        }
        $kind = array_key_first($cond);
        if (! in_array($kind, self::CONDITION_KEYS, true)) {
            throw new InvalidArgumentException("Event {$key} condition: unknown kind '{$kind}'.");
        }

        if (in_array($kind, ['all', 'any'], true)) {
            foreach ((array) $cond[$kind] as $sub) {
                $this->validateCondition($sub, $key);
            }
        } elseif ($kind === 'not') {
            $this->validateCondition($cond['not'], $key);
        } elseif ($kind === 'resource' && ! in_array($cond['resource'], $this->resourceCodes, true)) {
            throw new InvalidArgumentException("Event {$key} condition: unknown resource '{$cond['resource']}'.");
        }

        // Spot-check operators where present.
        $op = $cond['op'] ?? ($cond['day']['op'] ?? null);
        if ($op !== null && ! in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException("Event {$key} condition: unknown operator '{$op}'.");
        }
    }
}
