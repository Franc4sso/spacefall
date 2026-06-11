<?php

use App\Game\Engine\EffectApplier;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function applier(): EffectApplier
{
    config(['themes.space.resources' => [
        'oxygen' => ['max' => 100],
        'food'   => ['max' => 100],
        'power'  => ['max' => 100],
        'morale' => ['max' => 100],
    ]]);
    return new EffectApplier(new \App\Game\ThemeConfig());
}

function freshState(array $o = []): RunState
{
    return new RunState(
        day: $o['day'] ?? 3,
        resources: $o['resources'] ?? ['oxygen' => 50, 'food' => 50],
        characters: $o['characters'] ?? [],
        relationships: $o['relationships'] ?? [],
        systems: $o['systems'] ?? [],
        items: $o['items'] ?? [],
    );
}

it('applies a resource delta and clamps to [0, max]', function () {
    $s = freshState(['resources' => ['oxygen' => 95, 'food' => 3]]);
    applier()->apply([
        ['resource' => 'oxygen', 'delta' => 20],  // would be 115 -> 100
        ['resource' => 'food', 'delta' => -10],   // would be -7  -> 0
    ], $s, new SeededRng(1));

    expect($s->resources['oxygen'])->toBe(100);
    expect($s->resources['food'])->toBe(0);
});

it('sets run-scoped and profile-scoped flags', function () {
    $s = freshState();
    applier()->apply([
        ['set_flag' => 'vented', 'value' => true],
        ['set_flag' => 'veteran', 'scope' => 'profile', 'value' => true],
    ], $s, new SeededRng(1));

    expect($s->flags['vented'])->toBeTrue();
    expect($s->profileFlags['veteran'])->toBeTrue();
});

it('schedules a delayed event relative to the current day', function () {
    $s = freshState(['day' => 3]);
    applier()->apply([['spawn_event' => ['key' => 'boom', 'in_days' => 2]]], $s, new SeededRng(1));

    expect($s->scheduledEvents)->toBe([['key' => 'boom', 'fire_on_day' => 5]]);
});

it('adds stress to a targeted character and clamps it', function () {
    $s = freshState(['characters' => [
        ['name' => 'Anna', 'alive' => true, 'stress' => 95],
    ]]);
    applier()->apply([['character' => 'Anna', 'stress' => 20]], $s, new SeededRng(1));

    expect($s->characters[0]['stress'])->toBe(100);
});

it('targets all living survivors with stress (rationing primitive)', function () {
    $s = freshState(['characters' => [
        ['name' => 'A', 'alive' => true, 'stress' => 0],
        ['name' => 'B', 'alive' => false, 'stress' => 0], // dead, skipped
        ['name' => 'C', 'alive' => true, 'stress' => 90],
    ]]);
    applier()->apply([['character' => 'all', 'stress' => 12]], $s, new SeededRng(1));

    expect($s->characters[0]['stress'])->toBe(12);
    expect($s->characters[1]['stress'])->toBe(0);   // dead untouched
    expect($s->characters[2]['stress'])->toBe(100); // clamped
});

it('targets highest_stress', function () {
    $s = freshState(['characters' => [
        ['name' => 'A', 'alive' => true, 'stress' => 10],
        ['name' => 'B', 'alive' => true, 'stress' => 80],
    ]]);
    applier()->apply([['character' => 'highest_stress', 'stress' => 5]], $s, new SeededRng(1));

    expect($s->characters[1]['stress'])->toBe(85);
    expect($s->characters[0]['stress'])->toBe(10);
});

it('kills a targeted character', function () {
    $s = freshState(['characters' => [['name' => 'A', 'alive' => true]]]);
    applier()->apply([['kill' => 'A']], $s, new SeededRng(1));

    expect($s->characters[0]['alive'])->toBeFalse();
});

it('recruits a new survivor', function () {
    $s = freshState();
    applier()->apply([['recruit' => ['role' => 'doctor']]], $s, new SeededRng(1));

    expect($s->characters)->toHaveCount(1);
    expect($s->characters[0]['role'])->toBe('doctor');
});

it('adjusts a relationship symmetrically and clamps to [-100, 100]', function () {
    $s = freshState(['relationships' => [['a' => 'A', 'b' => 'B', 'value' => -95]]]);
    applier()->apply([['relationship' => ['a' => 'B', 'b' => 'A', 'delta' => -20]]], $s, new SeededRng(1));

    expect($s->relationships[0]['value'])->toBe(-100); // clamped, pair matched regardless of order
});

it('creates a relationship when none exists', function () {
    $s = freshState();
    applier()->apply([['relationship' => ['a' => 'A', 'b' => 'B', 'delta' => 15]]], $s, new SeededRng(1));

    expect($s->relationships)->toHaveCount(1);
    expect($s->relationships[0]['value'])->toBe(15);
});

it('damages a system efficiency', function () {
    $s = freshState(['systems' => ['power_grid' => ['efficiency' => 30]]]);
    applier()->apply([['damage_system' => 'power_grid', 'amount' => 50]], $s, new SeededRng(1));

    expect($s->systems['power_grid']['efficiency'])->toBe(0);
});

it('grants research points cumulatively', function () {
    $s = freshState();
    applier()->apply([
        ['grant_research_points' => 5],
        ['grant_research_points' => 3],
    ], $s, new SeededRng(1));

    expect($s->profileFlags['__research_points'])->toBe(8);
});

it('ignores unknown effects without throwing (total)', function () {
    $s = freshState();
    applier()->apply([['mystery_effect' => 1]], $s, new SeededRng(1));
    expect($s->resources)->toBe(['oxygen' => 50, 'food' => 50]);
});

it('consumes an item from state', function () {
    $s = freshState(['items' => ['med_kit', 'torch']]);
    applier()->apply([['consume_item' => 'med_kit']], $s, new SeededRng(1));
    expect($s->items)->toBe(['torch']);
});

it('grants an item to state', function () {
    $s = freshState(['items' => []]);
    applier()->apply([['grant_item' => 'rope']], $s, new SeededRng(1));
    expect($s->items)->toContain('rope');
});

it('does not grant duplicate items', function () {
    $s = freshState(['items' => ['rope']]);
    applier()->apply([['grant_item' => 'rope']], $s, new SeededRng(1));
    expect($s->items)->toHaveCount(1);
});

it('modifies crew_trust flag', function () {
    $s = freshState();
    $s->flags['crew_trust'] = 50;
    applier()->apply([['modify_trust' => -15]], $s, new SeededRng(1));
    expect($s->flags['crew_trust'])->toBe(35);
});

it('clamps crew_trust to 0 minimum', function () {
    $s = freshState();
    $s->flags['crew_trust'] = 5;
    applier()->apply([['modify_trust' => -20]], $s, new SeededRng(1));
    expect($s->flags['crew_trust'])->toBe(0);
});

it('clamps crew_trust to 100 maximum', function () {
    $s = freshState();
    $s->flags['crew_trust'] = 95;
    applier()->apply([['modify_trust' => 20]], $s, new SeededRng(1));
    expect($s->flags['crew_trust'])->toBe(100);
});

it('modifies a character standing flag', function () {
    $s = freshState();
    $s->flags['standing_anna'] = 10;
    applier()->apply([['modify_standing' => ['who' => 'Anna', 'delta' => 15]]], $s, new SeededRng(1));
    expect($s->flags['standing_anna'])->toBe(25);
});

it('defaults standing to zero and clamps to [-100, 100]', function () {
    $s = freshState();
    applier()->apply([['modify_standing' => ['who' => 'Bex', 'delta' => -130]]], $s, new SeededRng(1));
    expect($s->flags['standing_bex'])->toBe(-100);

    applier()->apply([['modify_standing' => ['who' => 'Bex', 'delta' => 250]]], $s, new SeededRng(1));
    expect($s->flags['standing_bex'])->toBe(100);
});

it('is deterministic for random targeting given the same seed', function () {
    $build = fn () => freshState(['characters' => [
        ['name' => 'A', 'alive' => true, 'stress' => 0],
        ['name' => 'B', 'alive' => true, 'stress' => 0],
        ['name' => 'C', 'alive' => true, 'stress' => 0],
    ]]);

    $s1 = $build();
    $s2 = $build();
    applier()->apply([['character' => 'random', 'stress' => 10]], $s1, new SeededRng(42));
    applier()->apply([['character' => 'random', 'stress' => 10]], $s2, new SeededRng(42));

    $stress1 = array_column($s1->characters, 'stress');
    $stress2 = array_column($s2->characters, 'stress');
    expect($stress1)->toBe($stress2);
});

it('reduces hunger on all living survivors', function () {
    $s = freshState(['characters' => [
        ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 60],
        ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 50],
    ]]);
    applier()->apply([['character' => 'all', 'hunger' => -40]], $s, new SeededRng(1));
    expect($s->characters[0]['hunger'])->toBe(20);
    expect($s->characters[1]['hunger'])->toBe(10);
});

it('clamps hunger to [0, 100]', function () {
    $s = freshState(['characters' => [
        ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 30],
    ]]);
    applier()->apply([['character' => 'Anna', 'hunger' => -50]], $s, new SeededRng(1));
    expect($s->characters[0]['hunger'])->toBe(0);
    applier()->apply([['character' => 'Anna', 'hunger' => 130]], $s, new SeededRng(1));
    expect($s->characters[0]['hunger'])->toBe(100);
});

it('targets the hungriest living survivor', function () {
    $s = freshState(['characters' => [
        ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 30],
        ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 80],
        ['name' => 'Cole', 'alive' => false, 'stress' => 0, 'hunger' => 100],
    ]]);
    applier()->apply([['character' => 'hungriest', 'hunger' => -30]], $s, new SeededRng(1));
    expect($s->characters[1]['hunger'])->toBe(50); // Bex was hungriest among the living
    expect($s->characters[0]['hunger'])->toBe(30); // Anna untouched
});

it('excludes away crew from "all" targeting', function () {
    $s = freshState([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0],
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 8], // away (8 > 5)
        ],
    ]);
    applier()->apply([['character' => 'all', 'stress' => 10]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(10); // Anna present -> hit
    expect($s->characters[1]['stress'])->toBe(0);  // Bex away -> spared
});

it('excludes away crew from selector targeting', function () {
    $s = freshState([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 90, 'away_until' => 8], // hungriest but away
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 40],
        ],
    ]);
    applier()->apply([['character' => 'hungriest', 'hunger' => -20]], $s, new SeededRng(1));
    expect($s->characters[0]['hunger'])->toBe(90); // away -> untouched
    expect($s->characters[1]['hunger'])->toBe(20); // present hungriest
});

it('treats crew as present once their return day arrives', function () {
    $s = freshState([
        'day' => 8,
        'characters' => [
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 8], // back today (8 <= 8)
        ],
    ]);
    applier()->apply([['character' => 'all', 'stress' => 5]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(5);
});

it('resolves the expeditioner selector from the away_member flag', function () {
    $s = freshState([
        'day' => 7,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 7],
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0],
        ],
    ]);
    $s->flags['away_member'] = 'Anna';
    applier()->apply([['character' => 'expeditioner', 'stress' => 15]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(15); // Anna (the expeditioner)
    expect($s->characters[1]['stress'])->toBe(0);
});

it('ends an expedition: clears away state and applies a duration-scaled toll', function () {
    $s = freshState([
        'day' => 7,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 7],
        ],
    ]);
    $s->flags['away_member'] = 'Anna';
    $s->flags['away_days'] = 4;
    $s->flags['expedition_active'] = true;

    applier()->apply([['end_expedition' => true]], $s, new SeededRng(1));

    expect($s->characters[0]['away_until'])->toBe(0);     // present again
    expect($s->characters[0]['hunger'])->toBe(16);        // 4 days * 4
    expect($s->characters[0]['stress'])->toBe(8);         // 4 days * 2
    expect($s->flags['away_member'] ?? null)->toBeNull();
    expect($s->flags['expedition_active'] ?? null)->toBeNull();
});
