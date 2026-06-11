<?php

it('island has phase-depth events for all three phases', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['iso','det','rec'] as $ph) {
        $n = \App\Models\Event::where('theme','island')->where('key','like',$ph.'_%')->count();
        expect($n)->toBeGreaterThanOrEqual(4, "fase $ph deve avere >=4 eventi");
    }
});
