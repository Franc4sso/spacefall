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
