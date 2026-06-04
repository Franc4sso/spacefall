<?php

use App\Game\DayProcessor;
use App\Game\RunFactory;
use App\Models\Run;

it('seeds the configured roster onto a new run', function () {
    $run = app(RunFactory::class)->create(1);

    expect($run->characters)->toHaveCount(count(config('game.roster')));
    expect($run->characters[0]['stress'])->toBe(0);
    expect($run->characters[0]['alive'])->toBeTrue();
    expect($run->characters[0])->toHaveKey('traits');
});

it('schedules a self-initiated event when a survivor crosses into a stress band', function () {
    $run = app(RunFactory::class)->create(1);

    // Push the first survivor into the high-stress band.
    $chars = $run->characters;
    $chars[0]['stress'] = 90; // >= 85 band -> 'survivor_breaks'
    $run->characters = $chars;
    $run->save();

    app(DayProcessor::class)->advance($run->fresh());

    $sched = collect($run->fresh()->scheduled_events);
    expect($sched->pluck('key'))->toContain('survivor_breaks');
});

it('does not re-schedule the same band on subsequent days', function () {
    $run = app(RunFactory::class)->create(1);
    $chars = $run->characters;
    $chars[0]['stress'] = 70; // 'survivor_strained' band
    $run->characters = $chars;
    $run->save();

    app(DayProcessor::class)->advance($run->fresh());
    $afterFirst = count($run->fresh()->scheduled_events);

    app(DayProcessor::class)->advance($run->fresh());
    $afterSecond = count($run->fresh()->scheduled_events);

    // Stress didn't rise into a new band, so no second schedule was added.
    expect($afterSecond)->toBe($afterFirst);
});

it('the API surfaces the living roster', function () {
    $run = $this->postJson('/api/runs', ['seed' => 1])->assertCreated();

    expect($run->json('characters'))->toHaveCount(count(config('game.roster')));
    expect($run->json('characters.0'))->toHaveKeys(['name', 'role', 'traits', 'stress', 'alive']);
});
