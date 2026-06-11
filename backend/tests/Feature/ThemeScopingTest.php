<?php

use App\Models\Run;

it('persists a theme on a run, defaulting to space', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [],
    ]);
    expect($run->fresh()->theme)->toBe('space');
});

it('accepts an explicit theme', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    expect($run->fresh()->theme)->toBe('island');
});
