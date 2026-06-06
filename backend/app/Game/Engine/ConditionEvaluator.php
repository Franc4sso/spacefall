<?php

namespace App\Game\Engine;

/**
 * Evaluates a declarative condition tree against a RunState.
 *
 * Pure, total, side-effect-free. "Total" is a hard requirement: any structurally
 * valid condition — and a few invalid shapes — must produce a boolean, never an
 * exception, because the Selector evaluates conditions across the whole event
 * table on every card draw and must never blow up mid-run (build prompt §1.5).
 *
 * Grammar (build prompt §3):
 *   { all: [Cond...] } | { any: [Cond...] } | { not: Cond }
 *   { resource, op, value }
 *   { day: { op, value } }
 *   { has_role } | { has_item } | { trait_present }
 *   { flag, scope?, is }
 *   { relationship: { state, scope } }
 *   { system, field, op, value }
 *
 * A null / empty condition means "always true" — that's how filler events and
 * choices with no gate are expressed.
 */
final class ConditionEvaluator
{
    public function evaluate(?array $condition, RunState $state): bool
    {
        // No condition => always eligible.
        if ($condition === null || $condition === []) {
            return true;
        }

        // Boolean combinators.
        if (array_key_exists('all', $condition)) {
            foreach ((array) $condition['all'] as $sub) {
                if (! $this->evaluate($sub, $state)) {
                    return false;
                }
            }
            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach ((array) $condition['any'] as $sub) {
                if ($this->evaluate($sub, $state)) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('not', $condition)) {
            return ! $this->evaluate($condition['not'], $state);
        }

        // Leaf predicates.
        if (array_key_exists('resource', $condition)) {
            $have = $state->resources[$condition['resource']] ?? 0;
            return $this->compare($have, $condition['op'] ?? '=', $condition['value'] ?? 0);
        }

        if (array_key_exists('day', $condition)) {
            $spec = $condition['day'];
            return $this->compare($state->day, $spec['op'] ?? '=', $spec['value'] ?? 0);
        }

        if (array_key_exists('flag', $condition)) {
            $scope = $condition['scope'] ?? 'run';
            $bag = $scope === 'profile' ? $state->profileFlags : $state->flags;
            $expected = $condition['is'] ?? true;
            $actual = $bag[$condition['flag']] ?? false;
            return $actual === $expected;
        }

        if (array_key_exists('has_item', $condition)) {
            return in_array($condition['has_item'], $state->items, true);
        }

        if (array_key_exists('has_role', $condition)) {
            foreach ($state->characters as $c) {
                if (($c['role'] ?? null) === $condition['has_role'] && ($c['alive'] ?? true)) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('trait_present', $condition)) {
            foreach ($state->characters as $c) {
                if (($c['alive'] ?? true) && in_array($condition['trait_present'], $c['traits'] ?? [], true)) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('relationship', $condition)) {
            return $this->evaluateRelationship($condition['relationship'], $state);
        }

        if (array_key_exists('system', $condition)) {
            $field = $state->systems[$condition['system']][$condition['field'] ?? ''] ?? 0;
            return $this->compare($field, $condition['op'] ?? '=', $condition['value'] ?? 0);
        }

        if (array_key_exists('chosen', $condition)) {
            [$eventKey, $indexStr] = array_pad(explode(':', $condition['chosen'], 2), 2, '0');
            $index = (int) $indexStr;
            foreach ($state->choiceLog as $entry) {
                if (($entry['event_key'] ?? null) === $eventKey && ($entry['choice_index'] ?? -1) === $index) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('chosen_tag', $condition)) {
            $tag = $condition['chosen_tag'];
            foreach ($state->choiceLog as $entry) {
                if (in_array($tag, $entry['tags'] ?? [], true)) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('not_chosen', $condition)) {
            [$eventKey, $indexStr] = array_pad(explode(':', $condition['not_chosen'], 2), 2, '0');
            $index = (int) $indexStr;
            foreach ($state->choiceLog as $entry) {
                if (($entry['event_key'] ?? null) === $eventKey && ($entry['choice_index'] ?? -1) === $index) {
                    return false;
                }
            }
            return true;
        }

        // Unknown shape: fail closed (not eligible) rather than throw.
        return false;
    }

    /**
     * Relationship states are named bands over a numeric value:
     *   hatred < -40, tension < -10, neutral, bond > 10, devotion > 40.
     * scope "any" => some pair is in that band.
     */
    private function evaluateRelationship(array $spec, RunState $state): bool
    {
        $state_name = $spec['state'] ?? 'neutral';
        foreach ($state->relationships as $rel) {
            if ($this->relationshipBand($rel['value'] ?? 0) === $state_name) {
                return true;
            }
        }
        return false;
    }

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

    private function compare(int|float $left, string $op, int|float $right): bool
    {
        return match ($op) {
            '<' => $left < $right,
            '<=' => $left <= $right,
            '=', '==' => $left === $right,
            '!=' => $left !== $right,
            '>=' => $left >= $right,
            '>' => $left > $right,
            default => false, // unknown operator: fail closed
        };
    }
}
