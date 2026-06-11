<?php

it('seeds three cross-reaction events, one per survivor as commenter', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $keys = \App\Models\Event::where('theme','island')
        ->where('key','like','cross_%')->pluck('key')->all();
    expect(count($keys))->toBe(3, 'attesi 3 cross, trovati: '.implode(',',$keys));
});
