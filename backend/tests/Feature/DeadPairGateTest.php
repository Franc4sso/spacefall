<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

function pairState(array $relationships, array $characters): RunState
{
    return new RunState(
        day: 10,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: $characters,
        relationships: $relationships,
    );
}

function ch(string $name, bool $alive): array
{
    return ['name' => $name, 'role' => 'x', 'traits' => [], 'alive' => $alive, 'stress' => 0, 'hunger' => 0, 'away_until' => 0];
}

it('alive primitive checks a named character is alive', function () {
    $e = new ConditionEvaluator();
    $state = pairState([], [ch('Anna', true), ch('Cole', false)]);
    expect($e->evaluate(['alive' => 'Anna'], $state))->toBeTrue();
    expect($e->evaluate(['alive' => 'Cole'], $state))->toBeFalse();
    expect($e->evaluate(['alive' => 'Ghost'], $state))->toBeFalse(); // absent = not alive
});

it('a named-pair relationship condition fails if either member is dead', function () {
    $e = new ConditionEvaluator();
    // Anna-Cole in hatred, but Cole is dead -> condition must be false.
    $state = pairState([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]], [ch('Anna', true), ch('Cole', false)]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeFalse();
    // Both alive -> true.
    $state2 = pairState([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]], [ch('Anna', true), ch('Cole', true)]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state2))->toBeTrue();
});

it('ungated pair events require both named members alive', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
    // The ungated pair events should now carry an alive-gate on both members.
    foreach (['pair_anna_cole_blame', 'pair_anna_bex_triage', 'pair_bex_cole_reckless'] as $key) {
        $ev = Event::where('key', $key)->first();
        expect($ev)->not->toBeNull("missing {$key}");
        $j = json_encode($ev->requires);
        expect($j)->toContain('alive'); // gated on aliveness now
    }
});
