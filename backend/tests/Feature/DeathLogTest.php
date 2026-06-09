<?php

use App\Game\Engine\RunState;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('defaults death_log to empty and round-trips through RunState', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    expect($run->death_log)->toBe([]);

    $state = RunState::fromRun($run);
    expect($state->deathLog)->toBe([]);

    $state->deathLog[] = ['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck'];
    $state->applyTo($run);
    $run->save();

    expect($run->fresh()->death_log)->toHaveCount(1);
    expect($run->fresh()->death_log[0]['name'])->toBe('Cole');
});

it('records an event kill into the death log with context', function () {
    $applier = app(\App\Game\Engine\EffectApplier::class);
    $state = new RunState(
        day: 12,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0],
        ],
    );

    $applier->apply([['kill' => 'Anna']], $state, new \App\Game\SeededRng(1), ['event_key' => 'hull_breach', 'day' => 12]);

    expect($state->deathLog)->toHaveCount(1);
    expect($state->deathLog[0])->toMatchArray(['name' => 'Anna', 'day' => 12, 'cause' => 'event', 'context' => 'hull_breach']);
});

it('attributes a death to the event the player resolved', function () {
    $run = app(RunFactory::class)->create(7, ['welder']);
    $run->resources = ['oxygen' => 80, 'food' => 4, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['hunger'] = 80; }
    $run->characters = $chars;
    $run->current_event_key = 'food_sacrifice';
    $run->save();

    $engine = app(\App\Game\Engine\EventEngine::class);
    $engine->currentCard($run->fresh());
    $engine->resolveChoice($run->fresh(), 0); // choice 0 kills the hungriest

    $after = $run->fresh();
    expect($after->death_log)->not->toBeEmpty();
    expect($after->death_log[0]['context'])->toBe('food_sacrifice');
    expect($after->death_log[0]['cause'])->toBe('event');
});

it('records a starvation death with cause starvation', function () {
    $run = app(RunFactory::class)->create(3, ['welder']);
    $chars = $run->characters;
    $chars[0]['hunger'] = 99; // daily_rise pushes to >= starve_at (100)
    $run->characters = $chars;
    $run->day = 5;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    $after = $run->fresh();
    $starved = collect($after->death_log)->firstWhere('cause', 'starvation');
    expect($starved)->not->toBeNull();
    expect($starved['name'])->toBe($chars[0]['name']);
});

it('schedules a death_notice when a death occurs mid-run', function () {
    $run = app(RunFactory::class)->create(7, ['welder']);
    $run->resources = ['oxygen' => 80, 'food' => 4, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['hunger'] = 80; }
    $run->characters = $chars;
    $run->current_event_key = 'food_sacrifice';
    $run->save();

    $engine = app(\App\Game\Engine\EventEngine::class);
    $engine->currentCard($run->fresh());
    $engine->resolveChoice($run->fresh(), 0); // the kill choice

    $after = $run->fresh();
    if ($after->status === 'active') {
        $keys = collect($after->scheduled_events ?? [])->pluck('key');
        expect($keys)->toContain('death_notice');
    } else {
        $keys = collect($after->scheduled_events ?? [])->pluck('key');
        expect($keys)->not->toContain('death_notice');
    }
});
