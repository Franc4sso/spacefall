<?php

it('seeds six pair-arc events across the three couples', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $keys = \App\Models\Event::where('theme','island')
        ->where('key','like','pair_%')->pluck('key')->all();
    expect(count($keys))->toBe(6, 'attesi 6 pair-arc, trovati: '.implode(',',$keys));
    $bond = \App\Models\Event::where('theme','island')->where('key','like','pair_%_bond')->first();
    expect(json_encode($bond->requires))->toContain('bond');
});
