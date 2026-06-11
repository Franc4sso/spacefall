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

it('RunState carries the run theme', function () {
    $run = App\Models\Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    $state = App\Game\Engine\RunState::fromRun($run->fresh());
    expect($state->theme)->toBe('island');
});

it('scopes event lookup by theme', function () {
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    expect(App\Models\Event::where('theme', 'island')->where('key', 'shared_key')->first()->title)
        ->toBe('I');
    expect(App\Models\Event::where('theme', 'space')->count())->toBe(1);
});

it('an island run never draws space events', function () {
    App\Models\Event::query()->delete();
    App\Models\Event::create([
        'key' => 'space_only', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'island_only', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    $run = App\Models\Run::create([
        'seed' => 7, 'rng_cursor' => 0, 'day' => 1, 'resources' => ['morale' => 50],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);

    $card = app(App\Game\Engine\EventEngine::class)->currentCard($run->fresh());
    expect($card['event']->key)->toBe('island_only');
});
