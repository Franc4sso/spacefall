<?php

namespace App\Game\Sim;

use App\Game\Engine\EndingService;
use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use App\Models\Run;

/**
 * The operational definition of "fair" (design §5): no death is unavoidable.
 *
 * Given a simulated run that LOST, this re-plays it deterministically up to the
 * final decision, then checks every alternative available choice on that last
 * card: if at least one alternative does NOT immediately end the run in death,
 * the death was a consequence of the player's CHOICE, not a card with no
 * survivable option. A card whose every available choice ends in death at that
 * step is an unfair card — the probe flags it.
 *
 * Re-playing from the seed is exact because everything is seeded.
 */
final class FairnessProbe
{
    public function __construct(
        private readonly RunFactory $factory,
        private readonly EventEngine $engine,
        private readonly EndingService $endings,
    ) {
    }

    /**
     * @param  list<string>  $items
     * @return array{fair: bool, decisive_event: ?string, alternatives: int}
     */
    public function probe(SimResult $result, Policy $policy, array $items = []): array
    {
        $last = $result->lastStep();
        if ($last === null) {
            return ['fair' => true, 'decisive_event' => null, 'alternatives' => 0];
        }

        // The death card offered these available choices; the policy took one.
        $available = $last['available_indices'];
        $taken = $last['chosen'];
        $alternatives = array_values(array_filter($available, fn ($i) => $i !== $taken));

        // A single-choice card that kills is unavoidable *at that card*, but the
        // chain leading there is what matters — a single-choice consequence card
        // (e.g. a scheduled cascade) is fair if the EARLIER choice that scheduled
        // it had an alternative. For the last-card test we treat a lone forced
        // choice as fair only if the run had earlier real decisions; a death on
        // the very first card with one option would be unfair.
        if ($alternatives === []) {
            return [
                'fair' => count($result->steps) > 1,
                'decisive_event' => $last['event'],
                'alternatives' => 0,
            ];
        }

        // Re-play to just before the final decision, then try each alternative:
        // if any does NOT end in death immediately, the death was avoidable.
        $survivable = $this->anyAlternativeSurvives($result, $policy, $items, $alternatives);

        return [
            'fair' => $survivable,
            'decisive_event' => $last['event'],
            'alternatives' => count($alternatives),
        ];
    }

    /**
     * @param  list<string>  $items
     * @param  list<int>  $alternatives
     */
    private function anyAlternativeSurvives(SimResult $result, Policy $policy, array $items, array $alternatives): bool
    {
        foreach ($alternatives as $alt) {
            $run = $this->replayToFinalDecision($result, $policy, $items);
            if ($run === null) {
                continue;
            }
            // Take the alternative instead of what the policy took.
            $this->engine->resolveChoice($run->fresh(), $alt);
            $after = $run->fresh();
            $this->endings->check($after);
            if ($after->fresh()->ending_type !== 'lose') {
                return true; // this alternative did not lock in the death
            }
        }
        return false;
    }

    /**
     * Re-create the run from its seed and replay the policy's choices for every
     * step EXCEPT the last, leaving the run pinned on the final card.
     *
     * @param  list<string>  $items
     */
    private function replayToFinalDecision(SimResult $result, Policy $policy, array $items): ?Run
    {
        $run = $this->factory->create($result->seed, $items);
        $upTo = count($result->steps) - 1;

        for ($i = 0; $i < $upTo; $i++) {
            $run = $run->fresh();
            if ($run->status !== 'active') {
                return null;
            }
            $card = $this->engine->currentCard($run);
            if ($card['event'] === null) {
                return null;
            }
            $choice = $policy->pick($card['choices'], $run->rng());
            $this->engine->resolveChoice($run->fresh(), $choice);

            $run = $run->fresh();
            if ($run->status !== 'active') {
                return null;
            }
            app(\App\Game\DayProcessor::class)->advance($run->fresh());
        }

        // Pin the final card so the caller can try alternatives on it.
        $run = $run->fresh();
        if ($run->status !== 'active') {
            return null;
        }
        $this->engine->currentCard($run);
        return $run->fresh();
    }
}
