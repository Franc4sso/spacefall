<?php

use App\Game\Engine\HintService;

const RISK_BANDS = [
    ['key' => 'safe', 'phrase' => 'dovrebbe reggere'],
    ['key' => 'uncertain', 'phrase' => 'incerto'],
    ['key' => 'risky', 'phrase' => 'rischioso'],
    ['key' => 'dangerous', 'phrase' => 'molto pericoloso'],
];

const HINT_TRAITS = [
    'genius' => ['hint_bias' => 'reliable', 'luck_shift' => 1.0],
    'coward' => ['hint_bias' => 'inflate', 'luck_shift' => 1.0],
    'optimist' => ['hint_bias' => 'downplay', 'luck_shift' => 1.0],
];

function hints(): HintService
{
    return new HintService(RISK_BANDS, HINT_TRAITS);
}

/** A choice with one risky branch (net -10) and one safe branch. */
function riskyChoice(): array
{
    return [
        'label' => 'Tento',
        'outcomes' => [
            ['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => -10]]],
            ['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 0]]],
        ],
    ];
}

it('a Coward and a Genius give different hints for the same underlying risk', function () {
    $choice = riskyChoice();

    $genius = ['name' => 'A', 'traits' => ['genius']];   // reliable
    $coward = ['name' => 'B', 'traits' => ['coward']];   // inflate

    $g = hints()->hintFor($choice, $genius);
    $c = hints()->hintFor($choice, $coward);

    expect($g)->not->toBeNull();
    expect($c)->not->toBeNull();
    expect($c)->not->toBe($g); // the coward reports it as more dangerous
});

it('an Optimist downplays relative to a reliable speaker', function () {
    $choice = riskyChoice();

    $reliable = hints()->hintFor($choice, ['traits' => ['genius']]);
    $optimist = hints()->hintFor($choice, ['traits' => ['optimist']]);

    $order = array_column(RISK_BANDS, 'phrase');
    expect(array_search($optimist, $order, true))
        ->toBeLessThan(array_search($reliable, $order, true));
});

it('respects an author-written hint over computed risk', function () {
    $choice = riskyChoice();
    $choice['hint'] = 'fidati di me';

    expect(hints()->hintFor($choice, ['traits' => ['coward']]))->toBe('fidati di me');
});

it('returns null when a choice has no resource stakes', function () {
    $choice = ['label' => 'x', 'outcomes' => [['effects' => [['set_flag' => 'f', 'value' => true]]]]];
    expect(hints()->hintFor($choice, ['traits' => ['genius']]))->toBeNull();
});

it('a null speaker yields the true (reliable) band', function () {
    $choice = riskyChoice();
    $reliable = hints()->hintFor($choice, ['traits' => ['genius']]);
    expect(hints()->hintFor($choice, null))->toBe($reliable);
});
