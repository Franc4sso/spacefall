<?php

use App\Game\ThemeConfig;

it('resolves a key from the requested theme', function () {
    $tc = new ThemeConfig();
    expect($tc->for('space')->get('items_pick'))->toBe(config('themes.space.items_pick'));
});

it('returns the default when a key is missing', function () {
    expect((new ThemeConfig())->for('space')->get('does.not.exist', 'fallback'))
        ->toBe('fallback');
});

it('rejects an unknown theme', function () {
    (new ThemeConfig())->for('atlantis');
})->throws(InvalidArgumentException::class);

it('isolates state between for() calls', function () {
    $tc = new ThemeConfig();
    $space = $tc->for('space');
    expect($space->get('items_pick'))->not->toBeNull();
});

it('EffectApplier clamps using the resource max of the state theme', function () {
    $applier = app(App\Game\Engine\EffectApplier::class);
    $state = new App\Game\Engine\RunState(
        day: 1, resources: ['morale' => 90], theme: 'space',
    );
    $rng = new App\Game\SeededRng(1);
    $applier->apply([['resource' => 'morale', 'delta' => 1000]], $state, $rng);
    $max = config('themes.space.resources.morale.max');
    expect($state->resources['morale'])->toBe($max);
});

it('EffectApplier clamps to the island theme max, not space (cross-theme routing)', function () {
    // Make the two themes' morale max differ WITHOUT touching balance: stub
    // config only. A +1000 morale on an island-themed state must clamp to the
    // ISLAND max (50), proving the applier read the state's theme, not space.
    config(['themes.island.resources.morale.max' => 50]);
    config(['themes.space.resources.morale.max' => 100]);

    $applier = app(App\Game\Engine\EffectApplier::class);
    $rng = new App\Game\SeededRng(1);

    $island = new App\Game\Engine\RunState(day: 1, resources: ['morale' => 10], theme: 'island');
    $applier->apply([['resource' => 'morale', 'delta' => 1000]], $island, $rng);
    expect($island->resources['morale'])->toBe(50);

    $space = new App\Game\Engine\RunState(day: 1, resources: ['morale' => 10], theme: 'space');
    $applier->apply([['resource' => 'morale', 'delta' => 1000]], $space, $rng);
    expect($space->resources['morale'])->toBe(100);
});
