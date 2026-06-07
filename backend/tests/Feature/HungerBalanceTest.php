<?php

use App\Game\Sim\GreedySurvivalPolicy;
use App\Game\Sim\Simulator;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

function medianDay(Simulator $sim, $policy, array $items, int $count): int
{
    $days = [];
    for ($i = 0; $i < $count; $i++) {
        $days[] = $sim->play($i, $policy, $items)->day;
    }
    sort($days);
    return $days[(int) floor(count($days) / 2)];
}

it('a food-poor crew is pressured but not instantly doomed', function () {
    $sim = app(Simulator::class);
    // No food items in the kit: hunger must bite, but a careful player should
    // not collapse in the first handful of days.
    $median = medianDay($sim, new GreedySurvivalPolicy(), ['welder', 'scanner', 'comms', 'medkit', 'manual'], 60);
    expect($median)->toBeGreaterThanOrEqual(10);
});

it('a food engine lets a skilled crew sustain itself longer', function () {
    $sim = app(Simulator::class);
    $poor = medianDay($sim, new GreedySurvivalPolicy(), ['welder', 'scanner', 'comms', 'medkit', 'manual'], 60);
    $fed = medianDay($sim, new GreedySurvivalPolicy(), ['seedbank', 'rations', 'drone', 'rifle', 'medkit'], 60);
    // Investing the loadout in food must measurably extend survival.
    expect($fed)->toBeGreaterThan($poor);
});
