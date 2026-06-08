<?php

use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/** Collect every event whose requires tree mentions {phase: X} (at any depth). */
function eventsGatedToPhase(string $phase): \Illuminate\Support\Collection
{
    return Event::all()->filter(function (Event $e) use ($phase) {
        return str_contains(json_encode($e->requires), '"phase":"' . $phase . '"');
    });
}

it('has at least two distinctive events gated to each phase', function () {
    expect(eventsGatedToPhase('isolation')->count())->toBeGreaterThanOrEqual(2);
    expect(eventsGatedToPhase('deterioration')->count())->toBeGreaterThanOrEqual(3); // incl. marker
    expect(eventsGatedToPhase('reckoning')->count())->toBeGreaterThanOrEqual(3); // incl. marker
});

it('keeps every event valid against the DSL schema', function () {
    $schema = new \App\Game\Engine\EventSchema(array_keys(config('game.resources')));
    Event::all()->each(function (Event $e) use ($schema) {
        $schema->validate([
            'key' => $e->key,
            'title' => $e->title,
            'body' => $e->body,
            'choices' => $e->choices,
            'requires' => $e->requires,
        ]);
        expect(true)->toBeTrue();
    });
});
