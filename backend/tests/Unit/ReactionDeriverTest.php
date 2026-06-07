<?php

use App\Game\Engine\ReactionDeriver;
use App\Game\Engine\RunState;

function reactState(array $characters): RunState
{
    return new RunState(day: 5, resources: [], characters: $characters);
}

function living(): array
{
    return [
        ['name' => 'Anna', 'alive' => true],
        ['name' => 'Bex', 'alive' => true],
        ['name' => 'Cole', 'alive' => true],
    ];
}

it('returns explicit outcome reactions, filtered to living characters', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => []];
    $outcome = ['reactions' => [
        ['who' => 'Bex', 'tone' => 'approve', 'line' => 'Bene.'],
        ['who' => 'Cole', 'tone' => 'anger', 'line' => 'Idiota.'],
    ]];
    $state = reactState([
        ['name' => 'Bex', 'alive' => true],
        ['name' => 'Cole', 'alive' => false],
    ]);

    $out = $deriver->derive($choice, $outcome, $state);
    expect($out)->toHaveCount(1);
    expect($out[0]['who'])->toBe('Bex');
});

it('derives doctor anger from a cold-choice tag', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => ['sacrifice_crew']];
    $out = $deriver->derive($choice, ['effects' => []], reactState(living()));
    expect($out)->toHaveCount(1);
    expect($out[0]['who'])->toBe('Bex');
    expect($out[0]['tone'])->toBe('anger');
});

it('derives doctor approval from a generous tag', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => ['generous']];
    $out = $deriver->derive($choice, ['effects' => []], reactState(living()));
    expect($out[0]['who'])->toBe('Bex');
    expect($out[0]['tone'])->toBe('approve');
});

it('derives engineer anger when a system is damaged', function () {
    $deriver = new ReactionDeriver();
    $out = $deriver->derive(['tags' => []], ['effects' => [['damage_system' => 'power_grid', 'amount' => 20]]], reactState(living()));
    expect(collect($out)->pluck('who'))->toContain('Anna');
});

it('makes the whole living crew angry on a kill', function () {
    $deriver = new ReactionDeriver();
    $out = $deriver->derive(['tags' => []], ['effects' => [['kill' => 'random']]], reactState(living()));
    expect($out)->toHaveCount(3);
    expect(collect($out)->every(fn ($r) => $r['tone'] === 'anger'))->toBeTrue();
});

it('maps tone to a standing delta', function () {
    $deriver = new ReactionDeriver();
    expect($deriver->standingDelta('anger'))->toBe(-10);
    expect($deriver->standingDelta('approve'))->toBe(8);
    expect($deriver->standingDelta('complicated'))->toBe(0);
});

it('summarizes the first reaction in third person', function () {
    $deriver = new ReactionDeriver();
    $summary = $deriver->summary([['who' => 'Bex', 'tone' => 'anger', 'line' => 'No.']]);
    expect($summary)->toBe('Bex non era d\'accordo.');
    expect($deriver->summary([]))->toBeNull();
});
