<?php

use App\Game\Engine\EventEngine;
use App\Game\Engine\ProfileSync;
use App\Game\Engine\RunState;
use App\Game\RunFactory;
use App\Models\Profile;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('exposes meta state for a profile', function () {
    $res = $this->getJson('/api/meta?handle=tester')->assertOk();

    expect($res->json('research_points'))->toBe(0);
    expect($res->json('unlocks'))->toHaveCount(count(config('themes.space.unlocks')));
    expect($res->json('unlocks.0'))->toHaveKeys(['key', 'name', 'cost', 'owned', 'affordable']);
});

it('accrues research points to the profile when a granting event resolves', function () {
    $profile = Profile::resolve('earner');

    $run = app(RunFactory::class)->create(1, ['scanner'], $profile);
    $run->day = 12;
    $run->current_event_key = 'research_breakthrough';
    $run->save();

    // Choice 0 grants 10 research points.
    app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    expect($profile->fresh()->research_points)->toBe(10);
});

it('keeps research points even when the run ends in a loss', function () {
    $profile = Profile::resolve('loser');

    $run = app(RunFactory::class)->create(1, ['scanner'], $profile);
    $run->day = 12;
    // Set up a lethal post-resolution state by draining oxygen to the brink,
    // then resolve the research event (which grants points) — the points must
    // survive the loss.
    $run->current_event_key = 'research_breakthrough';
    $run->save();

    app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    // Now force a death and confirm points are already banked on the profile.
    $dead = $run->fresh();
    $dead->resources = array_merge($dead->resources, ['oxygen' => 0]);
    $dead->save();
    app(\App\Game\Engine\EndingService::class)->check($dead->fresh());

    expect($dead->fresh()->status)->toBe('ended');
    expect($profile->fresh()->research_points)->toBe(10);
});

it('buying an unlock spends points and records it', function () {
    $profile = Profile::resolve('buyer');
    $profile->research_points = 50;
    $profile->save();

    $res = $this->postJson('/api/meta/unlock', ['handle' => 'buyer', 'key' => 'unlock_turret'])
        ->assertOk();

    expect($res->json('research_points'))->toBe(50 - 15);
    expect($res->json('unlocks_owned'))->toContain('unlock_turret');
});

it('refuses an unlock the profile cannot afford', function () {
    Profile::resolve('broke'); // 0 points

    $this->postJson('/api/meta/unlock', ['handle' => 'broke', 'key' => 'unlock_fabricator'])
        ->assertStatus(422);
});

it('an unlock changes the next run\'s pickable items', function () {
    $profile = Profile::resolve('progressor');

    // Locked item not offered before the unlock.
    $before = $this->getJson('/api/items?handle=progressor')->json('items');
    expect(collect($before)->pluck('key'))->not->toContain('turret');

    // A run cannot pick it either.
    $runBefore = app(RunFactory::class)->create(1, ['turret'], $profile->fresh());
    expect($runBefore->items)->not->toContain('turret');

    // Buy the unlock, then it becomes offered and pickable.
    $profile->research_points = 50;
    $profile->save();
    $this->postJson('/api/meta/unlock', ['handle' => 'progressor', 'key' => 'unlock_turret'])->assertOk();

    $after = $this->getJson('/api/items?handle=progressor')->json('items');
    expect(collect($after)->pluck('key'))->toContain('turret');

    $runAfter = app(RunFactory::class)->create(1, ['turret'], $profile->fresh());
    expect($runAfter->items)->toContain('turret');
});

it('a profile flag set in one run is readable by a condition in a later run', function () {
    $profile = Profile::resolve('rememberer');

    // RUN 1: blow a reactor (sets profile-scoped flag blew_a_reactor).
    $run1 = app(RunFactory::class)->create(1, [], $profile);
    $run1->day = 6;
    $run1->current_event_key = 'reactor_gamble';
    $run1->save();
    app(EventEngine::class)->resolveChoice($run1->fresh(), 0);

    expect($profile->fresh()->flags['blew_a_reactor'])->toBeTrue();

    // RUN 2 (a brand-new run on the same profile): the callback event
    // 'old_scorch' requires that profile flag. A fresh RunState should see it.
    $run2 = app(RunFactory::class)->create(2, [], $profile->fresh());
    $state2 = RunState::fromRun($run2->fresh());

    expect($state2->profileFlags['blew_a_reactor'] ?? false)->toBeTrue();

    // And the Selector can surface old_scorch in run 2.
    $engine = app(EventEngine::class);
    $appeared = false;
    for ($s = 0; $s < 40; $s++) {
        $r = $run2->replicate();
        $r->seed = $s;
        $r->rng_cursor = 0;
        $r->current_event_key = null;
        $r->profile_id = $profile->id;
        $r->save();
        if ($engine->currentCard($r)['event']->key === 'old_scorch') {
            $appeared = true;
            break;
        }
    }
    expect($appeared)->toBeTrue();
});
