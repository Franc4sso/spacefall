<?php

use App\Game\Engine\ExpeditionResolver;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function expState(array $chars, array $items = []): RunState
{
    return new RunState(day: 5, resources: [], characters: $chars, items: $items);
}

it('scores a fresh, fed, equipped expeditioner to a nearby target as low risk', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $low = $r->score('Anna', days: 2, danger: 1, state: expState($chars, ['spacesuit', 'scanner']));
    $high = $r->score('Anna', days: 5, danger: 3, state: expState(
        [['name' => 'Anna', 'alive' => true, 'stress' => 80, 'hunger' => 80, 'traits' => []]]
    ));
    expect($low)->toBeLessThan($high);
});

it('lowers risk with relevant gear', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $bare = $r->score('Anna', 2, 2, expState($chars, []));
    $geared = $r->score('Anna', 2, 2, expState($chars, ['spacesuit', 'scanner', 'drone']));
    expect($geared)->toBeLessThan($bare);
});

it('resolve returns one of the known tiers', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $tier = $r->resolve('Anna', 3, 2, expState($chars), new SeededRng(1));
    expect(['rich', 'modest', 'wounded', 'lost', 'discovery'])->toContain($tier);
});

it('a very safe trip skews away from lost across seeds', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $lost = 0;
    for ($seed = 0; $seed < 60; $seed++) {
        if ($r->resolve('Anna', 2, 1, expState($chars, ['spacesuit', 'scanner']), new SeededRng($seed)) === 'lost') {
            $lost++;
        }
    }
    expect($lost)->toBeLessThan(6); // < ~10% on a safe trip
});
