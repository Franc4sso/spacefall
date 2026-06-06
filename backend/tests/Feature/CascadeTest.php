<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('an ignored power fault cascades via spawn_event into a later disaster', function () {
    $engine = app(EventEngine::class);

    // Find a seed whose "ignore the fault" branch schedules the cascade.
    $run = null;
    for ($seed = 0; $seed < 80; $seed++) {
        $r = app(RunFactory::class)->create($seed);
        $r->current_event_key = 'power_flicker';
        $r->save();
        $engine->resolveChoice($r->fresh(), 1); // choice 1 = ignore
        if (collect($r->fresh()->scheduled_events)->contains(fn ($s) => $s['key'] === 'power_cascade')) {
            $run = $r->fresh();
            break;
        }
    }

    expect($run)->not->toBeNull('expected an ignore branch to schedule the cascade');

    // The consequence is scheduled for a FUTURE day — the player can trace it.
    $entry = collect($run->scheduled_events)->firstWhere('key', 'power_cascade');
    expect($entry['fire_on_day'])->toBeGreaterThan($run->day);

    // Jump to the fire day: the cascade is the forced next card.
    $run->day = $entry['fire_on_day'];
    $run->current_event_key = null;
    $run->save();

    $card = $engine->currentCard($run->fresh());
    expect($card['event']->key)->toBe('power_cascade');

    // Resolving the cascade with low reserves drives toward a lethal state —
    // a death traceable back to the earlier ignored choice.
    $run = $run->fresh();
    $run->resources = array_merge($run->resources, ['oxygen' => 8, 'power' => 5]);
    $run->save();

    $result = $engine->resolveChoice($run->fresh(), 0); // sacrifice oxygen to reboot
    // Either it ended in death now, or oxygen took the documented hit — the
    // chain is real and consequential.
    $after = $run->fresh();
    expect($after->resources['oxygen'])->toBeLessThan(8);
});
