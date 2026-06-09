<?php

namespace App\Game\Engine;

use App\Game\Engine\EpithetEngine;
use App\Game\Engine\TrustEngine;
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
        private readonly HintService $hints,
        private readonly OutcomeWeigher $weigher,
        private readonly EndingService $endings,
        private readonly ProfileSync $profileSync,
        private readonly EpithetEngine $epithet,
        private readonly TrustEngine $trust,
        private readonly ReactionDeriver $reactions,
        private readonly ExpeditionResolver $expeditions,
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
        // An ended run shows no card — the run is over.
        if ($run->status !== 'active') {
            return ['event' => null, 'choices' => []];
        }

        $state = RunState::fromRun($run);

        if (! $run->current_event_key && $this->trust->shouldMutiny($state)) {
            $mutinyEvent = Event::where('key', $this->trust->mutinyEventKey())->first();
            if ($mutinyEvent) {
                // Reset trust to prevent infinite loop
                $run->flags = array_merge($run->flags ?? [], ['crew_trust' => 25]);
                $run->current_event_key = $mutinyEvent->key;
                $run->save();
                return ['event' => $mutinyEvent, 'choices' => $this->visibleChoices($mutinyEvent, $state)];
            }
        }

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
        $speaker = $this->resolveSpeaker($event, $state);
        $outcome = $this->pickOutcome($choice['outcomes'] ?? [], $speaker, $rng);

        $deathsBefore = count($state->deathLog);

        $this->applier->apply($outcome['effects'] ?? [], $state, $rng, [
            'event_key' => $event->key,
            'day' => $state->day,
            'cause' => 'event',
        ]);

        if (count($state->deathLog) > $deathsBefore) {
            $state->scheduledEvents[] = ['key' => 'death_notice', 'fire_on_day' => $state->day];
        }

        // Crew reactions (spoken memory). Explicit on the outcome, else derived.
        $reactions = $this->reactions->derive($choice, $outcome, $state);
        foreach ($reactions as $r) {
            $delta = $this->reactions->standingDelta($r['tone'] ?? '');
            if ($delta !== 0) {
                $key = 'standing_' . strtolower((string) ($r['who'] ?? ''));
                $current = (int) ($state->flags[$key] ?? 0);
                $state->flags[$key] = max(-100, min(100, $current + $delta));
            }
        }

        // Expedition dispatch: a choice may send a crew member away. Mark them
        // away, stash the return params, roll the outcome tier, and schedule
        // the matching return event (forced — it cannot be lost in the pool).
        if (! empty($choice['expedition'])) {
            $exp = $choice['expedition'];
            $who = (string) ($exp['who'] ?? '');
            $days = max(1, (int) ($exp['days'] ?? 3));
            $danger = (int) ($exp['danger'] ?? 1);

            foreach ($state->characters as $i => $c) {
                if (($c['name'] ?? null) === $who) {
                    $state->characters[$i]['away_until'] = $state->day + $days;
                }
            }
            $state->flags['expedition_active'] = true;
            $state->flags['away_member'] = $who;
            $state->flags['away_days'] = $days;

            $tier = $this->expeditions->resolve($who, $days, $danger, $state, $rng);
            $state->scheduledEvents[] = [
                'key' => 'exp_return_' . $tier,
                'fire_on_day' => $state->day + $days,
            ];
        }

        // Record cooldown and consume a scheduled occurrence if present.
        $state->recentEvents[$event->key] = $state->day;
        $state->scheduledEvents = array_values(array_filter(
            $state->scheduledEvents,
            fn ($s) => ($s['key'] ?? null) !== $event->key,
        ));

        // Append this choice to the rolling log (capped at 30 entries).
        $entry = [
            'day'              => $state->day,
            'event_key'        => $event->key,
            'choice_index'     => $choiceIndex,
            'choice_label'     => $choice['label'] ?? '',
            'tags'             => $choice['tags'] ?? [],
            'reaction_summary' => $this->reactions->summary($reactions),
        ];
        $state->choiceLog = array_slice(
            array_merge($state->choiceLog, [$entry]),
            -30
        );

        // Persist run state, then flush profile-scoped state (cross-run memory
        // + earned research points) back onto the profile.
        $state->applyTo($run);
        $run->syncRng($rng);
        $run->current_event_key = null;
        $run->save();
        $this->profileSync->flush($run, $state);

        // Sync epithet to profile
        $epithet = $this->epithet->calculate($state);
        if ($epithet !== null && $run->profile) {
            $profileFlags = $run->profile->flags ?? [];
            $profileFlags['epithet'] = $epithet;
            $run->profile->flags = $profileFlags;
            $run->profile->save();
        }

        // A choice's effects may push the run into an ending (death or win).
        $ending = $this->endings->check($run);

        if ($ending !== null) {
            $run->scheduled_events = array_values(array_filter(
                $run->scheduled_events ?? [],
                fn ($s) => ($s['key'] ?? null) !== 'death_notice',
            ));
            $run->save();
        }

        return [
            'log'       => $outcome['log'] ?? '',
            'effects'   => $outcome['effects'] ?? [],
            'ending'    => $ending,
            'reactions' => $reactions,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visibleChoices(Event $event, RunState $state): array
    {
        $speaker = $this->resolveSpeaker($event, $state);
        $corrupted = $this->shouldCorruptHints($state, $speaker);
        $choiceCount = count($event->choices);

        $out = [];
        foreach ($event->choices as $index => $choice) {
            $available = $this->evaluator->evaluate($choice['requires'] ?? null, $state);
            $hint = $this->hints->hintFor($choice, $speaker);

            // Corrupt hint: swap it with a random other choice's hint
            if ($corrupted && $hint !== null && $choiceCount > 1) {
                $otherIndices = array_filter(array_keys($event->choices), fn ($i) => $i !== $index);
                if ($otherIndices !== []) {
                    $otherKey = array_values($otherIndices)[($state->day + $index) % count($otherIndices)];
                    $otherChoice = $event->choices[$otherKey];
                    $hint = $this->hints->hintFor($otherChoice, $speaker) ?? $hint;
                }
            }

            $out[] = [
                'index'        => $index,
                'label'        => $choice['label'] ?? '',
                'hint'         => $hint,
                'available'    => $available,
                'requires_item'=> $choice['requires_item'] ?? null,
            ];
        }
        return $out;
    }

    private function shouldCorruptHints(RunState $state, ?array $speaker): bool
    {
        $moraleLow = ($state->resources['morale'] ?? 100) < 25;
        $speakerStressed = $speaker !== null && ($speaker['stress'] ?? 0) > 80;
        if (! $moraleLow && ! $speakerStressed) {
            return false;
        }
        // Deterministic: use day + speaker name length to avoid rand()
        return (($state->day * 7 + strlen($speaker['name'] ?? '')) % 4) === 0;
    }

    /**
     * Weighted-random outcome branch, reweighted by the speaker's luck traits.
     * Single-branch choices resolve directly (no luck to apply).
     */
    private function pickOutcome(array $outcomes, ?array $speaker, \App\Game\SeededRng $rng): array
    {
        if ($outcomes === []) {
            return ['effects' => [], 'log' => ''];
        }
        if (count($outcomes) === 1) {
            return $outcomes[0];
        }

        $weights = $this->weigher->weights($outcomes, $speaker);
        // weightedPick keys by array index; weights is parallel to $outcomes.
        return $outcomes[$rng->weightedPick($weights)];
    }

    /**
     * The living character whose name matches the event's `speaker`, or null
     * (no speaker, or speaker dead/absent → neutral hints, no luck shift).
     *
     * @return array<string,mixed>|null
     */
    private function resolveSpeaker(Event $event, RunState $state): ?array
    {
        if (! $event->speaker) {
            return null;
        }
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $event->speaker && ($c['alive'] ?? true)) {
                return $c;
            }
        }
        return null;
    }
}
