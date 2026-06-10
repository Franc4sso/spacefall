<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use App\Models\Event;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('seeds escape stage 1 gated on the spacesuit', function () {
    $e = Event::where('key', 'escape_1_discovery')->first();
    expect($e)->not->toBeNull();
    expect(json_encode($e->requires))->toContain('spacesuit');
});

it('seeds escape stage 2 gated on escape_found', function () {
    $e = Event::where('key', 'escape_2_repair')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_found', 'is' => true]);
});

it('seeds escape stage 3 gated on escape_repaired', function () {
    $e = Event::where('key', 'escape_3_fuel')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_repaired', 'is' => true]);
});

it('seeds escape stage 4 (who leaves) gated on escape_fueled and setting escape_launched', function () {
    $e = Event::where('key', 'escape_4_who_leaves')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_fueled', 'is' => true]);
    $sets = collect($e->choices)->flatMap(fn ($c) => collect($c['outcomes'] ?? [])->flatMap(fn ($o) => $o['effects'] ?? []))
        ->contains(fn ($eff) => ($eff['set_flag'] ?? null) === 'escape_launched');
    expect($sets)->toBeTrue();
});
