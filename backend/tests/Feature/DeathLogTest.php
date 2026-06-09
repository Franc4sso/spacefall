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
