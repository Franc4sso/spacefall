<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('personalizes the death_notice card with the name, day and cause', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 19;
    $run->death_log = [['name' => 'Anna', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger']];
    $run->current_event_key = 'death_notice';
    $run->save();

    $card = app(EventEngine::class)->currentCard($run->fresh());

    expect($card['event']->body)->toContain('Anna');
    expect($card['event']->body)->toContain('19');
    expect($card['event']->body)->toContain('fame');
});

it('announces each death separately (marks announced, advances to the next)', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 19;
    $run->death_log = [
        ['name' => 'Anna', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger'],
        ['name' => 'Cole', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger'],
    ];
    $run->current_event_key = 'death_notice';
    $run->save();

    $engine = app(EventEngine::class);
    $card1 = $engine->currentCard($run->fresh());
    expect($card1['event']->body)->toContain('Anna');
    $engine->resolveChoice($run->fresh(), 0);

    $after = $run->fresh();
    $log = $after->death_log;
    expect(collect($log)->firstWhere('name', 'Anna')['announced'] ?? false)->toBeTrue();
    expect(collect($log)->firstWhere('name', 'Cole')['announced'] ?? false)->toBeFalse();
});
