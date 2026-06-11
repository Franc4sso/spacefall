<?php

it('island has at least 16 filler/atmosphere events', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $count = \App\Models\Event::where('theme','island')->where('is_filler',true)->count();
    expect($count)->toBeGreaterThanOrEqual(16);
});
