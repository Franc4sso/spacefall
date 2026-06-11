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
