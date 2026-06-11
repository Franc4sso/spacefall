<?php

use App\Models\Run;

it('persists a theme on a run, defaulting to space', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [],
    ]);
    expect($run->fresh()->theme)->toBe('space');
});

it('accepts an explicit theme', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    expect($run->fresh()->theme)->toBe('island');
});

it('RunState carries the run theme', function () {
    $run = App\Models\Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    $state = App\Game\Engine\RunState::fromRun($run->fresh());
    expect($state->theme)->toBe('island');
});

it('scopes event lookup by theme', function () {
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    expect(App\Models\Event::where('theme', 'island')->where('key', 'shared_key')->first()->title)
        ->toBe('I');
    expect(App\Models\Event::where('theme', 'space')->count())->toBe(1);
});

it('an island run never draws space events', function () {
    App\Models\Event::query()->delete();
    App\Models\Event::create([
        'key' => 'space_only', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'island_only', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    $run = App\Models\Run::create([
        'seed' => 7, 'rng_cursor' => 0, 'day' => 1, 'resources' => ['morale' => 50],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);

    $card = app(App\Game\Engine\EventEngine::class)->currentCard($run->fresh());
    expect($card['event']->key)->toBe('island_only');
});

it('RunFactory initialises resources from the requested theme', function () {
    $factory = app(App\Game\RunFactory::class);
    $run = $factory->create(seed: 1, itemKeys: [], profile: null, theme: 'space');
    $expected = collect(config('themes.space.resources'))->map(fn ($d) => $d['start'])->all();
    expect($run->resources)->toBe($expected);
    expect($run->theme)->toBe('space');
});

it('POST /api/runs starts a run in the requested theme', function () {
    App\Models\Event::create(['key' => 'i', 'theme' => 'island', 'title' => 't', 'body' => 'b', 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => true, 'choices' => [['label' => 'ok', 'outcomes' => []]]]);
    $res = $this->postJson('/api/runs', ['seed' => 5, 'theme' => 'island']);
    $res->assertCreated();
    expect(App\Models\Run::latest('id')->first()->theme)->toBe('island');
});

it('POST /api/runs rejects an unknown theme', function () {
    $this->postJson('/api/runs', ['seed' => 5, 'theme' => 'atlantis'])
        ->assertStatus(422);
});

it('island theme defines its five resources and three systems', function () {
    expect(array_keys(config('themes.island.resources')))
        ->toBe(['water', 'food', 'fire', 'shelter', 'morale']);
    expect(array_keys(config('themes.island.systems')))
        ->toBe(['water_still', 'signal_fire', 'shelter_frame']);
});

it('island morale is two-sided like space', function () {
    expect(config('themes.island.resources.morale.two_sided'))->toBeTrue();
});

it('Simulator plays a run in the given theme', function () {
    App\Models\Event::query()->delete();
    App\Models\Event::create([
        'key' => 'island_filler', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => true,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    $sim = app(App\Game\Sim\Simulator::class);
    $result = $sim->play(seed: 3, policy: new App\Game\Sim\RandomPolicy(), items: [], maxDays: 5, theme: 'island');
    expect($result)->not->toBeNull();
    expect(App\Models\Run::latest('id')->first()->theme)->toBe('island');
});

it('an island run can be played to a conclusion by the sim', function () {
    $this->seed(Database\Seeders\IslandEventSeeder::class);
    $sim = app(App\Game\Sim\Simulator::class);
    $result = $sim->play(seed: 42, policy: new App\Game\Sim\GreedySurvivalPolicy(), items: [], maxDays: 80, theme: 'island');
    expect($result->day)->toBeGreaterThan(1);
});

it('island has a rescue chain that can set rescue_launched', function () {
    $this->seed(Database\Seeders\IslandEventSeeder::class);
    $launchEvent = App\Models\Event::where('theme', 'island')
        ->where('key', 'rescue_4_launch')->first();
    expect($launchEvent)->not->toBeNull();
});
