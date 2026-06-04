<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use App\Models\Event;
use App\Models\Run;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('seeds the starter events and they all validate', function () {
    // The seeder itself runs the schema validator; reaching here means it passed.
    expect(Event::count())->toBeGreaterThanOrEqual(5);
    expect(Event::where('is_filler', true)->count())->toBeGreaterThanOrEqual(1);
});

it('always presents a card on a fresh run (flow guarantee)', function () {
    $run = app(RunFactory::class)->create(123);
    $card = app(EventEngine::class)->currentCard($run);

    expect($card['event'])->toBeInstanceOf(Event::class);
    expect($card['choices'])->not->toBeEmpty();
});

it('pins the current card so a reload returns the same one', function () {
    $run = app(RunFactory::class)->create(123);
    $engine = app(EventEngine::class);

    $first = $engine->currentCard($run->fresh());
    $second = $engine->currentCard($run->fresh());

    expect($first['event']->key)->toBe($second['event']->key);
});

it('resolves a choice, applies effects, and unpins the card', function () {
    $run = app(RunFactory::class)->create(123);
    $engine = app(EventEngine::class);

    $card = $engine->currentCard($run);

    // First available choice — some cards gate choice 0 on an item.
    $choice = collect($card['choices'])->firstWhere('available', true)['index'];
    $result = $engine->resolveChoice($run->fresh(), $choice);

    expect($result)->toHaveKeys(['log', 'effects']);
    expect($run->fresh()->current_event_key)->toBeNull();
});

it('plays several days through real events without ever stalling', function () {
    $run = app(RunFactory::class)->create(999);
    $engine = app(EventEngine::class);

    for ($i = 0; $i < 12; $i++) {
        $run = $run->fresh();
        $card = $engine->currentCard($run);
        expect($card['event'])->not->toBeNull();

        // Pick the first available choice.
        $choiceIndex = collect($card['choices'])->firstWhere('available', true)['index'] ?? 0;
        $engine->resolveChoice($run->fresh(), $choiceIndex);

        // Advance a day so day-gated events and schedules can fire.
        app(\App\Game\DayProcessor::class)->advance($run->fresh());
    }

    expect(true)->toBeTrue(); // reaching here = never stalled / threw
});

it('honours a flag callback: venting the technician unlocks the ghost event', function () {
    $engine = app(EventEngine::class);

    // Build a run pinned to the technician event, then choose to vent.
    $run = app(RunFactory::class)->create(1);
    $run->day = 3;
    $run->current_event_key = 'technician_panic';
    $run->save();

    $engine->resolveChoice($run->fresh(), 0); // choice 0 = vent (sets flag)
    expect($run->fresh()->flags['vented_the_technician'])->toBeTrue();

    // technician_ghost requires that flag; force-pin nothing, just check eligibility
    // by confirming the Selector can surface it now that the flag is set.
    $run = $run->fresh();
    $found = false;
    for ($i = 0; $i < 30; $i++) {
        $r = $run->replicate();
        $r->seed = $i;
        $r->rng_cursor = 0;
        $r->current_event_key = null;
        $r->save();
        if ($engine->currentCard($r)['event']->key === 'technician_ghost') {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
});

it('creates a delayed event chain: ignoring the fault schedules a cascade', function () {
    $engine = app(EventEngine::class);

    $run = app(RunFactory::class)->create(1);
    $run->current_event_key = 'power_flicker';
    $run->save();

    // Choice 1 = ignore; one of its branches schedules power_cascade. Find the
    // seed whose branch schedules it (deterministic per seed).
    $scheduled = null;
    for ($seed = 0; $seed < 50; $seed++) {
        $r = app(RunFactory::class)->create($seed);
        $r->current_event_key = 'power_flicker';
        $r->save();
        $engine->resolveChoice($r->fresh(), 1);
        $sched = $r->fresh()->scheduled_events;
        if (collect($sched)->contains(fn ($s) => $s['key'] === 'power_cascade')) {
            $scheduled = $r->fresh();
            break;
        }
    }

    expect($scheduled)->not->toBeNull('expected at least one seed to roll the cascade branch');

    // Advance to the fire day; the cascade must be the next forced card.
    $fireDay = collect($scheduled->scheduled_events)->firstWhere('key', 'power_cascade')['fire_on_day'];
    $scheduled->day = $fireDay;
    $scheduled->current_event_key = null;
    $scheduled->save();

    expect($engine->currentCard($scheduled->fresh())['event']->key)->toBe('power_cascade');
});
