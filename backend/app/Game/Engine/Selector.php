<?php

namespace App\Game\Engine;

use App\Models\Event;
use App\Game\SeededRng;
use Illuminate\Support\Collection;

/**
 * Picks the next card.
 *
 * Contract (build prompt §1.5): MUST always return an Event. An empty hand is a
 * hard bug. Resolution order:
 *
 *   1. Scheduled events firing today (the "fair failure" consequences a past
 *      choice queued). These are forced — they jump the queue — and chosen
 *      among themselves by weight.
 *   2. Otherwise, the normal eligible pool: events whose `requires` is satisfied
 *      and that are not on cooldown, weighted-random by base_weight (×modifiers,
 *      which arrive in Phase 3).
 *   3. If (2) is empty, the guaranteed filler pool (always-eligible, low-stakes).
 *   4. If even filler is somehow empty, fall back to ANY filler ignoring
 *      cooldown, then to ANY event at all. The method cannot return null.
 */
final class Selector
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
    ) {
    }

    /**
     * @param  Collection<int,Event>  $events  the full event pool
     */
    public function select(Collection $events, RunState $state, SeededRng $rng): Event
    {
        // 1. Forced scheduled events due today.
        $dueKeys = collect($state->scheduledEvents)
            ->filter(fn ($s) => ($s['fire_on_day'] ?? PHP_INT_MAX) <= $state->day)
            ->pluck('key')
            ->all();

        if ($dueKeys !== []) {
            $due = $events->filter(fn (Event $e) => in_array($e->key, $dueKeys, true) && $this->speakerAlive($e, $state));
            if ($due->isNotEmpty()) {
                return $this->weightedPick($due, $state, $rng);
            }
        }

        // 2. Normal eligible pool.
        $eligible = $events->filter(
            fn (Event $e) => ! $e->is_filler
                && $this->isEligible($e, $state)
                && ! $this->onCooldown($e, $state)
        );

        if ($eligible->isNotEmpty()) {
            return $this->weightedPick($eligible, $state, $rng);
        }

        // 3. Filler pool (respecting cooldown first).
        $filler = $events->filter(
            fn (Event $e) => $e->is_filler
                && $this->isEligible($e, $state)
                && ! $this->onCooldown($e, $state)
        );
        if ($filler->isNotEmpty()) {
            return $this->weightedPick($filler, $state, $rng);
        }

        // 4. Last-resort guarantees — never return null.
        $anyFiller = $events->filter(fn (Event $e) => $e->is_filler);
        if ($anyFiller->isNotEmpty()) {
            return $this->weightedPick($anyFiller, $state, $rng);
        }

        if ($events->isEmpty()) {
            throw new \RuntimeException('Selector: event pool is empty — seed events before selecting.');
        }

        return $this->weightedPick($events, $state, $rng);
    }

    private function isEligible(Event $event, RunState $state): bool
    {
        return $this->speakerAlive($event, $state)
            && $this->evaluator->evaluate($event->requires, $state);
    }

    /**
     * An event with a named speaker may only fire while that speaker is alive.
     * Narrator events (speaker null) are never gated by this rule.
     */
    private function speakerAlive(Event $event, RunState $state): bool
    {
        $speaker = $event->speaker ?? null;
        if ($speaker === null || $speaker === '') {
            return true;
        }
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $speaker) {
                return (bool) ($c['alive'] ?? true);
            }
        }
        return true; // speaker named but not in roster — defensive let-through
    }

    private function onCooldown(Event $event, RunState $state): bool
    {
        if (($event->cooldown_days ?? 0) <= 0) {
            return false;
        }
        $last = $state->recentEvents[$event->key] ?? null;
        if ($last === null) {
            return false;
        }
        return ($state->day - $last) < $event->cooldown_days;
    }

    /**
     * @param  Collection<int,Event>  $pool
     */
    private function weightedPick(Collection $pool, RunState $state, SeededRng $rng): Event
    {
        $weights = [];
        $byKey = [];
        foreach ($pool as $event) {
            $w = max(1, $this->weightFor($event, $state));
            $weights[$event->key] = $w;
            $byKey[$event->key] = $event;
        }

        $chosenKey = $rng->weightedPick($weights);
        return $byKey[$chosenKey];
    }

    /**
     * Effective weight. Phase 2 returns base_weight; trait / relationship / item
     * modifiers multiply in via the event's `weight_modifiers` (data-driven,
     * evaluated with the same Condition DSL — no per-event code here).
     */
    private function weightFor(Event $event, RunState $state): int
    {
        $weight = (float) $event->base_weight;

        foreach ($event->weight_modifiers ?? [] as $mod) {
            if ($this->evaluator->evaluate($mod['when'] ?? null, $state)) {
                $weight *= (float) ($mod['factor'] ?? 1.0);
            }
        }

        return max(1, (int) round($weight));
    }
}
