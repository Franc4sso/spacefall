<?php

use App\Game\Engine\EndingService;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('ends the run with crew_lost (lose) the moment the whole crew is dead', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 30;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    $after = $run->fresh();
    expect($after->ending_key)->toBe('crew_lost');
    expect($after->ending_type)->toBe('lose');
});

it('does not fire crew_lost while at least one member is alive', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 30;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    $chars[0]['alive'] = true;
    for ($i = 1; $i < count($chars); $i++) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->not->toBe('crew_lost');
});

it('fires crew_lost even before day 25 (no day gate)', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 12;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('crew_lost');
});
