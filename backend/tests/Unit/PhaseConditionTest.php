<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function stateInPhase(string $phase, int $index): RunState
{
    return new RunState(
        day: 1,
        resources: ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90],
        phaseFloor: $phase,
        phase: $phase,
        phaseIndex: $index,
    );
}

it('matches the exact phase with {phase}', function () {
    $e = new ConditionEvaluator();
    expect($e->evaluate(['phase' => 'deterioration'], stateInPhase('deterioration', 1)))->toBeTrue();
    expect($e->evaluate(['phase' => 'deterioration'], stateInPhase('isolation', 0)))->toBeFalse();
});

it('matches a phase floor with {phase_index} numeric comparison', function () {
    $e = new ConditionEvaluator();
    $cond = ['phase_index' => ['op' => '>=', 'value' => 1]];
    expect($e->evaluate($cond, stateInPhase('deterioration', 1)))->toBeTrue();
    expect($e->evaluate($cond, stateInPhase('reckoning', 2)))->toBeTrue();
    expect($e->evaluate($cond, stateInPhase('isolation', 0)))->toBeFalse();
});
