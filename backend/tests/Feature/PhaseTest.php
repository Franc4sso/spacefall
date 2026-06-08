<?php

use App\Game\Engine\RunState;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('defaults a new run phase floor to isolation and exposes phase', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    expect($run->phase_floor)->toBe('isolation');

    $state = RunState::fromRun($run);
    expect($state->phaseFloor)->toBe('isolation');
    expect($state->phase)->toBe('isolation');
    expect($state->phaseIndex)->toBe(0);
});
