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

it('seeds a speaker-null hunger_warning surfaced by high hunger bands', function () {
    $warn = \App\Models\Event::where('key', 'hunger_warning')->first();
    expect($warn)->not->toBeNull();
    expect($warn->speaker)->toBeNull();
    $bands = collect(config('themes.space.hunger.spawn_bands'))->pluck('spawn');
    expect($bands)->toContain('hunger_warning');
});

it('schedules a hunger warning when a crew member crosses a high hunger band', function () {
    $run = app(\App\Game\RunFactory::class)->create(3, ['welder']);
    $chars = $run->characters;
    $chars[0]['hunger'] = 60;       // +daily_rise crosses the 65 band
    $chars[0]['hunger_band'] = 1;   // already past the food_ration band
    $run->characters = $chars;
    $run->day = 8;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    $keys = collect($run->fresh()->scheduled_events ?? [])->pluck('key');
    expect($keys)->toContain('hunger_warning');
});
