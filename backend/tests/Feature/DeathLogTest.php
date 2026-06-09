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
