<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('exposes the item catalogue and the pick count', function () {
    $res = $this->getJson('/api/items')->assertOk();

    expect($res->json('pick'))->toBe(config('themes.space.items_pick'));
    // Locked items are filtered out for a profile that hasn't unlocked them.
    $unlockedCount = collect(config('themes.space.items'))->reject(fn ($i) => $i['locked'] ?? false)->count();
    expect($res->json('items'))->toHaveCount($unlockedCount);
    expect($res->json('items.0'))->toHaveKeys(['key', 'name', 'description']);
});

it('keeps only valid items, de-duplicates, and caps the pick', function () {
    $run = app(RunFactory::class)->create(1, [
        'welder', 'welder', 'scanner', 'drone', 'bogus_item', 'medkit', 'rifle', 'comms',
    ]);

    // bogus dropped, welder de-duped, capped at items_pick.
    expect($run->items)->not->toContain('bogus_item');
    expect(count($run->items))->toBe(config('themes.space.items_pick'));
    expect(array_count_values($run->items)['welder'] ?? 0)->toBe(1);
});

it('shows an item-gated choice as available only when the item is held', function () {
    $engine = app(EventEngine::class);

    // With the welder: the "Saldo la breccia" choice is available.
    $withWelder = app(RunFactory::class)->create(1, ['welder']);
    $withWelder->current_event_key = 'hull_breach';
    $withWelder->save();
    $cardWith = $engine->currentCard($withWelder->fresh());
    $weldWith = collect($cardWith['choices'])->firstWhere('label', 'Saldo la breccia');
    expect($weldWith['available'])->toBeTrue();

    // Without it: same choice is present but disabled.
    $without = app(RunFactory::class)->create(1, ['scanner']);
    $without->current_event_key = 'hull_breach';
    $without->save();
    $cardWithout = $engine->currentCard($without->fresh());
    $weldWithout = collect($cardWithout['choices'])->firstWhere('label', 'Saldo la breccia');
    expect($weldWithout['available'])->toBeFalse();
});

it('refuses to resolve an item-gated choice without the item', function () {
    $engine = app(EventEngine::class);

    $run = app(RunFactory::class)->create(1, ['scanner']); // no welder
    $run->current_event_key = 'hull_breach';
    $run->save();

    // Choice index 0 is the welder-gated one.
    expect(fn () => $engine->resolveChoice($run->fresh(), 0))
        ->toThrow(RuntimeException::class);
});

it('carries the chosen inventory through the run API', function () {
    $run = $this->postJson('/api/runs', ['seed' => 1, 'items' => ['welder', 'scanner']])
        ->assertCreated();

    $keys = collect($run->json('items'))->pluck('key');
    expect($keys)->toContain('welder', 'scanner');
    expect($run->json('items.0'))->toHaveKeys(['key', 'name', 'description']);
});
