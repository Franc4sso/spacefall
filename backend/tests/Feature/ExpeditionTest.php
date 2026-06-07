<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\EventEngine;
use App\Game\Engine\RunState;
use App\Models\Event;
use App\Models\Run;
use Database\Seeders\ContentEventSeeder;

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

it('return events are not eligible without an active expedition', function () {
    $this->seed(ContentEventSeeder::class);

    // A run with NO expedition out: the return events must NOT be drawable from
    // the normal pool (otherwise they fire spuriously, e.g. free loot).
    $run = Run::factory()->create(['day' => 10, 'flags' => []]);
    $state = RunState::fromRun($run);
    $evaluator = app(ConditionEvaluator::class);

    foreach (['exp_return_rich', 'exp_return_modest', 'exp_return_wounded', 'exp_return_lost', 'exp_return_discovery'] as $key) {
        $event = Event::where('key', $key)->first();
        expect($evaluator->evaluate($event->requires, $state))->toBeFalse();
    }
});

it('a full expedition round-trip returns the crew member and clears the away state', function () {
    $this->seed(ContentEventSeeder::class);
    Event::create([
        'key' => 'exp_test2', 'title' => 'Relitto', 'body' => 'x',
        'speaker' => null, 'base_weight' => 1, 'cooldown_days' => 0, 'is_filler' => false,
        'requires' => null, 'weight_modifiers' => null,
        'choices' => [[
            'label' => 'Manda Anna', 'hint' => 'molto pericoloso', 'tags' => [],
            'expedition' => ['who' => 'Anna', 'days' => 2, 'danger' => 1],
            'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'via']],
        ]],
    ]);

    $run = Run::factory()->create([
        'day' => 6,
        'resources' => ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 65, 'hull' => 100],
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 0, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 0, 'alive' => true],
        ],
    ]);
    $run->current_event_key = 'exp_test2';
    $run->save();

    $engine = app(EventEngine::class);
    $engine->resolveChoice($run->fresh(), 0);
    expect($run->fresh()->flags['expedition_active'])->toBeTrue();

    // Advance to the return day; the scheduled return event must be presented.
    app(\App\Game\DayProcessor::class)->advance($run->fresh()); // day 7
    app(\App\Game\DayProcessor::class)->advance($run->fresh()); // day 8 (return day)

    $card = $engine->currentCard($run->fresh());
    expect($card['event']->key)->toStartWith('exp_return_');

    // Resolve the return: the away state must clear and Anna rejoin.
    $engine->resolveChoice($run->fresh(), 0);
    $anna = collect($run->fresh()->characters)->firstWhere('name', 'Anna');
    expect((int) ($anna['away_until'] ?? 0))->toBe(0);
    expect($run->fresh()->flags['expedition_active'] ?? null)->toBeNull();
});
