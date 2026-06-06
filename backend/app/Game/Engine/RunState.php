<?php

namespace App\Game\Engine;

use App\Models\Run;

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
    ) {
    }

    public static function fromRun(Run $run): self
    {
        return new self(
            day: $run->day,
            resources: $run->resources ?? [],
            flags: $run->flags ?? [],
            recentEvents: $run->recent_events ?? [],
            scheduledEvents: $run->scheduled_events ?? [],
            characters: $run->characters ?? [],
            relationships: $run->relationships ?? [],
            items: $run->items ?? [],
            systems: $run->systems ?? [],
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
    }
}
