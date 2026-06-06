<?php

use App\Game\DayProcessor;
use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('initialises station systems on a new run', function () {
    $run = app(RunFactory::class)->create(1);

    foreach (config('game.systems') as $key => $def) {
        expect($run->systems[$key]['efficiency'])->toBe($def['start']);
    }
});

it('degrades systems each day and bleeds a resource once below threshold', function () {
    $run = app(RunFactory::class)->create(1);

    // Force life_support just above its penalty threshold, oxygen full.
    $systems = $run->systems;
    $systems['life_support']['efficiency'] = 51; // penalty_below = 50, decay = 3
    $run->systems = $systems;
    $res = $run->resources;
    $res['oxygen'] = 100;
    $run->resources = $res;
    $run->save();

    app(DayProcessor::class)->advance($run->fresh());

    $after = $run->fresh();
    // 51 - 3 = 48 < 50 -> penalty applies this day.
    expect($after->systems['life_support']['efficiency'])->toBe(48);
    // oxygen lost the daily drain (8) + the life_support penalty (5).
    expect($after->resources['oxygen'])->toBe(100 - 8 - 5);
});

it('adds hardship stress to every survivor when a resource is critically low', function () {
    $run = app(RunFactory::class)->create(1);

    $res = $run->resources;
    $res['food'] = 5; // <= 20 -> +8 stress to all
    $run->resources = $res;
    $run->save();

    app(DayProcessor::class)->advance($run->fresh());

    foreach ($run->fresh()->characters as $c) {
        expect($c['stress'])->toBeGreaterThanOrEqual(8);
    }
});

it('keeps state internally consistent across a 15-day playthrough', function () {
    $run = app(RunFactory::class)->create(4242, ['welder', 'scanner', 'medkit']);
    $engine = app(EventEngine::class);
    $day = app(DayProcessor::class);

    $endedEarly = false;
    for ($i = 0; $i < 15; $i++) {
        $run = $run->fresh();
        if ($run->status !== 'active') {
            $endedEarly = true;
            break; // a reached ending is a valid stop
        }

        $card = $engine->currentCard($run);
        expect($card['event'])->not->toBeNull(); // never stalls while active

        $choice = collect($card['choices'])->firstWhere('available', true)['index'] ?? 0;
        $engine->resolveChoice($run->fresh(), $choice);

        $day->advance($run->fresh());

        $state = $run->fresh();

        // Every resource within [0, max].
        foreach (config('game.resources') as $code => $def) {
            expect($state->resources[$code])->toBeGreaterThanOrEqual(0);
            expect($state->resources[$code])->toBeLessThanOrEqual($def['max']);
        }
        // Every system efficiency within [0, 100].
        foreach ($state->systems as $sys) {
            expect($sys['efficiency'])->toBeGreaterThanOrEqual(0);
            expect($sys['efficiency'])->toBeLessThanOrEqual(100);
        }
        // Every survivor's stress within [0, 100].
        foreach ($state->characters as $c) {
            expect($c['stress'])->toBeGreaterThanOrEqual(0);
            expect($c['stress'])->toBeLessThanOrEqual(100);
        }
    }

    // If it survived all 15 days it's on day 16; if it died, state was still
    // consistent every step and the run is properly marked ended.
    if ($endedEarly) {
        expect($run->fresh()->status)->toBe('ended');
    } else {
        expect($run->fresh()->day)->toBe(16);
    }
});

it('is fully reproducible: same seed + same choices => identical 15-day end state', function () {
    $play = function () {
        $run = app(RunFactory::class)->create(7777, ['welder']);
        $engine = app(EventEngine::class);
        $day = app(DayProcessor::class);
        for ($i = 0; $i < 15; $i++) {
            $run = $run->fresh();
            if ($run->status !== 'active') {
                break;
            }
            $card = $engine->currentCard($run);
            $choice = collect($card['choices'])->firstWhere('available', true)['index'] ?? 0;
            $engine->resolveChoice($run->fresh(), $choice);
            $day->advance($run->fresh());
        }
        $s = $run->fresh();
        return [$s->status, $s->ending_key, $s->resources, $s->systems, $s->characters, $s->flags];
    };

    expect($play())->toEqual($play());
});
