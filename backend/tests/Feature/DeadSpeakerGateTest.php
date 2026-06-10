<?php

use App\Game\Engine\RunState;
use App\Game\Engine\Selector;
use App\Game\SeededRng;
use App\Models\Event;

it('excludes an event whose named speaker is dead, keeps speaker-null events', function () {
    $selector = app(Selector::class);

    $annaEvent = new Event(['key' => 't_anna', 'title' => 'x', 'body' => 'x', 'speaker' => 'Anna', 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $narratorEvent = new Event(['key' => 't_narr', 'title' => 'x', 'body' => 'x', 'speaker' => null, 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $pool = collect([$annaEvent, $narratorEvent]);

    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: [['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => false, 'stress' => 0, 'hunger' => 0, 'away_until' => 0]],
    );

    $seenAnna = false; $seenNarr = false;
    for ($i = 0; $i < 20; $i++) {
        $picked = $selector->select($pool, $state, new SeededRng($i));
        if ($picked->key === 't_anna') $seenAnna = true;
        if ($picked->key === 't_narr') $seenNarr = true;
    }
    expect($seenAnna)->toBeFalse('a dead speaker event must not be selected');
    expect($seenNarr)->toBeTrue('the narrator event should still be selectable');
});

it('keeps an event whose named speaker is alive', function () {
    $selector = app(Selector::class);
    $annaEvent = new Event(['key' => 't_anna', 'title' => 'x', 'body' => 'x', 'speaker' => 'Anna', 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $pool = collect([$annaEvent]);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: [['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0]],
    );
    expect($selector->select($pool, $state, new SeededRng(1))->key)->toBe('t_anna');
});
