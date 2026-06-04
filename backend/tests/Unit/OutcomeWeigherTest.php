<?php

use App\Game\Engine\OutcomeWeigher;
use App\Game\SeededRng;

const TRAITS = [
    'genius' => ['hint_bias' => 'reliable', 'luck_shift' => 1.0],
    'lucky' => ['hint_bias' => 'reliable', 'luck_shift' => 1.6],
    'reckless' => ['hint_bias' => 'downplay', 'luck_shift' => 0.65],
];

function weigher(): OutcomeWeigher
{
    return new OutcomeWeigher(TRAITS);
}

/** A 50/50 gamble: one good branch (+5), one bad branch (-15). */
function gamble(): array
{
    return [
        ['weight' => 10, 'effects' => [['resource' => 'oxygen', 'delta' => 5]]],   // good
        ['weight' => 10, 'effects' => [['resource' => 'oxygen', 'delta' => -15]]], // bad
    ];
}

it('leaves weights unchanged for a neutral speaker', function () {
    expect(weigher()->weights(gamble(), ['traits' => ['genius']]))->toBe([10, 10]);
});

it('a Lucky speaker tilts weight toward the good branch', function () {
    $w = weigher()->weights(gamble(), ['traits' => ['lucky']]); // luck_shift 1.6
    expect($w[0])->toBeGreaterThan($w[1]);
});

it('a Reckless speaker tilts weight toward the bad branch', function () {
    $w = weigher()->weights(gamble(), ['traits' => ['reckless']]); // luck_shift 0.65
    expect($w[1])->toBeGreaterThan($w[0]);
});

it('changes the realised outcome distribution over many seeds', function () {
    $rng = fn (int $s) => new SeededRng($s);

    $countGood = function (?array $speaker) use ($rng) {
        $good = 0;
        for ($s = 0; $s < 2000; $s++) {
            $weights = weigher()->weights(gamble(), $speaker);
            $pick = $rng($s)->weightedPick($weights); // 0 = good, 1 = bad
            if ($pick === 0) {
                $good++;
            }
        }
        return $good;
    };

    $neutralGood = $countGood(['traits' => ['genius']]);
    $luckyGood = $countGood(['traits' => ['lucky']]);
    $recklessGood = $countGood(['traits' => ['reckless']]);

    // Lucky should land on the good branch more often than neutral, reckless less.
    expect($luckyGood)->toBeGreaterThan($neutralGood);
    expect($recklessGood)->toBeLessThan($neutralGood);
});
