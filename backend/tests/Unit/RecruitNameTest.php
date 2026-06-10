<?php

use App\Game\Engine\EffectApplier;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function recruitState(): RunState
{
    return new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        flags: [],
        characters: [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0],
        ],
    );
}

it('assigns a real name to a recruited survivor (never "?")', function () {
    $state = recruitState();
    $rng = new SeededRng(1);
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, $rng);
    $recruited = end($state->characters);
    expect($recruited['name'] ?? '?')->not->toBe('?');
    expect($recruited['name'] ?? '')->not->toBe('');
});

it('gives two recruits distinct names', function () {
    $state = recruitState();
    $rng = new SeededRng(1);
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, $rng);
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, $rng);
    $names = array_column($state->characters, 'name');
    expect(count($names))->toBe(count(array_unique($names)));
});
