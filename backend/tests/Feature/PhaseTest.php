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

it('scales resource drain by the phase decay multiplier', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    // Force reckoning via the floor; calm resources so only the floor drives phase.
    $run->phase_floor = 'reckoning';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->day = 1;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    // oxygen daily drain = 3; reckoning multiplier = 1.2 => 3 * 1.2 = 3.6 -> round 4.
    // Day-1 systems are at start (no below-threshold penalty yet), so the only oxygen
    // change is the scaled daily drain. 100 - 4 = 96.
    expect($run->fresh()->resources['oxygen'])->toBe(96);
});

it('leaves isolation decay identical to the base daily drain', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->phase_floor = 'isolation';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->day = 1;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    // isolation multiplier = 1.0 => oxygen drain stays 3 => 100 - 3 = 97.
    expect($run->fresh()->resources['oxygen'])->toBe(97);
});
