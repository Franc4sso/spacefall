<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function stateWith(array $overrides = []): RunState
{
    return new RunState(
        day: $overrides['day'] ?? 1,
        resources: $overrides['resources'] ?? ['oxygen' => 100, 'food' => 50],
        flags: $overrides['flags'] ?? [],
        recentEvents: [],
        scheduledEvents: [],
        profileFlags: $overrides['profileFlags'] ?? [],
        characters: $overrides['characters'] ?? [],
        items: $overrides['items'] ?? [],
        systems: $overrides['systems'] ?? [],
        relationships: $overrides['relationships'] ?? [],
    );
}

beforeEach(function () {
    $this->eval = new ConditionEvaluator();
});

it('treats null and empty as always true', function () {
    expect($this->eval->evaluate(null, stateWith()))->toBeTrue();
    expect($this->eval->evaluate([], stateWith()))->toBeTrue();
});

it('evaluates every comparison operator on a resource', function () {
    $s = stateWith(['resources' => ['food' => 50]]);
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '<', 'value' => 60], $s))->toBeTrue();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '<', 'value' => 50], $s))->toBeFalse();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '<=', 'value' => 50], $s))->toBeTrue();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '=', 'value' => 50], $s))->toBeTrue();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '!=', 'value' => 50], $s))->toBeFalse();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '>=', 'value' => 50], $s))->toBeTrue();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '>', 'value' => 50], $s))->toBeFalse();
});

it('treats a missing resource as zero', function () {
    expect($this->eval->evaluate(['resource' => 'plasma', 'op' => '=', 'value' => 0], stateWith()))->toBeTrue();
});

it('evaluates the day predicate', function () {
    $s = stateWith(['day' => 5]);
    expect($this->eval->evaluate(['day' => ['op' => '>=', 'value' => 5]], $s))->toBeTrue();
    expect($this->eval->evaluate(['day' => ['op' => '>', 'value' => 5]], $s))->toBeFalse();
});

it('evaluates run-scoped and profile-scoped flags', function () {
    $s = stateWith(['flags' => ['vented' => true], 'profileFlags' => ['veteran' => true]]);
    expect($this->eval->evaluate(['flag' => 'vented', 'is' => true], $s))->toBeTrue();
    expect($this->eval->evaluate(['flag' => 'vented', 'is' => false], $s))->toBeFalse();
    expect($this->eval->evaluate(['flag' => 'unset', 'is' => false], $s))->toBeTrue();
    expect($this->eval->evaluate(['flag' => 'veteran', 'scope' => 'profile', 'is' => true], $s))->toBeTrue();
    expect($this->eval->evaluate(['flag' => 'veteran', 'is' => true], $s))->toBeFalse(); // wrong scope
});

it('evaluates has_item', function () {
    $s = stateWith(['items' => ['scanner', 'drone']]);
    expect($this->eval->evaluate(['has_item' => 'scanner'], $s))->toBeTrue();
    expect($this->eval->evaluate(['has_item' => 'welder'], $s))->toBeFalse();
});

it('evaluates has_role only for living characters', function () {
    $s = stateWith(['characters' => [
        ['role' => 'engineer', 'alive' => false],
        ['role' => 'doctor', 'alive' => true],
    ]]);
    expect($this->eval->evaluate(['has_role' => 'doctor'], $s))->toBeTrue();
    expect($this->eval->evaluate(['has_role' => 'engineer'], $s))->toBeFalse(); // dead
});

it('evaluates trait_present', function () {
    $s = stateWith(['characters' => [['alive' => true, 'traits' => ['paranoid', 'genius']]]]);
    expect($this->eval->evaluate(['trait_present' => 'genius'], $s))->toBeTrue();
    expect($this->eval->evaluate(['trait_present' => 'coward'], $s))->toBeFalse();
});

it('evaluates relationship bands', function () {
    $s = stateWith(['relationships' => [['a' => 'x', 'b' => 'y', 'value' => -55]]]);
    expect($this->eval->evaluate(['relationship' => ['state' => 'hatred']], $s))->toBeTrue();
    expect($this->eval->evaluate(['relationship' => ['state' => 'bond']], $s))->toBeFalse();
});

it('evaluates a system field', function () {
    $s = stateWith(['systems' => ['life_support' => ['efficiency' => 40]]]);
    expect($this->eval->evaluate(['system' => 'life_support', 'field' => 'efficiency', 'op' => '<', 'value' => 50], $s))->toBeTrue();
});

it('composes all / any / not, including deep nesting', function () {
    $s = stateWith(['day' => 5, 'resources' => ['food' => 20], 'flags' => ['vented' => true]]);

    $cond = ['all' => [
        ['day' => ['op' => '>=', 'value' => 3]],
        ['any' => [
            ['resource' => 'food', 'op' => '>', 'value' => 90],
            ['not' => ['flag' => 'vented', 'is' => false]],
        ]],
    ]];

    expect($this->eval->evaluate($cond, $s))->toBeTrue();

    // Break the inner branch: food high requirement false AND flag now false.
    $s2 = stateWith(['day' => 5, 'resources' => ['food' => 20], 'flags' => []]);
    expect($this->eval->evaluate($cond, $s2))->toBeFalse();
});

it('is total: unknown kinds and bad operators fail closed without throwing', function () {
    $s = stateWith();
    expect($this->eval->evaluate(['nonsense' => 1], $s))->toBeFalse();
    expect($this->eval->evaluate(['resource' => 'food', 'op' => '~~', 'value' => 1], $s))->toBeFalse();
});

it('evaluates chosen condition true when choice exists in log', function () {
    $state = new \App\Game\Engine\RunState(
        day: 5,
        resources: [],
        choiceLog: [
            ['day' => 2, 'event_key' => 'hull_warning', 'choice_index' => 1, 'tags' => ['ignored_warning']],
        ]
    );
    $ev = new \App\Game\Engine\ConditionEvaluator;
    expect($ev->evaluate(['chosen' => 'hull_warning:1'], $state))->toBeTrue();
    expect($ev->evaluate(['chosen' => 'hull_warning:0'], $state))->toBeFalse();
});

it('evaluates chosen_tag condition', function () {
    $state = new \App\Game\Engine\RunState(
        day: 5,
        resources: [],
        choiceLog: [
            ['day' => 2, 'event_key' => 'hull_warning', 'choice_index' => 1, 'tags' => ['ignored_warning']],
        ]
    );
    $ev = new \App\Game\Engine\ConditionEvaluator;
    expect($ev->evaluate(['chosen_tag' => 'ignored_warning'], $state))->toBeTrue();
    expect($ev->evaluate(['chosen_tag' => 'nonexistent'], $state))->toBeFalse();
});

it('evaluates a standing condition against the run flag', function () {
    $s = stateWith(['flags' => ['standing_cole' => 45]]);
    expect($this->eval->evaluate(['standing' => ['who' => 'Cole', 'op' => '>=', 'value' => 40]], $s))->toBeTrue();
    expect($this->eval->evaluate(['standing' => ['who' => 'Cole', 'op' => '<', 'value' => 40]], $s))->toBeFalse();
});

it('treats a missing standing as zero', function () {
    $s = stateWith();
    expect($this->eval->evaluate(['standing' => ['who' => 'Anna', 'op' => '=', 'value' => 0]], $s))->toBeTrue();
});

it('evaluates not_chosen condition', function () {
    $state = new \App\Game\Engine\RunState(
        day: 5,
        resources: [],
        choiceLog: [
            ['day' => 2, 'event_key' => 'hull_warning', 'choice_index' => 1, 'tags' => []],
        ]
    );
    $ev = new \App\Game\Engine\ConditionEvaluator;
    expect($ev->evaluate(['not_chosen' => 'hull_warning:0'], $state))->toBeTrue();
    expect($ev->evaluate(['not_chosen' => 'hull_warning:1'], $state))->toBeFalse();
});
