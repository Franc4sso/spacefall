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
