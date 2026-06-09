<?php

use App\Game\Engine\RunState;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('persists items mutated on the state back to the run', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    $state = RunState::fromRun($run);
    $state->items[] = 'scanner';      // simulate a grant_item effect
    $state->applyTo($run);
    $run->save();

    expect($run->fresh()->items)->toContain('scanner');
});
