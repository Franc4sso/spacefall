<?php

use App\Game\Engine\EventEngine;
use App\Models\Event;
use App\Models\Run;

it('attaches reactions and lowers standing on a cold choice', function () {
    Event::create([
        'key' => 'react_test',
        'title' => 'Prova',
        'body' => 'Una decisione fredda.',
        'speaker' => null,
        'base_weight' => 1,
        'cooldown_days' => 0,
        'is_filler' => false,
        'requires' => null,
        'weight_modifiers' => null,
        'choices' => [
            ['label' => 'Sacrifica', 'tags' => ['sacrifice_crew'],
             'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'Fatto.']]],
        ],
    ]);

    $run = Run::factory()->create([
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'alive' => true],
        ],
    ]);
    $run->current_event_key = 'react_test';
    $run->save();

    $result = app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    expect($result['reactions'])->toHaveCount(1);
    expect($result['reactions'][0]['who'])->toBe('Bex');
    expect($result['reactions'][0]['tone'])->toBe('anger');

    $fresh = $run->fresh();
    expect((int) $fresh->flags['standing_bex'])->toBe(-10);

    $lastLog = collect($fresh->choice_log)->last();
    expect($lastLog['reaction_summary'])->toBe('Bex non era d\'accordo.');
});
