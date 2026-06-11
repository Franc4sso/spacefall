<?php

namespace App\Game\Engine;

use App\Game\ThemeConfig;

/**
 * Turns a choice's hidden risk into a vague, character-coloured phrase.
 *
 * The player never sees a number. The TRUE risk of a choice is read off its
 * outcome spread (how bad the worst likely outcome is, by net resource loss).
 * The SPEAKER's trait then distorts which risk band's phrase is shown:
 * a 'reliable' speaker (Genius) reports the true band, an 'inflate' speaker
 * (Coward/Paranoid) reports one band scarier, a 'downplay' speaker (Optimist)
 * one band safer. Hidden information becomes characterisation (design §1.5).
 *
 * Pure and deterministic. An author-written `hint` on the choice always wins —
 * this only fills in hints that weren't hand-authored.
 */
final class HintService
{
    public function __construct(
        private readonly ThemeConfig $theme,
    ) {
    }

    /**
     * @param  array<string,mixed>  $choice
     * @param  array<string,mixed>|null  $speaker  the character "saying" the card
     * @return string|null
     */
    public function hintFor(array $choice, ?array $speaker, string $theme = 'space'): ?string
    {
        // Author override wins.
        if (! empty($choice['hint'])) {
            return $choice['hint'];
        }

        $riskBands = $this->theme->for($theme)->get('risk_bands', []);
        $traits = $this->theme->for($theme)->get('traits', []);

        $trueBand = $this->trueRiskBand($choice['outcomes'] ?? []);
        if ($trueBand === null) {
            return null; // no resource stakes => no risk phrase
        }

        $shifted = $this->shiftForSpeaker($trueBand, $speaker, $riskBands, $traits);

        return $riskBands[$shifted]['phrase'] ?? null;
    }

    /**
     * True risk band index, from the worst *likely* outcome's net resource loss.
     * "Likely" weights the loss by each branch's probability so a rare disaster
     * doesn't dominate the read.
     */
    private function trueRiskBand(array $outcomes): ?int
    {
        if ($outcomes === []) {
            return null;
        }

        $totalWeight = 0;
        $expectedLoss = 0.0;
        $anyResource = false;

        foreach ($outcomes as $o) {
            $w = max(1, (int) ($o['weight'] ?? 1));
            $totalWeight += $w;
            $loss = 0;
            foreach ($o['effects'] ?? [] as $e) {
                if (array_key_exists('resource', $e)) {
                    $anyResource = true;
                    $delta = (int) ($e['delta'] ?? 0);
                    if ($delta < 0) {
                        $loss += -$delta;
                    }
                }
            }
            $expectedLoss += $w * $loss;
        }

        if (! $anyResource || $totalWeight === 0) {
            return null;
        }

        $avgLoss = $expectedLoss / $totalWeight;

        // Map expected loss to a band. Thresholds are tuning data; kept here as
        // the only place that translates damage→band so it's easy to retune.
        return match (true) {
            $avgLoss <= 2 => 0, // safe
            $avgLoss <= 6 => 1, // uncertain
            $avgLoss <= 12 => 2, // risky
            default => 3,        // dangerous
        };
    }

    private function shiftForSpeaker(int $band, ?array $speaker, array $riskBands, array $traits): int
    {
        $bias = $this->dominantBias($speaker, $traits);
        $shifted = match ($bias) {
            'inflate' => $band + 1,
            'downplay' => $band - 1,
            default => $band, // reliable / none
        };

        return max(0, min(count($riskBands) - 1, $shifted));
    }

    /**
     * A speaker may carry several traits; the first one with a hint_bias other
     * than 'reliable' wins (fear/optimism colours speech more than neutrality).
     */
    private function dominantBias(?array $speaker, array $traits): string
    {
        if ($speaker === null) {
            return 'reliable';
        }
        foreach ($speaker['traits'] ?? [] as $trait) {
            $bias = $traits[$trait]['hint_bias'] ?? 'reliable';
            if ($bias !== 'reliable') {
                return $bias;
            }
        }
        return 'reliable';
    }
}
