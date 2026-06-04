<?php

namespace App\Game\Engine;

use App\Models\Event;
use App\Models\Run;
use RuntimeException;

/**
 * Orchestrates the three pure services around a persisted Run: presents the
 * current card and resolves a chosen option. This is the only engine class that
 * touches Eloquent / persistence; Selector, ConditionEvaluator, EffectApplier
 * stay pure and headlessly testable.
 */
final class EventEngine
{
    public function __construct(
        private readonly Selector $selector,
        private readonly ConditionEvaluator $evaluator,
        private readonly EffectApplier $applier,
    ) {
    }

    /**
     * The card the player currently faces. Picks (and pins) one if none is
     * pinned yet. Always returns an event with its presently-available choices.
     *
     * @return array{event: Event, choices: list<array<string,mixed>>}
     */
    public function currentCard(Run $run): array
    {
        $state = RunState::fromRun($run);

        $event = $run->current_event_key
            ? Event::where('key', $run->current_event_key)->first()
            : null;

        if (! $event) {
            $pool = Event::all();

            // No content at all (e.g. a run created before any events are
            // seeded): degrade to "no card" rather than 500. With content
            // present the Selector's filler guarantee means this never happens
            // during real play.
            if ($pool->isEmpty()) {
                return ['event' => null, 'choices' => []];
            }

            // Pick a card, advancing the run's RNG cursor exactly once, and pin
            // it so a reload returns the same card (the player must not get a
            // re-roll by refreshing).
            $rng = $run->rng();
            $event = $this->selector->select($pool, $state, $rng);
            $run->current_event_key = $event->key;
            $run->syncRng($rng);
            $run->save();
        }

        return [
            'event' => $event,
            'choices' => $this->visibleChoices($event, $state),
        ];
    }

    /**
     * Resolve the pinned card's choice by index. Picks an outcome branch via the
     * run's seeded RNG, applies its effects, records cooldown, consumes any
     * scheduled entry for this event, and unpins the card.
     *
     * @return array{log: string, effects: list<array<string,mixed>>}
     */
    public function resolveChoice(Run $run, int $choiceIndex): array
    {
        if (! $run->current_event_key) {
            throw new RuntimeException('No card is currently presented.');
        }

        $event = Event::where('key', $run->current_event_key)->firstOrFail();
        $state = RunState::fromRun($run);

        $choice = $event->choices[$choiceIndex] ?? null;
        if ($choice === null) {
            throw new RuntimeException("Choice {$choiceIndex} does not exist on event {$event->key}.");
        }
        if (! $this->evaluator->evaluate($choice['requires'] ?? null, $state)) {
            throw new RuntimeException("Choice {$choiceIndex} is not available in the current state.");
        }

        $rng = $run->rng();
        $outcome = $this->pickOutcome($choice['outcomes'] ?? [], $rng);

        $this->applier->apply($outcome['effects'] ?? [], $state, $rng);

        // Record cooldown and consume a scheduled occurrence if present.
        $state->recentEvents[$event->key] = $state->day;
        $state->scheduledEvents = array_values(array_filter(
            $state->scheduledEvents,
            fn ($s) => ($s['key'] ?? null) !== $event->key,
        ));

        // Persist.
        $state->applyTo($run);
        $run->syncRng($rng);
        $run->current_event_key = null;
        $run->save();

        return [
            'log' => $outcome['log'] ?? '',
            'effects' => $outcome['effects'] ?? [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visibleChoices(Event $event, RunState $state): array
    {
        $out = [];
        foreach ($event->choices as $index => $choice) {
            $available = $this->evaluator->evaluate($choice['requires'] ?? null, $state);
            $out[] = [
                'index' => $index,
                'label' => $choice['label'] ?? '',
                'hint' => $choice['hint'] ?? null,
                'available' => $available,
            ];
        }
        return $out;
    }

    /**
     * Weighted-random outcome branch. Single-branch choices resolve directly.
     */
    private function pickOutcome(array $outcomes, \App\Game\SeededRng $rng): array
    {
        if ($outcomes === []) {
            return ['effects' => [], 'log' => ''];
        }
        if (count($outcomes) === 1) {
            return $outcomes[0];
        }

        $weights = [];
        foreach ($outcomes as $i => $o) {
            $weights[$i] = max(1, (int) ($o['weight'] ?? 1));
        }
        return $outcomes[$rng->weightedPick($weights)];
    }
}
