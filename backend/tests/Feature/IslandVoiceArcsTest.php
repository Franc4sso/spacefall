<?php

it('seeds three nominal survivor voice arcs, each a 3-beat chain', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['nadia','bruno','carla'] as $who) {
        $beats = \App\Models\Event::where('theme','island')
            ->where('key','like',$who.'_arc_%')->orderBy('key')->pluck('key')->all();
        expect(count($beats))->toBe(3, "arco di $who deve avere 3 beat, trovati: ".implode(',',$beats));
    }
});
