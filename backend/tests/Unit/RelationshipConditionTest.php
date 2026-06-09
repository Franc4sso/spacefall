<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function stateWithRel(array $relationships): RunState
{
    return new RunState(
        day: 1,
        resources: ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90],
        relationships: $relationships,
    );
}

it('matches a named pair in a band', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]]); // hatred
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeTrue();
});

it('matches a named pair regardless of stored order (symmetric)', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Cole', 'b' => 'Anna', 'value' => -60]]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeTrue();
});

it('does not match a different pair or a different band', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'state' => 'hatred']], $state))->toBeFalse();
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'bond']], $state))->toBeFalse();
});

it('treats a nonexistent named pair as neutral', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'neutral']], $state))->toBeTrue();
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeFalse();
});

it('keeps any-pair behaviour when a/b are omitted (backward compatible)', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Bex', 'b' => 'Cole', 'value' => 50]]); // devotion
    expect($e->evaluate(['relationship' => ['state' => 'devotion']], $state))->toBeTrue();
    expect($e->evaluate(['relationship' => ['state' => 'hatred']], $state))->toBeFalse();
});
