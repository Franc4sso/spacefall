<?php

use App\Models\Run;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

// The browser plays the game over HTTP. A "turn" is: resolve the current card,
// then the day advances and the next card is drawn — exactly the canonical loop
// the Simulator runs (currentCard -> resolveChoice -> advance). These tests pin
// that contract so the day actually progresses and silent cards never freeze.

it('advances the day when a choice is resolved over HTTP', function () {
    $this->seed(EventSeeder::class);

    $run = $this->postJson('/api/runs', ['seed' => 123])->json();
    expect($run['day'])->toBe(1);

    $choice = collect($run['card']['choices'])->firstWhere('available', true)['index'];

    $res = $this->postJson("/api/runs/{$run['id']}/choices", ['choice' => $choice])->assertOk();

    // The turn advanced the day and drew the next card.
    expect($res->json('state.day'))->toBe(2);
    expect($res->json('state.card'))->not->toBeNull();
    // The resolution still carries the choice's log/reactions.
    expect($res->json('resolution'))->toHaveKeys(['log', 'effects', 'reactions']);
});

it('consumes a pinned silent card on /advance and never locks', function () {
    $this->seed(ContentEventSeeder::class);

    // Pin a silent card (no choices) — the frontend auto-advances these.
    // A real run always carries a roster; give this one a living member so the
    // crew_lost ending (living_crew == 0) doesn't fire on the factory's empty
    // default roster — this test is about silent-card flow, not crew death.
    $run = Run::factory()->create([
        'day' => 1,
        'characters' => [['name' => 'Anna', 'role' => 'engineer', 'alive' => true]],
    ]);
    $run->current_event_key = 'silent_window';
    $run->save();

    $res = $this->postJson("/api/runs/{$run->id}/advance")->assertOk();
    expect($res->json('day'))->toBe(2);

    // The silent card must be consumed, not re-pinned — otherwise the UI shows
    // the same silent card forever (the "loading bar that never moves on" bug).
    expect($run->fresh()->current_event_key)->not->toBe('silent_window');

    // Advancing again must keep progressing — proves no infinite lock.
    $res2 = $this->postJson("/api/runs/{$run->id}/advance")->assertOk();
    expect($res2->json('day'))->toBe(3);
});
