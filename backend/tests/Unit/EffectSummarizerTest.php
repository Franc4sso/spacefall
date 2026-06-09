<?php

use App\Game\Engine\EffectSummarizer;

it('summarizes resource deltas and notable events', function () {
    $s = new EffectSummarizer();
    $out = $s->summarize([
        ['resource' => 'oxygen', 'delta' => -12],
        ['resource' => 'morale', 'delta' => 8],
        ['character' => 'Anna', 'stress' => 10],
        ['kill' => 'Cole'],
        ['damage_system' => 'power_grid', 'amount' => 8],
        ['consume_item' => 'medkit'],
    ]);

    expect($out['resources'])->toBe(['oxygen' => -12, 'morale' => 8]);
    expect($out['notes'])->toContain('Anna: stress +10');
    expect($out['notes'])->toContain('morte');
    expect($out['notes'])->toContain('power_grid danneggiato');
    expect($out['notes'])->toContain('medkit consumato');
});

it('returns empty summary for no effects', function () {
    $s = new EffectSummarizer();
    expect($s->summarize([]))->toBe(['resources' => [], 'notes' => []]);
});
