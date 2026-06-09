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

it('keeps food and survival loadouts competitive (no single strategy dominates)', function () {
    $sim = app(Simulator::class);
    $poor = medianDay($sim, new GreedySurvivalPolicy(), ['welder', 'scanner', 'comms', 'medkit', 'manual'], 60);
    $fed = medianDay($sim, new GreedySurvivalPolicy(), ['seedbank', 'rations', 'drone', 'rifle', 'medkit'], 60);
    // Under the harder phase-decay balance the station (oxygen/power/hull) sets a
    // common survival clock, so a food-heavy loadout no longer outlives a
    // survival-utility loadout on raw days — food's payoff moved to WINNING
    // (win_colony needs food >= 68). What we DO want to guarantee is that neither
    // loadout collapses relative to the other: balance means no single kit
    // dominates the survival-day axis. Median days must stay within a tight band.
    expect(abs($fed - $poor))->toBeLessThanOrEqual(2);
});
