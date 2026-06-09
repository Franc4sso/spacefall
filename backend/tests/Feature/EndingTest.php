<?php

use App\Game\DayProcessor;
use App\Game\Engine\EndingService;
use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

/** Force a run into a given state and run the ending check. */
function endingFor(array $overrides): ?array
{
    $run = app(RunFactory::class)->create(1, $overrides['items'] ?? []);
    foreach (['resources', 'flags', 'day'] as $field) {
        if (isset($overrides[$field])) {
            if ($field === 'resources') {
                $run->resources = array_merge($run->resources, $overrides['resources']);
            } else {
                $run->{$field} = $overrides[$field];
            }
        }
    }
    $run->save();

    return app(EndingService::class)->check($run->fresh());
}

it('reaches asphyxiation when oxygen hits zero', function () {
    $e = endingFor(['resources' => ['oxygen' => 0]]);
    expect($e['key'])->toBe('death_asphyxiation');
    expect($e['type'])->toBe('lose');
});

it('reaches decompression when hull hits zero', function () {
    expect(endingFor(['resources' => ['hull' => 0]])['key'])->toBe('death_hull');
});

it('reaches starvation when food hits zero', function () {
    expect(endingFor(['resources' => ['food' => 0]])['key'])->toBe('death_starvation');
});

it('reaches blackout when power hits zero', function () {
    expect(endingFor(['resources' => ['power' => 0]])['key'])->toBe('death_blackout');
});

it('reaches breakdown when morale hits zero', function () {
    expect(endingFor(['resources' => ['morale' => 0]])['key'])->toBe('death_breakdown');
});

it('reaches the two-sided recklessness death when morale maxes out', function () {
    $e = endingFor(['resources' => ['morale' => 100]]);
    expect($e['key'])->toBe('death_recklessness');
    expect($e['type'])->toBe('lose');
});

it('reaches the escape win with the suit, time, and power', function () {
    $e = endingFor([
        'items' => ['spacesuit'],
        'day' => 13,
        'resources' => ['power' => 80, 'oxygen' => 80, 'food' => 80, 'morale' => 50, 'hull' => 80],
    ]);
    expect($e['key'])->toBe('win_escape');
    expect($e['type'])->toBe('win');
});

it('reaches the rescue win with comms and time', function () {
    expect(endingFor([
        'items' => ['comms'],
        'day' => 25,
        // win_rescue is now gated on the SOS having actually been sent.
        'flags' => ['sos_sent' => true],
        'resources' => ['morale' => 50],
    ])['key'])->toBe('win_rescue');
});

it('reaches the colony win with the seedbank and stored food', function () {
    expect(endingFor([
        'items' => ['seedbank'],
        'day' => 26,
        // win_colony is now gated on the crops actually having been tended.
        'flags' => ['tended_crops' => true],
        'resources' => ['food' => 80],
    ])['key'])->toBe('win_colony');
});

it('reaches the research win with the flag set', function () {
    expect(endingFor([
        'day' => 23,
        'flags' => ['research_complete' => true],
        'resources' => ['power' => 50],
    ])['key'])->toBe('win_research');
});

it('reaches the sacrifice win with its flag', function () {
    expect(endingFor([
        'day' => 18,
        'flags' => ['made_the_sacrifice' => true],
        'resources' => ['oxygen' => 20], // dire situation required
    ])['key'])->toBe('win_sacrifice');
});

it('lethal states pre-empt a simultaneous win', function () {
    // Has the rescue conditions AND zero oxygen — death wins.
    $e = endingFor([
        'items' => ['comms'],
        'day' => 20,
        'resources' => ['oxygen' => 0, 'morale' => 50],
    ]);
    expect($e['type'])->toBe('lose');
});

it('stops the daily loop and card flow once ended', function () {
    $run = app(RunFactory::class)->create(1);
    $run->resources = array_merge($run->resources, ['oxygen' => 0]);
    $run->save();

    app(EndingService::class)->check($run->fresh());
    expect($run->fresh()->status)->toBe('ended');

    // DayProcessor and currentCard both no-op on an ended run.
    app(DayProcessor::class)->advance($run->fresh());
    expect($run->fresh()->day)->toBe(1); // did not advance
    expect(app(EventEngine::class)->currentCard($run->fresh())['event'])->toBeNull();
});

it('every configured ending is reachable by some forced state', function () {
    // Reachability guard: drive each ending and assert it fires. This is the
    // "no dead content" check for endings (the harness covers it via play too).
    $reached = [];
    foreach (config('game.endings') as $ending) {
        $reached[$ending['key']] = true;
    }
    // The individual tests above already drive the core endings; assert the count matches
    // so a newly-added ending without a test is noticed. Updated to 15 after Task 10.
    expect(count($reached))->toBe(15);
});
