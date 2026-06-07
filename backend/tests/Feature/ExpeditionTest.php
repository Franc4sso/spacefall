<?php

use App\Game\Engine\EventEngine;
use App\Models\Event;
use App\Models\Run;

it('dispatches an expedition: marks away, sets flags, schedules a return', function () {
    Event::create([
        'key' => 'exp_test', 'title' => 'Relitto', 'body' => 'Segnali deboli.',
        'speaker' => null, 'base_weight' => 1, 'cooldown_days' => 0, 'is_filler' => false,
        'requires' => null, 'weight_modifiers' => null,
        'choices' => [
            [
                'label' => 'Manda Anna', 'hint' => 'molto pericoloso', 'tags' => [],
                'expedition' => ['who' => 'Anna', 'days' => 3, 'danger' => 2],
                'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'Anna sparisce nel portello.']],
            ],
        ],
    ]);

    $run = Run::factory()->create([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 0, 'alive' => true],
        ],
    ]);
    $run->current_event_key = 'exp_test';
    $run->save();

    app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    $fresh = $run->fresh();
    expect($fresh->characters[0]['away_until'])->toBe(8); // day 5 + 3
    expect($fresh->flags['away_member'])->toBe('Anna');
    expect($fresh->flags['away_days'])->toBe(3);
    expect($fresh->flags['expedition_active'])->toBeTrue();

    $scheduledKeys = collect($fresh->scheduled_events)->pluck('key');
    expect($scheduledKeys->filter(fn ($k) => str_starts_with($k, 'exp_return_')))->not->toBeEmpty();
});
