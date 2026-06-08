<?php

namespace App\Game\Engine;

use App\Models\Run;
use App\Game\Engine\PhaseResolver;

/**
 * A plain, mutable snapshot of everything the engine reads and writes for one
 * run. Decouples the pure engine services (Evaluator / Applier / Selector)
 * from Eloquent so they can be unit-tested against hand-built states and
 * fuzzed cheaply.
 *
 * Fields that belong to later phases (characters, items, relationships,
 * systems) exist here now as empty defaults so Conditions referencing them are
 * *total* (they evaluate to a defined result, never crash) before that content
 * is built. They get populated in Phases 3–5.
 */
final class RunState
{
    /**
     * @param  array<string,int>     $resources         code => value
     * @param  array<string,mixed>   $flags             run-scoped flags
     * @param  array<string,int>     $recentEvents      event_key => last_seen_day
     * @param  list<array{key:string,fire_on_day:int}> $scheduledEvents
     * @param  array<string,mixed>   $profileFlags      profile-scoped flags (Phase 7)
     * @param  list<array<string,mixed>> $characters     (Phase 3)
     * @param  list<string>          $items             item keys (Phase 4)
     * @param  array<string,array<string,mixed>> $systems  system => fields (Phase 5)
     * @param  list<array{a:string,b:string,value:int}> $relationships (Phase 3)
     * @param  list<array<string,mixed>> $choiceLog      ordered log of resolved choices (capped at 30)
     */
    public function __construct(
        public int $day,
        public array $resources,
        public array $flags = [],
        public array $recentEvents = [],
        public array $scheduledEvents = [],
        public array $profileFlags = [],
        public array $characters = [],
        public array $items = [],
        public array $systems = [],
        public array $relationships = [],
        public array $choiceLog = [],
        public string $phaseFloor = 'isolation',
        public string $phase = 'isolation',
        public int $phaseIndex = 0,
    ) {
    }

    public static function fromRun(Run $run): self
    {
        // Profile-scoped flags are loaded from the linked profile so a
        // condition with scope:profile sees what earlier runs left behind
        // (cross-run memory). They are flushed back by ProfileSync after a
        // choice resolves.
        $profileFlags = $run->profile?->flags ?? [];

        $resolver = new PhaseResolver();
        $floor = $run->phase_floor ?? 'isolation';
        $phase = $resolver->resolve($run->day, $run->resources ?? [], $floor);

        return new self(
            day: $run->day,
            resources: $run->resources ?? [],
            flags: $run->flags ?? [],
            recentEvents: $run->recent_events ?? [],
            scheduledEvents: $run->scheduled_events ?? [],
            profileFlags: $profileFlags,
            characters: $run->characters ?? [],
            relationships: $run->relationships ?? [],
            items: $run->items ?? [],
            systems: $run->systems ?? [],
            choiceLog: $run->choice_log ?? [],
            phaseFloor: $floor,
            phase: $phase,
            phaseIndex: $resolver->indexOf($phase),
        );
    }

    /**
     * Write engine-owned fields back onto the model (caller saves).
     */
    public function applyTo(Run $run): void
    {
        $run->day = $this->day;
        $run->resources = $this->resources;
        $run->flags = $this->flags;
        $run->recent_events = $this->recentEvents;
        $run->scheduled_events = $this->scheduledEvents;
        $run->characters = $this->characters;
        $run->relationships = $this->relationships;
        $run->systems = $this->systems;
        $run->choice_log = $this->choiceLog;
        $run->phase_floor = $this->phaseFloor;
    }
}
