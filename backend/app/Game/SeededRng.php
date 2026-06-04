<?php

namespace App\Game;

/**
 * Deterministic, reproducible PRNG for a single run.
 *
 * Randomness derives purely from (seed, cursor). The cursor is a monotonic
 * counter the caller persists on the Run, so reloading a run mid-play and
 * drawing again continues the exact same stream — no desync. Same seed +
 * same sequence of draws => identical results, on any PHP build / platform.
 *
 * Implementation: each draw hashes "seed:cursor" with SHA-256 and reads 52
 * bits of the digest as a uniform double. We deliberately avoid mt_rand()
 * (not stable across versions) and avoid hand-rolled 64-bit integer mixing
 * (PHP ints overflow to float, which silently breaks determinism). A
 * cryptographic hash is overkill for quality but is exactly stable
 * everywhere and keeps every intermediate value inside PHP's safe integer
 * range — which is the property the whole reproducibility contract rests on.
 */
final class SeededRng
{
    public function __construct(
        private int $seed,
        private int $cursor = 0,
    ) {
    }

    /** Current cursor — persist this on the Run after drawing. */
    public function cursor(): int
    {
        return $this->cursor;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * Float in [0, 1). Advances the cursor by one.
     */
    public function nextFloat(): float
    {
        $this->cursor++;

        // 13 hex digits = 52 bits, comfortably below PHP's 53-bit float
        // precision, so the conversion is exact and platform-independent.
        $digest = hash('sha256', $this->seed . ':' . $this->cursor);
        $bits = hexdec(substr($digest, 0, 13)); // int on 64-bit PHP

        return $bits / (float) (1 << 52);
    }

    /**
     * Integer in [min, max] inclusive.
     */
    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $span = $max - $min + 1;

        return $min + (int) floor($this->nextFloat() * $span);
    }

    /**
     * Weighted pick. $weights maps key => weight (weights > 0).
     * Returns the chosen key. Empty / non-positive input throws — callers
     * must guard (the engine's filler pool guarantees a non-empty set in
     * Phase 2).
     */
    public function weightedPick(array $weights): int|string
    {
        $total = array_sum($weights);

        if ($weights === [] || $total <= 0) {
            throw new \InvalidArgumentException('weightedPick requires positive total weight.');
        }

        $roll = $this->nextFloat() * $total;
        $acc = 0.0;

        foreach ($weights as $key => $weight) {
            $acc += $weight;
            if ($roll < $acc) {
                return $key;
            }
        }

        // Floating-point fall-through guard: return the last key.
        return array_key_last($weights);
    }
}
