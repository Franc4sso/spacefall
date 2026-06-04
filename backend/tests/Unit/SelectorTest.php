<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;
use App\Game\Engine\Selector;
use App\Game\SeededRng;
use App\Models\Event;
use Illuminate\Support\Collection;

function ev(array $attrs): Event
{
    return new Event(array_merge([
        'base_weight' => 10,
        'cooldown_days' => 0,
        'is_filler' => false,
        'requires' => null,
        'choices' => [['label' => 'x', 'outcomes' => [['effects' => [], 'log' => '']]]],
    ], $attrs));
}

function selector(): Selector
{
    return new Selector(new ConditionEvaluator());
}

it('picks a deterministic card for a fixed seed', function () {
    $pool = new Collection([
        ev(['key' => 'a', 'title' => 'A', 'body' => '']),
        ev(['key' => 'b', 'title' => 'B', 'body' => '']),
        ev(['key' => 'c', 'title' => 'C', 'body' => '']),
    ]);
    $state = new RunState(day: 1, resources: ['oxygen' => 50]);

    $first = selector()->select($pool, $state, new SeededRng(7))->key;
    $again = selector()->select($pool, $state, new SeededRng(7))->key;

    expect($first)->toBe($again);
});

it('prioritises a scheduled event due today over the normal pool', function () {
    $pool = new Collection([
        ev(['key' => 'normal', 'title' => 'N', 'body' => '', 'base_weight' => 1000]),
        ev(['key' => 'doom', 'title' => 'D', 'body' => '', 'base_weight' => 1]),
    ]);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 50],
        scheduledEvents: [['key' => 'doom', 'fire_on_day' => 5]],
    );

    expect(selector()->select($pool, $state, new SeededRng(1))->key)->toBe('doom');
});

it('skips events whose requires are unmet', function () {
    $pool = new Collection([
        ev(['key' => 'gated', 'title' => 'G', 'body' => '', 'requires' => ['flag' => 'x', 'is' => true]]),
        ev(['key' => 'filler_only', 'title' => 'F', 'body' => '', 'is_filler' => true]),
    ]);
    $state = new RunState(day: 1, resources: ['oxygen' => 50]); // flag x unset

    // 'gated' ineligible -> only filler remains.
    expect(selector()->select($pool, $state, new SeededRng(1))->key)->toBe('filler_only');
});

it('respects cooldown and falls back to filler when the only themed event is cooling down', function () {
    $pool = new Collection([
        ev(['key' => 'themed', 'title' => 'T', 'body' => '', 'cooldown_days' => 5]),
        ev(['key' => 'filler', 'title' => 'F', 'body' => '', 'is_filler' => true]),
    ]);
    $state = new RunState(
        day: 3,
        resources: ['oxygen' => 50],
        recentEvents: ['themed' => 1], // seen day 1, cooldown 5 -> blocked until day 6
    );

    expect(selector()->select($pool, $state, new SeededRng(1))->key)->toBe('filler');
});

it('NEVER returns nothing: fuzz many random states and always get a card', function () {
    // A realistic pool: some gated themed events + a small filler pool.
    $pool = new Collection([
        ev(['key' => 't1', 'title' => '', 'body' => '', 'requires' => ['resource' => 'food', 'op' => '<', 'value' => 20]]),
        ev(['key' => 't2', 'title' => '', 'body' => '', 'requires' => ['day' => ['op' => '>=', 'value' => 10]]]),
        ev(['key' => 't3', 'title' => '', 'body' => '', 'requires' => ['flag' => 'x', 'is' => true], 'cooldown_days' => 3]),
        ev(['key' => 'f1', 'title' => '', 'body' => '', 'is_filler' => true, 'cooldown_days' => 1]),
        ev(['key' => 'f2', 'title' => '', 'body' => '', 'is_filler' => true, 'cooldown_days' => 1]),
    ]);

    $sel = selector();

    for ($i = 0; $i < 3000; $i++) {
        $rng = new SeededRng($i);
        $state = new RunState(
            day: $rng->nextInt(1, 40),
            resources: [
                'oxygen' => $rng->nextInt(0, 100),
                'food' => $rng->nextInt(0, 100),
                'power' => $rng->nextInt(0, 100),
            ],
            flags: $rng->nextFloat() > 0.5 ? ['x' => true] : [],
            recentEvents: [
                'f1' => $rng->nextInt(0, 40),
                'f2' => $rng->nextInt(0, 40),
                't3' => $rng->nextInt(0, 40),
            ],
        );

        $card = $sel->select($pool, $state, $rng);
        expect($card)->toBeInstanceOf(Event::class);
    }
});

it('still returns a card when every filler is on cooldown (last-resort guarantee)', function () {
    $pool = new Collection([
        ev(['key' => 'f1', 'title' => '', 'body' => '', 'is_filler' => true, 'cooldown_days' => 10]),
    ]);
    $state = new RunState(
        day: 2,
        resources: ['oxygen' => 50],
        recentEvents: ['f1' => 1], // on cooldown
    );

    expect(selector()->select($pool, $state, new SeededRng(1))->key)->toBe('f1');
});
