<?php

use App\Game\SeededRng;

it('is reproducible: same seed yields the same stream', function () {
    $a = new SeededRng(12345);
    $b = new SeededRng(12345);

    $streamA = [];
    $streamB = [];
    for ($i = 0; $i < 50; $i++) {
        $streamA[] = $a->nextFloat();
        $streamB[] = $b->nextFloat();
    }

    expect($streamA)->toBe($streamB);
});

it('resumes from a persisted cursor without desync', function () {
    // Draw 10 from a fresh RNG.
    $full = new SeededRng(999);
    $expected = [];
    for ($i = 0; $i < 10; $i++) {
        $expected[] = $full->nextFloat();
    }

    // Draw 5, "persist" the cursor, rebuild, draw the next 5.
    $first = new SeededRng(999);
    $got = [];
    for ($i = 0; $i < 5; $i++) {
        $got[] = $first->nextFloat();
    }
    $resumed = new SeededRng(999, $first->cursor());
    for ($i = 0; $i < 5; $i++) {
        $got[] = $resumed->nextFloat();
    }

    expect($got)->toBe($expected);
});

it('produces different streams for different seeds', function () {
    $a = new SeededRng(1);
    $b = new SeededRng(2);

    expect($a->nextFloat())->not->toBe($b->nextFloat());
});

it('keeps nextFloat in [0, 1)', function () {
    $rng = new SeededRng(7);
    for ($i = 0; $i < 1000; $i++) {
        $v = $rng->nextFloat();
        expect($v)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    }
});

it('keeps nextInt within the inclusive bounds', function () {
    $rng = new SeededRng(7);
    $seen = [];
    for ($i = 0; $i < 2000; $i++) {
        $v = $rng->nextInt(3, 6);
        expect($v)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);
        $seen[$v] = true;
    }
    // Over 2000 draws every value in a 4-wide range should appear.
    expect(array_keys($seen))->toContain(3, 4, 5, 6);
});

it('weights a pick toward the heavier key', function () {
    $rng = new SeededRng(42);
    $counts = ['a' => 0, 'b' => 0];
    for ($i = 0; $i < 4000; $i++) {
        $counts[$rng->weightedPick(['a' => 9, 'b' => 1])]++;
    }
    // 'a' has 9x the weight; it should dominate decisively.
    expect($counts['a'])->toBeGreaterThan($counts['b'] * 4);
});

it('throws on an empty weighted pick', function () {
    $rng = new SeededRng(1);
    $rng->weightedPick([]);
})->throws(InvalidArgumentException::class);
