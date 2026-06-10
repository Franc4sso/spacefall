<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function crewState(array $characters): RunState
{
    return new RunState(
        day: 10,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: $characters,
    );
}

function member(string $name, bool $alive): array
{
    return ['name' => $name, 'role' => 'x', 'traits' => [], 'alive' => $alive, 'stress' => 0, 'hunger' => 0, 'away_until' => 0];
}

it('counts living crew and compares', function () {
    $e = new ConditionEvaluator();
    $allDead = crewState([member('Anna', false), member('Bex', false), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '==', 'value' => 0]], $allDead))->toBeTrue();

    $oneAlive = crewState([member('Anna', true), member('Bex', false), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '==', 'value' => 0]], $oneAlive))->toBeFalse();
    expect($e->evaluate(['living_crew' => ['op' => '>=', 'value' => 1]], $oneAlive))->toBeTrue();

    $twoAlive = crewState([member('Anna', true), member('Bex', true), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '<', 'value' => 3]], $twoAlive))->toBeTrue();
});
