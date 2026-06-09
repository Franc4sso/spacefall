<?php

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

it('accepts a named-pair relationship condition at seed-validation time', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));

    // Should not throw — the validator checks the top-level condition kind
    // (relationship), which is whitelisted; the a/b/state sub-shape is allowed.
    $schema->validate([
        'key' => 'rel_probe',
        'title' => 'probe',
        'body' => 'probe',
        'requires' => ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']],
        'choices' => [
            ['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]],
        ],
    ]);

    expect(true)->toBeTrue();
});

it('seeds dedicated pair events that move relationships', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);

    // Events whose choices carry a relationship effect.
    $movers = Event::all()->filter(fn (Event $e) => str_contains(json_encode($e->choices), '"relationship"'));
    expect($movers->count())->toBeGreaterThanOrEqual(10);

    // Each crew pair is referenced by at least one relationship effect. Decode and
    // scan structurally (order-independent) rather than matching a raw substring.
    $pairsSeen = [];
    foreach (Event::all() as $e) {
        foreach (($e->choices ?? []) as $choice) {
            foreach (($choice['outcomes'] ?? []) as $outcome) {
                foreach (($outcome['effects'] ?? []) as $eff) {
                    if (is_array($eff) && isset($eff['relationship']['a'], $eff['relationship']['b'])) {
                        $p = [$eff['relationship']['a'], $eff['relationship']['b']];
                        sort($p);
                        $pairsSeen[implode('-', $p)] = true;
                    }
                }
            }
        }
    }
    foreach ([['Anna', 'Bex'], ['Anna', 'Cole'], ['Bex', 'Cole']] as $pair) {
        sort($pair);
        expect($pairsSeen)->toHaveKey(implode('-', $pair));
    }
});

it('keeps every event valid against the DSL schema', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
    $schema = new \App\Game\Engine\EventSchema(array_keys(config('game.resources')));
    Event::all()->each(function (Event $e) use ($schema) {
        $schema->validate([
            'key' => $e->key, 'title' => $e->title, 'body' => $e->body,
            'choices' => $e->choices, 'requires' => $e->requires,
        ]);
        expect(true)->toBeTrue();
    });
});
