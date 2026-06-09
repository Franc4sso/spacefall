<?php

use App\Game\Engine\ReactionDeriver;
use App\Game\Engine\RunState;

function reactRoster(array $names): array
{
    return array_map(fn ($n) => ['name' => $n, 'role' => 'x', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0], $names);
}

it('emits a reaction reflecting a worsening relationship shift', function () {
    $deriver = app(ReactionDeriver::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: reactRoster(['Anna', 'Bex', 'Cole']),
    );

    $choice = ['label' => 'x', 'tags' => []];
    $outcome = ['effects' => [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -12]]], 'log' => 'x'];

    $reactions = $deriver->derive($choice, $outcome, $state);

    // Some reaction names a member of the shifted pair, with a negative-ish tone.
    $relReaction = collect($reactions)->first(fn ($r) => in_array($r['who'], ['Anna', 'Cole'], true));
    expect($relReaction)->not->toBeNull();
    expect(in_array($relReaction['tone'], ['anger', 'complicated'], true))->toBeTrue();
});

it('emits an approving reaction for an improving relationship shift', function () {
    $deriver = app(ReactionDeriver::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: reactRoster(['Anna', 'Bex', 'Cole']),
    );

    $choice = ['label' => 'x', 'tags' => []];
    $outcome = ['effects' => [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => 12]]], 'log' => 'x'];

    $reactions = $deriver->derive($choice, $outcome, $state);

    $relReaction = collect($reactions)->first(fn ($r) => in_array($r['who'], ['Bex', 'Cole'], true));
    expect($relReaction)->not->toBeNull();
    expect($relReaction['tone'])->toBe('approve');
});
