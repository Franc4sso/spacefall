<?php

use App\Models\Run;

it('starts a run with the configured starting resources', function () {
    $response = $this->postJson('/api/runs', ['seed' => 555])
        ->assertCreated();

    $response->assertJson([
        'day' => 1,
        'status' => 'active',
        'seed' => 555,
    ]);

    foreach (config('themes.space.resources') as $code => $def) {
        expect($response->json("resources.$code"))->toBe($def['start']);
        expect($response->json("resource_meta.$code.max"))->toBe($def['max']);
    }
});

it('fetches the current state of a run', function () {
    $run = $this->postJson('/api/runs', ['seed' => 1])->json();

    $this->getJson("/api/runs/{$run['id']}")
        ->assertOk()
        ->assertJson(['id' => $run['id'], 'day' => 1]);
});

it('consumes resources deterministically on advance', function () {
    $run = $this->postJson('/api/runs', ['seed' => 1])->json('id');

    $after = $this->postJson("/api/runs/{$run}/advance")->assertOk();

    $after->assertJson(['day' => 2]);

    foreach (config('themes.space.resources') as $code => $def) {
        $expected = max(0, min($def['max'], $def['start'] - $def['daily']));
        expect($after->json("resources.$code"))->toBe($expected);
    }
});

it('clamps resources at zero and never goes negative', function () {
    $run = Run::query()->create([
        'seed' => 1,
        'rng_cursor' => 0,
        'day' => 1,
        'resources' => array_fill_keys(array_keys(config('themes.space.resources')), 1),
        'status' => 'active',
    ]);

    // Advance several days; flat consumption would push values negative if unclamped.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson("/api/runs/{$run->id}/advance")->assertOk();
    }

    $state = $this->getJson("/api/runs/{$run->id}")->json('resources');
    foreach ($state as $value) {
        expect($value)->toBeGreaterThanOrEqual(0);
    }
});

it('produces an identical day-by-day trajectory for the same seed', function () {
    $idA = $this->postJson('/api/runs', ['seed' => 7777])->json('id');
    $idB = $this->postJson('/api/runs', ['seed' => 7777])->json('id');

    for ($day = 0; $day < 6; $day++) {
        $a = $this->postJson("/api/runs/{$idA}/advance")->json('resources');
        $b = $this->postJson("/api/runs/{$idB}/advance")->json('resources');
        expect($a)->toBe($b);
    }
});
