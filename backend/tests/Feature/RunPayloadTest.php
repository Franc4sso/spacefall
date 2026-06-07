<?php

use App\Models\Run;

it('exposes per-character standing in the run payload', function () {
    $run = Run::factory()->create([
        'flags' => ['crew_trust' => 60, 'standing_anna' => 30, 'standing_bex' => -20],
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'alive' => true],
        ],
    ]);

    $payload = $this->getJson("/api/runs/{$run->id}")->assertOk()->json();

    $byName = collect($payload['characters'])->keyBy('name');
    expect($byName['Anna']['standing'])->toBe(30);
    expect($byName['Bex']['standing'])->toBe(-20);
    expect($byName['Cole']['standing'])->toBe(0); // default
});
