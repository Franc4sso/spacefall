<?php

use App\Game\Engine\EndingService;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('does not award rescue without the SOS having been sent', function () {
    $run = app(RunFactory::class)->create(1, ['comms']);
    $run->day = 25;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 60, 'hull' => 80];
    $run->flags = []; // sos_sent NOT set
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->not->toBe('win_rescue');
});

it('awards rescue once the SOS has been sent', function () {
    $run = app(RunFactory::class)->create(1, ['comms']);
    $run->day = 25;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 60, 'hull' => 80];
    $run->flags = ['sos_sent' => true];
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('win_rescue');
});

it('still gives lone_survivor as a fallback when alive past day 25 with no win-action', function () {
    $run = app(RunFactory::class)->create(1, ['welder']); // no comms/seedbank
    $run->day = 26;
    $run->resources = ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50];
    $run->flags = [];
    // Kill the doctor so crew_intact (needs engineer AND doctor) can't fire —
    // lone_survivor is the correct ending when you're the one left standing.
    $chars = $run->characters;
    foreach ($chars as $i => $c) {
        if (($c['role'] ?? '') === 'doctor') { $chars[$i]['alive'] = false; }
    }
    $run->characters = $chars;
    $run->save();

    app(\App\Game\Engine\EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('lone_survivor');
});
