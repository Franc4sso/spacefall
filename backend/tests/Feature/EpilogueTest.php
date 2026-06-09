<?php

use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('includes a sectioned epilogue in the ending payload of an ended run', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->status = 'ended';
    $run->ending_key = 'lone_survivor';
    $run->ending_type = 'win';
    $run->day = 26;
    $run->death_log = [['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck']];
    $run->save();

    $res = $this->getJson("/api/runs/{$run->id}")->assertOk();

    expect($res->json('ending.epilogue'))->not->toBeNull();
    expect($res->json('ending.epilogue.0.title'))->toBe('Esito');
    $epilogueJson = json_encode($res->json('ending.epilogue'));
    expect($epilogueJson)->toContain('Cole');
});

it('has no epilogue while the run is active', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $res = $this->getJson("/api/runs/{$run->id}")->assertOk();
    expect($res->json('ending'))->toBeNull();
});
