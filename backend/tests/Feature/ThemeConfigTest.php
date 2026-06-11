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
