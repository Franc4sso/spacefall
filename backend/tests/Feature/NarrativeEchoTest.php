<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use App\Models\Event;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('esiste una carta-eco gated su knows_the_past che richiama la scelta', function () {
    $echo = Event::where('key', 'echo_knows_the_past')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires)->toBe(['flag' => 'knows_the_past', 'is' => true]);
    expect(strtolower($echo->body))->toContain('log');
});

it('esiste una carta-eco gated su research_complete', function () {
    $echo = Event::where('key', 'echo_research_complete')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires)->toBe(['flag' => 'research_complete', 'is' => true]);
});

it('esiste una carta-eco gated su ate_alone che richiama lo strappo', function () {
    $echo = Event::where('key', 'echo_ate_alone')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires)->toBe(['all' => [['flag' => 'ate_alone', 'is' => true], ['alive' => 'Anna']]]);
});

it('esiste una carta-eco gated su illness_caught', function () {
    $echo = Event::where('key', 'echo_illness_caught')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires['all'][0])->toBe(['flag' => 'illness_caught', 'is' => true]);
});
