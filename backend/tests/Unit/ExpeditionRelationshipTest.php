<?php

use App\Game\Engine\ExpeditionResolver;
use App\Game\Engine\RunState;

function expRelState(array $relationships): RunState
{
    return new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: [
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0],
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0],
        ],
        items: [],
        relationships: $relationships,
    );
}

it('raises expedition risk when the expeditioner is in hatred with a staying member', function () {
    $r = new ExpeditionResolver();
    $neutral = $r->score('Cole', 3, 2, expRelState([]));
    $hateful = $r->score('Cole', 3, 2, expRelState([['a' => 'Cole', 'b' => 'Anna', 'value' => -60]]));
    expect($hateful)->toBeGreaterThan($neutral);
});

it('lowers expedition risk when the expeditioner is in bond with a staying member', function () {
    $r = new ExpeditionResolver();
    $neutral = $r->score('Cole', 3, 2, expRelState([]));
    $bonded = $r->score('Cole', 3, 2, expRelState([['a' => 'Cole', 'b' => 'Anna', 'value' => 30]]));
    expect($bonded)->toBeLessThan($neutral);
});

it('does not change risk when all relationships are neutral (zero regression)', function () {
    $r = new ExpeditionResolver();
    $a = $r->score('Cole', 3, 2, expRelState([]));
    $b = $r->score('Cole', 3, 2, expRelState([['a' => 'Cole', 'b' => 'Anna', 'value' => 0]]));
    expect($a)->toBe($b);
});
