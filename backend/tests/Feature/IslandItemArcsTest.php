<?php

it('seeds three 3-stage item arcs gated on their item', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['radio','garden','log'] as $arc) {
        $stages = \App\Models\Event::where('theme','island')
            ->where('key','like','arc_'.$arc.'_%')->count();
        expect($stages)->toBe(3, "arc_$arc deve avere 3 stadi");
    }
    $r1 = \App\Models\Event::where('theme','island')->where('key','arc_radio_1')->first();
    expect(json_encode($r1->requires))->toContain('has_item');
});
