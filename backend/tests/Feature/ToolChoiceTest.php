<?php

use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/**
 * Assert that event $key has exactly one choice gated on $item, with both the
 * logic gate (requires.has_item) and the UI hint (requires_item) set.
 * Returns that choice for further effect assertions.
 */
function gatedChoice(string $key, string $item): array
{
    $event = Event::where('key', $key)->firstOrFail();
    $gated = collect($event->choices)->filter(
        fn ($c) => ($c['requires']['has_item'] ?? null) === $item
    )->values();

    expect($gated)->toHaveCount(1, "event {$key} should have exactly one choice gated on {$item}");
    expect($gated[0]['requires_item'] ?? null)->toBe($item, "event {$key} choice missing requires_item UI hint");

    return $gated[0];
}

it('seeds the 7 tool-gated crisis choices', function () {
    $c = gatedChoice('power_flicker', 'welder');
    // Deterministic fix: single outcome, net positive power.
    expect($c['outcomes'])->toHaveCount(1);
    $deltas = collect($c['outcomes'][0]['effects'])->where('resource', 'power')->pluck('delta');
    expect($deltas->sum())->toBeGreaterThan(0);

    $c2 = gatedChoice('technician_panic', 'scanner');
    // Two outcomes: a good one (morale up) and a bad one that reveals a real leak.
    expect($c2['outcomes'])->toHaveCount(2);
    $spawns = collect($c2['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => array_key_exists('spawn_event', $e))
    );
    expect($spawns)->toBeTrue('scanner outcome should sometimes reveal a real leak via spawn_event');

    $c3 = gatedChoice('ration_crisis', 'rifle');
    // Gamble: two outcomes, the good one adds food, the bad one costs stress.
    expect($c3['outcomes'])->toHaveCount(2);
    $foodGain = collect($c3['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => ($e['resource'] ?? null) === 'food' && ($e['delta'] ?? 0) > 0)
    );
    expect($foodGain)->toBeTrue('rifle outcome should be able to gain food');

    $c4 = gatedChoice('ration_night', 'drone');
    // Gamble where the bad outcome consumes the drone.
    expect($c4['outcomes'])->toHaveCount(2);
    $consumes = collect($c4['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => ($e['consume_item'] ?? null) === 'drone')
    );
    expect($consumes)->toBeTrue('drone outcome should be able to consume the drone');

    $c5 = gatedChoice('trap_morale_collapse', 'medkit');
    expect($c5['outcomes'])->toHaveCount(1);
    $effects = collect($c5['outcomes'][0]['effects']);
    expect($effects->contains(fn ($e) => ($e['consume_item'] ?? null) === 'medkit'))
        ->toBeTrue('medkit choice should consume the medkit');
    expect($effects->contains(fn ($e) => ($e['resource'] ?? null) === 'morale' && ($e['delta'] ?? 0) > 0))
        ->toBeTrue('medkit choice should raise morale');

    $c6 = gatedChoice('trap_cascade_failure', 'comms');
    expect($c6['outcomes'])->toHaveCount(1);
    $effects = collect($c6['outcomes'][0]['effects']);
    // Reduced damage to one system, paid for in oxygen.
    expect($effects->contains(fn ($e) => array_key_exists('damage_system', $e)))
        ->toBeTrue('comms choice should still damage a system (reduced, not zero)');
    expect($effects->contains(fn ($e) => ($e['resource'] ?? null) === 'oxygen' && ($e['delta'] ?? 0) < 0))
        ->toBeTrue('comms choice should cost oxygen');
});
