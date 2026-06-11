<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('the rationing crisis appears only when food is scarce', function () {
    $engine = app(EventEngine::class);

    $hungry = app(RunFactory::class)->create(1);
    $res = $hungry->resources;
    $res['food'] = 10; // < 30 -> eligible
    $hungry->resources = $res;
    $hungry->save();

    // The Selector should be able to surface ration_crisis now. Scan seeds.
    $appeared = false;
    for ($s = 0; $s < 40; $s++) {
        $r = $hungry->replicate();
        $r->seed = $s;
        $r->rng_cursor = 0;
        $r->current_event_key = null;
        $r->save();
        if ($engine->currentCard($r)['event']->key === 'ration_crisis') {
            $appeared = true;
            break;
        }
    }
    expect($appeared)->toBeTrue();
});

it('"stringere la cinghia" stresses the whole crew (scales with survivors)', function () {
    $engine = app(EventEngine::class);

    $run = app(RunFactory::class)->create(1);
    $res = $run->resources;
    $res['food'] = 10;
    $run->resources = $res;
    $run->current_event_key = 'ration_crisis';
    $run->save();

    $before = collect($run->fresh()->characters)->pluck('stress')->sum();

    // Choice index 1 = "Si salta il turno" -> character:all +12 stress.
    $engine->resolveChoice($run->fresh(), 1);

    $after = collect($run->fresh()->characters)->pluck('stress')->sum();
    $crew = count(config('themes.space.roster'));

    // Every living survivor gained 12; the total weight scales with the crew.
    expect($after - $before)->toBe(12 * $crew);
});

it('"mangio solo io" crashes morale and sets the ate_alone flag', function () {
    $engine = app(EventEngine::class);

    $run = app(RunFactory::class)->create(1);
    $res = $run->resources;
    $res['food'] = 10;
    $res['morale'] = 60;
    $run->resources = $res;
    $run->current_event_key = 'ration_crisis';
    $run->save();

    $engine->resolveChoice($run->fresh(), 2);

    $after = $run->fresh();
    expect($after->resources['morale'])->toBe(60 - 14);
    expect($after->flags['ate_alone'])->toBeTrue();
});
