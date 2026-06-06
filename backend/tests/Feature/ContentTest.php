<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\EventSchema;
use App\Game\Engine\RunState;
use App\Game\Engine\Selector;
use App\Game\SeededRng;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('seeds at least 50 events', function () {
    expect(Event::count())->toBeGreaterThanOrEqual(50);
});

it('keeps a guaranteed filler pool', function () {
    expect(Event::where('is_filler', true)->count())->toBeGreaterThanOrEqual(2);
});

it('every event validates against the DSL schema', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));

    Event::all()->each(function (Event $event) use ($schema) {
        $schema->validate([
            'key' => $event->key,
            'title' => $event->title,
            'body' => $event->body,
            'requires' => $event->requires,
            'choices' => $event->choices,
        ]);
    })->isNotEmpty();

    expect(true)->toBeTrue(); // reaching here = nothing threw
});

it('every event has non-empty Italian text and well-formed choices', function () {
    Event::all()->each(function (Event $event) {
        expect(trim($event->title))->not->toBe('');
        expect(trim($event->body))->not->toBe('');
        expect($event->choices)->not->toBeEmpty();

        foreach ($event->choices as $choice) {
            expect(trim($choice['label'] ?? ''))->not->toBe('');
            expect($choice['outcomes'] ?? [])->not->toBeEmpty();
            foreach ($choice['outcomes'] as $outcome) {
                // Every outcome shows the player a line (log), even if terse.
                expect(array_key_exists('log', $outcome))->toBeTrue();
            }
        }
    });
});

it('event keys are unique', function () {
    $keys = Event::pluck('key');
    expect($keys->count())->toBe($keys->unique()->count());
});

it('the Selector never stalls against the full content pool', function () {
    $selector = new Selector(new ConditionEvaluator());
    $pool = Event::all();

    for ($i = 0; $i < 2000; $i++) {
        $rng = new SeededRng($i);
        $state = new RunState(
            day: $rng->nextInt(1, 40),
            resources: [
                'oxygen' => $rng->nextInt(0, 100),
                'food' => $rng->nextInt(0, 100),
                'power' => $rng->nextInt(0, 100),
                'morale' => $rng->nextInt(0, 100),
                'hull' => $rng->nextInt(0, 100),
            ],
            flags: $rng->nextFloat() > 0.5 ? ['made_promise' => true, 'left_someone' => true] : [],
            profileFlags: $rng->nextFloat() > 0.5 ? ['blew_a_reactor' => true] : [],
            characters: [
                ['name' => 'Anna', 'role' => 'engineer', 'alive' => true, 'stress' => $rng->nextInt(0, 100), 'traits' => ['genius']],
                ['name' => 'Cole', 'role' => 'pilot', 'alive' => true, 'stress' => $rng->nextInt(0, 100), 'traits' => ['coward']],
            ],
            items: ['drone', 'comms', 'medkit'],
            systems: ['life_support' => ['efficiency' => $rng->nextInt(0, 100)], 'power_grid' => ['efficiency' => $rng->nextInt(0, 100)]],
            relationships: [['a' => 'Anna', 'b' => 'Cole', 'value' => $rng->nextInt(-100, 100)]],
        );

        expect($selector->select($pool, $state, $rng))->toBeInstanceOf(Event::class);
    }
});

it('spans every trigger family across the content', function () {
    // A rough variety guard: at least one event keyed on each surface.
    $all = Event::all();

    $hasResource = $all->contains(fn ($e) => str_contains(json_encode($e->requires), '"resource"'));
    $hasSystem = $all->contains(fn ($e) => str_contains(json_encode($e->requires), '"system"'));
    $hasItem = $all->contains(fn ($e) => str_contains(json_encode($e->requires), '"has_item"'));
    $hasRelationship = $all->contains(fn ($e) => str_contains(json_encode($e->requires), '"relationship"'));
    $hasProfileFlag = $all->contains(fn ($e) => str_contains(json_encode($e->requires), '"profile"'));
    $hasSpawn = $all->contains(fn ($e) => str_contains(json_encode($e->choices), '"spawn_event"'));

    expect($hasResource)->toBeTrue();
    expect($hasSystem)->toBeTrue();
    expect($hasItem)->toBeTrue();
    expect($hasRelationship)->toBeTrue();
    expect($hasProfileFlag)->toBeTrue();
    expect($hasSpawn)->toBeTrue();
});
