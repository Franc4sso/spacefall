<?php

use App\Game\Engine\EffectApplier;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function rosterOf(array $names): array
{
    return array_map(fn ($n) => ['name' => $n, 'role' => 'x', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0], $names);
}

it('warm survivor pairs grow closer when a third dies; cold pairs grow colder', function () {
    $applier = app(EffectApplier::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: rosterOf(['Anna', 'Bex', 'Cole']),
        relationships: [
            ['a' => 'Anna', 'b' => 'Bex', 'value' => 20],   // bond -> +3 -> 23
            ['a' => 'Anna', 'b' => 'Cole', 'value' => -20],  // involves dead Cole -> untouched
        ],
    );

    $applier->apply([['kill' => 'Cole']], $state, new SeededRng(1));

    $byPair = [];
    foreach ($state->relationships as $r) {
        $byPair[$r['a'] . '-' . $r['b']] = $r['value'];
    }
    expect($byPair['Anna-Bex'])->toBe(23);
    expect($byPair['Anna-Cole'])->toBe(-20);
});

it('leaves neutral surviving pairs unchanged on a death', function () {
    $applier = app(EffectApplier::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: rosterOf(['Anna', 'Bex', 'Cole']),
        relationships: [['a' => 'Anna', 'b' => 'Bex', 'value' => 0]],
    );

    $applier->apply([['kill' => 'Cole']], $state, new SeededRng(1));

    $val = collect($state->relationships)->firstWhere(fn ($r) => $r['a'] === 'Anna' && $r['b'] === 'Bex')['value'];
    expect($val)->toBe(0);
});
