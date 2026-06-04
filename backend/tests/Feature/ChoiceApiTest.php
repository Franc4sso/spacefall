<?php

use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
});

it('returns a run with a current card on start', function () {
    $res = $this->postJson('/api/runs', ['seed' => 123])->assertCreated();

    expect($res->json('card'))->not->toBeNull();
    expect($res->json('card.choices'))->not->toBeEmpty();
    expect($res->json('card.title'))->toBeString();
});

it('resolves a choice over HTTP and returns the log plus next state', function () {
    $run = $this->postJson('/api/runs', ['seed' => 123])->json();

    $res = $this->postJson("/api/runs/{$run['id']}/choices", ['choice' => 0])
        ->assertOk();

    expect($res->json('resolution'))->toHaveKeys(['log', 'effects']);
    expect($res->json('state.card'))->not->toBeNull(); // next card already present
});

it('rejects an out-of-range choice', function () {
    $run = $this->postJson('/api/runs', ['seed' => 123])->json();

    $this->postJson("/api/runs/{$run['id']}/choices", ['choice' => 99])
        ->assertStatus(500); // RuntimeException -> 500 (engine guard)
});

it('the GET card endpoint is idempotent for the same pinned card', function () {
    $run = $this->postJson('/api/runs', ['seed' => 123])->json();

    $a = $this->getJson("/api/runs/{$run['id']}/card")->json('card.key');
    $b = $this->getJson("/api/runs/{$run['id']}/card")->json('card.key');

    expect($a)->toBe($b);
});
