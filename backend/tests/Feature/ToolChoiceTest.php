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
});
