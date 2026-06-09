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
