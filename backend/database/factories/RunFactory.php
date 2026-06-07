<?php

namespace Database\Factories;

use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

class RunFactory extends Factory
{
    protected $model = Run::class;

    public function definition(): array
    {
        return [
            'seed'             => $this->faker->numberBetween(1, 999999),
            'rng_cursor'       => 0,
            'day'              => 1,
            'resources'        => [
                'oxygen'  => 100,
                'food'    => 100,
                'fuel'    => 100,
                'morale'  => 100,
                'hull'    => 100,
            ],
            'status'           => 'active',
            'flags'            => [],
            'recent_events'    => [],
            'scheduled_events' => [],
            'characters'       => [],
            'relationships'    => [],
            'items'            => [],
            'systems'          => [],
            'choice_log'       => [],
        ];
    }
}
