<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;
use App\Game\Engine\Selector;
use App\Game\SeededRng;
use App\Models\Event;
use Illuminate\Support\Collection;

it('boosts an event weight when its modifier condition holds, biasing selection', function () {
    $selector = new Selector(new ConditionEvaluator());

    $pool = new Collection([
        new Event([
            'key' => 'plain', 'title' => '', 'body' => '', 'base_weight' => 10,
            'cooldown_days' => 0, 'is_filler' => false, 'requires' => null,
            'choices' => [['label' => 'x', 'outcomes' => [['effects' => [], 'log' => '']]]],
            'weight_modifiers' => null,
        ]),
        new Event([
            'key' => 'paranoid_spike', 'title' => '', 'body' => '', 'base_weight' => 10,
            'cooldown_days' => 0, 'is_filler' => false, 'requires' => null,
            'choices' => [['label' => 'x', 'outcomes' => [['effects' => [], 'log' => '']]]],
            // 5x more likely when a paranoid survivor is aboard.
            'weight_modifiers' => [['when' => ['trait_present' => 'paranoid'], 'factor' => 5.0]],
        ]),
    ]);

    $countWith = $countWithout = ['plain' => 0, 'paranoid_spike' => 0];

    for ($s = 0; $s < 2000; $s++) {
        $withParanoid = new RunState(day: 1, resources: ['oxygen' => 50],
            characters: [['alive' => true, 'traits' => ['paranoid']]]);
        $countWith[$selector->select($pool, $withParanoid, new SeededRng($s))->key]++;

        $noParanoid = new RunState(day: 1, resources: ['oxygen' => 50],
            characters: [['alive' => true, 'traits' => ['genius']]]);
        $countWithout[$selector->select($pool, $noParanoid, new SeededRng($s))->key]++;
    }

    // With a paranoid aboard, the spike event dominates; without, it's ~even.
    expect($countWith['paranoid_spike'])->toBeGreaterThan($countWith['plain'] * 3);
    expect(abs($countWithout['paranoid_spike'] - $countWithout['plain']))->toBeLessThan(300);
});
