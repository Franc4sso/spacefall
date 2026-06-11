<?php

it('seeds a jungle expedition with departure and outcome returns', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    expect(\App\Models\Event::where('theme', 'island')->where('key', 'exp_jungle_depart')->exists())->toBeTrue();
    $returns = \App\Models\Event::where('theme', 'island')->where('key', 'like', 'exp_return_%')->count();
    expect($returns)->toBeGreaterThanOrEqual(4);
});
