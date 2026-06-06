<?php

namespace App\Game\Sim;

use App\Game\DayProcessor;
use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use App\Models\Run;

/**
 * Headless auto-player. Plays a full run under a policy with seeded RNG and
 * records the decision trail, so the balance harness can reason about *why* a
 * run ended (design §5).
 *
 * Reproducible: same (seed, items, policy) ⇒ same run, because every random
 * draw — engine outcomes AND policy tie-breaks — flows from the run's seed.
 */
final class Simulator
{
    public function __construct(
        private readonly RunFactory $factory,
        private readonly EventEngine $engine,
        private readonly DayProcessor $dayProcessor,
    ) {
    }

    /**
     * Play one run to its end (or maxDays). Returns a SimResult.
     *
     * @param  list<string>  $items
     */
    public function play(int $seed, Policy $policy, array $items = [], int $maxDays = 80): SimResult
    {
        $run = $this->factory->create($seed, $items);
        $steps = [];

        for ($i = 0; $i < $maxDays; $i++) {
            $run = $run->fresh();
            if ($run->status !== 'active') {
                break;
            }

            $card = $this->engine->currentCard($run);
            if ($card['event'] === null) {
                break; // no content — treated as a stall (a bug the fuzz test guards)
            }

            $available = array_values(array_filter($card['choices'], fn ($c) => $c['available']));
            $choiceIndex = $policy->pick($card['choices'], $run->rng());

            // Snapshot the decision: the card, the choices offered, the pick,
            // and the run state BEFORE resolving (so fairness can re-probe).
            $steps[] = [
                'day' => $run->day,
                'event' => $card['event']->key,
                'available_indices' => array_map(fn ($c) => $c['index'], $available),
                'chosen' => $choiceIndex,
                'run_id' => $run->id,
            ];

            $this->engine->resolveChoice($run->fresh(), $choiceIndex);

            $run = $run->fresh();
            if ($run->status !== 'active') {
                $diedOnChoice = true; // a choice ended the run (death or win)
                break;
            }

            $this->dayProcessor->advance($run->fresh());
            $run = $run->fresh();
            if ($run->status !== 'active') {
                $diedOnChoice = false; // end-of-day drain crossed a threshold
                break;
            }
        }

        $final = $run->fresh();

        return new SimResult(
            seed: $seed,
            policy: $policy->name(),
            day: $final->day,
            status: $final->status,
            endingKey: $final->ending_key,
            endingType: $final->ending_type,
            steps: $steps,
            runId: $final->id,
            diedOnChoice: $diedOnChoice ?? false,
        );
    }
}
