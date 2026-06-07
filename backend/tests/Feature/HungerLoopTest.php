<?php

use App\Game\DayProcessor;
use App\Models\Run;

beforeEach(function () {
    // Activate hunger for these tests (the shipped default is dormant; Task 9
    // sets the real tuned value).
    config([
        'game.hunger.daily_rise' => 8,
        'game.hunger.starve_at' => 100,
        'game.hunger.stress_bands' => [
            ['at_or_above' => 70, 'stress' => 8],
            ['at_or_above' => 40, 'stress' => 4],
        ],
        'game.hunger.spawn_bands' => [
            ['at_or_above' => 30, 'spawn' => 'food_ration'],
        ],
    ]);
});

it('raises hunger each day and inflicts stress above the threshold', function () {
    $run = Run::factory()->create([
        'day' => 1,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 65, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run);

    $anna = $run->fresh()->characters[0];
    // daily_rise 8 -> hunger 73 (>=70 band) -> +8 stress
    expect($anna['hunger'])->toBe(73);
    expect($anna['stress'])->toBe(8);
});

it('kills a survivor who reaches the starvation threshold', function () {
    $run = Run::factory()->create([
        'day' => 1,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 95, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'hunger' => 10, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run);

    $fresh = $run->fresh();
    expect($fresh->characters[0]['alive'])->toBeFalse(); // 95 + 8 >= 100 -> dies
    expect($fresh->characters[1]['alive'])->toBeTrue();
    expect($fresh->flags['died_of_hunger'] ?? false)->toBeTrue();
});

it('schedules the meal decision when the crew crosses into the hunger band', function () {
    $run = Run::factory()->create([
        'day' => 1,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 25, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run); // 25 + 8 = 33 >= 30 -> crosses into the band

    $fresh = $run->fresh();
    expect($fresh->characters[0]['hunger_band'])->toBe(1);
    expect(collect($fresh->scheduled_events)->pluck('key'))->toContain('food_ration');
});

it('does not re-schedule the meal while staying within the same band', function () {
    $run = Run::factory()->create([
        'day' => 1,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 35, 'hunger_band' => 1, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run); // 35 + 8 = 43, still band 1 -> no new schedule

    $fresh = $run->fresh();
    expect(collect($fresh->scheduled_events)->pluck('key'))->not->toContain('food_ration');
});

it('does not make away crew hungrier', function () {
    $run = Run::factory()->create([
        'day' => 3,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 50, 'away_until' => 7, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run);

    expect($run->fresh()->characters[0]['hunger'])->toBe(50); // away -> unchanged
});
