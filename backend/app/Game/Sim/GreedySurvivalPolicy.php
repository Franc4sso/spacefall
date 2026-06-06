<?php

namespace App\Game\Sim;

use App\Game\SeededRng;

/**
 * Always picks the available choice whose visible hint sounds safest.
 *
 * It reads ONLY the hint phrase the player sees — never hidden weights — and
 * ranks phrases by an ordered safety list (lower index = safer). Unknown or
 * absent hints are treated as neutral. Ties break toward the lower index for
 * determinism. This models a cautious-but-uninformed human and is the policy
 * whose win rate must be meaningfully above random for the game to be "fair"
 * (design §5).
 */
final class GreedySurvivalPolicy implements Policy
{
    // Safest → most dangerous. Includes the computed risk_bands phrases and the
    // hand-authored hints used across the content.
    private const SAFER_FIRST = [
        'dovrebbe reggere',
        'giusto, costoso',
        'ne vale la pena?',
        'incerto',
        'azzardo calcolato',
        'azzardo',
        'rischioso',
        'duro per tutti',
        'crudo ma lucido',
        'pericoloso',
        'molto pericoloso',
        'non promette bene',
        'definitivo',
        'irreversibile',
    ];

    public function name(): string
    {
        return 'greedy_survival';
    }

    public function pick(array $choices, SeededRng $rng): int
    {
        $available = array_values(array_filter($choices, fn ($c) => $c['available']));

        $best = $available[0];
        $bestScore = $this->safety($available[0]['hint']);
        foreach ($available as $c) {
            $score = $this->safety($c['hint']);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        return $best['index'];
    }

    /** Lower = safer. Neutral (null/unknown) sits in the middle of the scale. */
    private function safety(?string $hint): int
    {
        if ($hint === null) {
            return (int) (count(self::SAFER_FIRST) / 2);
        }
        $idx = array_search($hint, self::SAFER_FIRST, true);
        return $idx === false ? (int) (count(self::SAFER_FIRST) / 2) : $idx;
    }
}
