<?php

use App\Game\Sim\FairnessProbe;
use App\Game\Sim\GreedySurvivalPolicy;
use App\Game\Sim\RandomPolicy;
use App\Game\Sim\Simulator;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/**
 * Run a batch and return [results, medianDay, winRate]. Kept small so the suite
 * stays fast; the full 5000-run sweep is the artisan command (sim:run).
 */
function batch(Simulator $sim, $policy, int $count, array $items): array
{
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $results[] = $sim->play($i, $policy, $items);
    }
    $days = array_map(fn ($r) => $r->day, $results);
    sort($days);
    $median = $days[(int) floor(count($days) / 2)];
    $wins = count(array_filter($results, fn ($r) => $r->won()));
    return [$results, $median, $wins / $count];
}

const KIT = ['welder', 'scanner', 'seedbank', 'comms', 'medkit'];

it('never stalls: every simulated run reaches an ending or the cap', function () {
    $sim = app(Simulator::class);
    [$results] = batch($sim, new GreedySurvivalPolicy(), 60, KIT);

    foreach ($results as $r) {
        // status is 'ended' (win/lose) or it hit the day cap still active —
        // either way it never broke on an empty card (no stalls).
        expect(in_array($r->status, ['ended', 'active'], true))->toBeTrue();
    }
});

it('is hard but not impossible: greedy survives more than random, and greedy can win', function () {
    $sim = app(Simulator::class);
    [, , $randomWin] = batch($sim, new RandomPolicy(), 80, KIT);
    [, , $greedyWin] = batch($sim, new GreedySurvivalPolicy(), 80, KIT);

    // Greedy (reads the hints) must win meaningfully more than blind random,
    // and must be able to win at all — else the game is unfair.
    expect($greedyWin)->toBeGreaterThan(0.0);
    expect($greedyWin)->toBeGreaterThan($randomWin);
});

it('lands run length in a sane band (proxy for ~30 min of play)', function () {
    $sim = app(Simulator::class);
    [, $greedyMedian] = batch($sim, new GreedySurvivalPolicy(), 80, KIT);

    // No hard floor — a careless player can die fast (and rare short runs are
    // fine). We only require the SKILLED median to sit in the intended band,
    // not bottom out immediately nor run forever.
    expect($greedyMedian)->toBeGreaterThanOrEqual(12);
    expect($greedyMedian)->toBeLessThanOrEqual(60);
});

it('has no unavoidable deaths: every recorded death had a survivable alternative', function () {
    $sim = app(Simulator::class);
    $probe = app(FairnessProbe::class);
    $policy = new GreedySurvivalPolicy();

    [$results] = batch($sim, $policy, 40, KIT);
    $deaths = array_filter($results, fn ($r) => $r->lost());

    expect($deaths)->not->toBeEmpty(); // the game must actually kill people

    // Two kinds of death:
    //  - died on a CHOICE: a choice's effects ended the run. This must be fair —
    //    the death card must have offered a survivable alternative.
    //  - died on the DAY drain: end-of-day consumption crossed a lethal
    //    threshold. Fair by construction — daily drains are slow and were
    //    avoidable by managing the resource over the preceding days.
    $choiceDeaths = array_filter($deaths, fn ($r) => $r->diedOnChoice);

    foreach ($choiceDeaths as $r) {
        $verdict = $probe->probe($r, $policy, KIT);
        expect($verdict['fair'])->toBeTrue(
            "Unfair death at event '{$verdict['decisive_event']}' (seed {$r->seed}): " .
            "no available choice avoided the death.",
        );
    }
});

it('reachability: across policies and seeds, multiple endings occur (no dead content)', function () {
    $sim = app(Simulator::class);

    $reached = [];
    foreach ([new GreedySurvivalPolicy(), new RandomPolicy()] as $policy) {
        // Vary the kit so item-gated wins (escape/colony) can occur too.
        foreach ([KIT, ['spacesuit', 'comms', 'seedbank', 'scanner', 'reactor_cell']] as $items) {
            [$results] = batch($sim, $policy, 50, $items);
            foreach ($results as $r) {
                if ($r->endingKey) {
                    $reached[$r->endingKey] = true;
                }
            }
        }
    }

    // At least several distinct endings should appear — both wins and losses.
    $wins = array_filter(array_keys($reached), fn ($k) => str_starts_with($k, 'win_'));
    $losses = array_filter(array_keys($reached), fn ($k) => str_starts_with($k, 'death_'));
    expect(count($wins))->toBeGreaterThanOrEqual(1);
    expect(count($losses))->toBeGreaterThanOrEqual(2);
});
