# Crew Relationships in Content — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Activate the dormant crew-relationship system so pairs (Anna-Bex, Anna-Cole, Bex-Cole) bond/clash differently each run, the player influences them, and they have visible consequences in crises, expeditions, and reactions.

**Architecture:** One small engine extension — a per-pair relationship predicate (`{relationship:{a,b,state}}`, symmetric, backward-compatible). Movement comes from player choices (data), dedicated pair events (data), and divergent passive drift on the death of a third (engine, in EffectApplier::applyKill). Consequences land in crises (per-pair gating), expeditions (ExpeditionResolver consults relationships), and reactions/diary (visibility). All thresholds/deltas config-driven.

**Tech Stack:** Laravel, PHP, Pest. Engine services: ConditionEvaluator, EffectApplier, ExpeditionResolver, ReactionDeriver (all pure). Config in `config/game.php`. Content in ContentEventSeeder. Sim via `sim:run --memory`.

---

## Background the engineer needs

- **Relationship model:** `RunState::$relationships` = `[{a:string, b:string, value:int}]`,
  value clamped [-100,100], stored as JSON on `runs`. Empty at run start (all neutral).
- **Bands** (`ConditionEvaluator::relationshipBand(int): string`): hatred(<-40), tension(<-10),
  neutral(-10..10), bond(>10), devotion(>40).
- **Effect** `{relationship:{a,b,delta}}` (`EffectApplier::applyRelationship`): find-or-create
  the pair, add delta, clamp. Pair match is **symmetric** via `EffectApplier::samePair($rel,$a,$b)`
  (matches a/b in either order). `clampSigned($v,100)` clamps to [-100,100].
- **Condition** `{relationship:{state}}` (`ConditionEvaluator::evaluateRelationship`): TODAY
  returns true if ANY pair is in that band. We extend it to optionally take `a`/`b`.
- **Crew:** Anna (engineer/genius), Bex (doctor/optimist), Cole (pilot/coward). Names are the
  identity used in `relationships`, `characters[].name`, reactions.
- **ExpeditionResolver::score(string $who,int $days,int $danger,RunState):int** (lower=safer)
  and `resolve(...):string` (draws a tier). Today ignores relationships.
- **ReactionDeriver::derive(array $choice, array $outcome, RunState): list<{who,tone,line}>` —
  pure; derives crew reactions from tags/effects. `summary(reactions): ?string` → diary line.
- **EffectApplier::applyKill(string $selector, RunState, SeededRng)** sets `characters[i]['alive']=false`.
  This is where ALL event-driven deaths flow (sacrifice/triage/expedition `kill` effects).
- **Seeders:** `ContentEventSeeder.php` helpers `ev([...])`, `one(label,effects,log)`,
  `gamble(...)`. `events()` array_merges section methods. `EventSchema::CONDITION_KEYS` /
  `EFFECT_KEYS` already include `relationship`.
- **Tests:** `php artisan test [--filter X]`. Re-seed: `php artisan migrate:fresh --seed --quiet`.
  Sim: `php artisan sim:run --count=200 --items=<csv> --memory --no-interaction`.

## Scope note: hunger-starvation deaths

Passive death-drift (Task 4) is implemented in `EffectApplier::applyKill`, which covers all
**event-driven** deaths (sacrifice, triage, expedition, mutiny) — the narratively significant
cases. Hunger-starvation death happens separately in `DayProcessor::applyHunger` (operates on
raw arrays, no RunState, no EffectApplier) and is a rare path. Wiring drift there would force an
architectural contortion (DI EffectApplier + RunState into DayProcessor's array pipeline) not
worth it for this slice. **Accepted gap:** starvation deaths don't trigger relationship drift.
Documented here so it's a known choice, not an oversight.

## File Structure

- Modify: `backend/app/Game/Engine/ConditionEvaluator.php` — per-pair relationship predicate.
- Modify: `backend/app/Game/Engine/EffectApplier.php` — death-drift in applyKill; expose a symmetric band helper.
- Modify: `backend/app/Game/Engine/ExpeditionResolver.php` — relationship-aware risk.
- Modify: `backend/app/Game/Engine/ReactionDeriver.php` — surface relationship shifts.
- Modify: `backend/config/game.php` — drift + expedition relationship tunables.
- Modify: `backend/database/seeders/ContentEventSeeder.php` — pair events + player-choice relationship effects + per-pair gated crisis variants.
- Test: `backend/tests/Unit/RelationshipConditionTest.php`, `backend/tests/Unit/ExpeditionRelationshipTest.php`, `backend/tests/Feature/RelationshipTest.php`, `backend/tests/Feature/RelationshipContentTest.php`.

---

## Task 1: Per-pair relationship predicate

**Files:**
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Test: `backend/tests/Unit/RelationshipConditionTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/RelationshipConditionTest.php`:

```php
<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function stateWithRel(array $relationships): RunState
{
    return new RunState(
        day: 1,
        resources: ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90],
        relationships: $relationships,
    );
}

it('matches a named pair in a band', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]]); // hatred
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeTrue();
});

it('matches a named pair regardless of stored order (symmetric)', function () {
    $e = new ConditionEvaluator();
    // Stored as Cole/Anna, queried as Anna/Cole — must still match.
    $state = stateWithRel([['a' => 'Cole', 'b' => 'Anna', 'value' => -60]]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeTrue();
});

it('does not match a different pair or a different band', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Anna', 'b' => 'Cole', 'value' => -60]]);
    // different pair
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'state' => 'hatred']], $state))->toBeFalse();
    // same pair, wrong band
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'bond']], $state))->toBeFalse();
});

it('treats a nonexistent named pair as neutral', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([]);
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'neutral']], $state))->toBeTrue();
    expect($e->evaluate(['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']], $state))->toBeFalse();
});

it('keeps any-pair behaviour when a/b are omitted (backward compatible)', function () {
    $e = new ConditionEvaluator();
    $state = stateWithRel([['a' => 'Bex', 'b' => 'Cole', 'value' => 50]]); // devotion
    expect($e->evaluate(['relationship' => ['state' => 'devotion']], $state))->toBeTrue();
    expect($e->evaluate(['relationship' => ['state' => 'hatred']], $state))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RelationshipConditionTest`
Expected: FAIL — named-pair cases fall through to the any-pair branch and give wrong results.

- [ ] **Step 3: Extend evaluateRelationship**

In `backend/app/Game/Engine/ConditionEvaluator.php`, replace the `evaluateRelationship` method
with the version below. It adds the named-pair branch (symmetric match, nonexistent = neutral)
and keeps the existing any-pair branch when `a`/`b` are absent:

```php
    private function evaluateRelationship(array $spec, RunState $state): bool
    {
        $wanted = $spec['state'] ?? 'neutral';

        // Named pair: check ONLY that pair (symmetric match). A pair that has no
        // stored value yet counts as neutral (value 0).
        if (isset($spec['a'], $spec['b'])) {
            $value = 0;
            foreach ($state->relationships as $rel) {
                if ($this->samePair($rel, $spec['a'], $spec['b'])) {
                    $value = $rel['value'] ?? 0;
                    break;
                }
            }
            return $this->relationshipBand($value) === $wanted;
        }

        // Any-pair: true if SOME pair is in the wanted band (unchanged behaviour).
        foreach ($state->relationships as $rel) {
            if ($this->relationshipBand($rel['value'] ?? 0) === $wanted) {
                return true;
            }
        }
        return false;
    }

    /** Symmetric pair match: {a,b} equals {b,a}. Mirrors EffectApplier::samePair. */
    private function samePair(array $rel, string $a, string $b): bool
    {
        return (($rel['a'] ?? null) === $a && ($rel['b'] ?? null) === $b)
            || (($rel['a'] ?? null) === $b && ($rel['b'] ?? null) === $a);
    }
```

(If `relationshipBand` is private and below this method, that's fine — same class. Do not remove it.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter RelationshipConditionTest`
Expected: PASS (all 5).

- [ ] **Step 5: Run the full suite (the any-pair change must not regress the 4 existing relationship events)**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/ConditionEvaluator.php backend/tests/Unit/RelationshipConditionTest.php
git commit -m "feat: per-pair relationship condition predicate (symmetric, backward-compatible)"
```

---

## Task 2: EventSchema accepts a/b/state on the relationship condition

**Files:**
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Test: `backend/tests/Feature/RelationshipContentTest.php`

This guards that seeded events using `{relationship:{a,b,state}}` validate (don't throw at seed
time). `relationship` is already a whitelisted condition key; this task confirms the validator
doesn't reject the `a/b/state` sub-shape, and adds it explicitly if the validator inspects
sub-keys.

- [ ] **Step 1: Inspect the validator**

Read `backend/app/Game/Engine/EventSchema.php::validateCondition`. Determine whether it only
checks the top-level condition key (`relationship`) or also validates the sub-array's keys.
- If it only checks the top-level key: no code change needed — note this and skip to Step 3.
- If it validates sub-keys (e.g. a whitelist for relationship's inner fields): add `'a'`, `'b'`,
  `'state'` to that inner whitelist.

- [ ] **Step 2: Write a guard test**

Create `backend/tests/Feature/RelationshipContentTest.php`:

```php
<?php

use App\Game\Engine\EventSchema;

it('accepts a named-pair relationship condition at seed-validation time', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));

    // Should not throw.
    $schema->validate([
        'key' => 'rel_probe',
        'title' => 'probe',
        'body' => 'probe',
        'requires' => ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']],
        'choices' => [
            ['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]],
        ],
    ]);

    expect(true)->toBeTrue();
});
```

- [ ] **Step 3: Run test**

Run: `cd backend && php artisan test --filter RelationshipContentTest`
Expected: PASS (no exception). If it throws "unknown ... key", apply the Step 1 fix and re-run.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Game/Engine/EventSchema.php backend/tests/Feature/RelationshipContentTest.php
git commit -m "test: validate named-pair relationship condition at seed time"
```

(If EventSchema needed no change, commit only the test.)

---

## Task 3: Symmetric band helper on EffectApplier (for death-drift reuse)

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Test: covered by Task 4's tests (this is a small internal refactor enabling Task 4).

EffectApplier already has private `samePair` and `clampSigned`. Task 4 needs to read a pair's
band and write divergent drift. Add a small private helper now so Task 4 stays focused.

- [ ] **Step 1: Add a band helper to EffectApplier**

In `backend/app/Game/Engine/EffectApplier.php`, add this private method near `samePair`:

```php
    /** Band name for a relationship value. Mirrors ConditionEvaluator::relationshipBand. */
    private function relationshipBand(int $value): string
    {
        return match (true) {
            $value < -40 => 'hatred',
            $value < -10 => 'tension',
            $value > 40 => 'devotion',
            $value > 10 => 'bond',
            default => 'neutral',
        };
    }
```

- [ ] **Step 2: Verify it compiles (run the existing suite)**

Run: `cd backend && php artisan test --filter EffectApplier`
Expected: existing EffectApplier tests still PASS (no behaviour change yet). If there is no
EffectApplier-specific test file, run `php artisan test` and expect 0 new failures.

- [ ] **Step 3: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php
git commit -m "refactor: add relationship band helper to EffectApplier"
```

---

## Task 4: Divergent death-drift in EffectApplier::applyKill

**Files:**
- Modify: `backend/config/game.php`
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Test: `backend/tests/Feature/RelationshipTest.php`

When a crew member dies (event kill), surviving pairs drift DIVERGENTLY: pairs already in
bond/devotion move +delta (grief unites), pairs in tension/hatred move −delta (they blame each
other), neutral pairs unchanged.

- [ ] **Step 1: Add config**

In `backend/config/game.php`, add a top-level key (after the `phase_decay` block):

```php
    /*
     | Crew relationship dynamics. death_drift: when a member dies, surviving
     | pairs shift DIVERGENTLY — pairs already warm grow closer (grief unites),
     | pairs already cold grow colder (blame). Neutral pairs are untouched.
     | expedition: a relationship between the expeditioner and a staying member
     | nudges trip risk (sending half of a hateful pair away frays the ship).
     */
    'relationships' => [
        'death_drift' => 3,        // magnitude applied to surviving pairs on a death
        'expedition_risk' => 3,    // risk nudge per relevant relationship band
    ],
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/RelationshipTest.php`:

```php
<?php

use App\Game\Engine\EffectApplier;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function rosterOf(array $names): array
{
    return array_map(fn ($n) => ['name' => $n, 'role' => 'x', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0], $names);
}

it('warm survivor pairs grow closer when a third dies; cold pairs grow colder', function () {
    $applier = app(EffectApplier::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: rosterOf(['Anna', 'Bex', 'Cole']),
        relationships: [
            ['a' => 'Anna', 'b' => 'Bex', 'value' => 20],   // bond -> +3 -> 23
            ['a' => 'Anna', 'b' => 'Cole', 'value' => -20],  // tension -> -3 -> -23
        ],
    );

    // Cole dies (a "third" relative to the Anna-Bex and Anna-Cole pairs).
    $applier->apply([['kill' => 'Cole']], $state, new SeededRng(1));

    $byPair = [];
    foreach ($state->relationships as $r) {
        $byPair[$r['a'] . '-' . $r['b']] = $r['value'];
    }

    // Anna-Bex both survive and were in bond -> +3.
    expect($byPair['Anna-Bex'])->toBe(23);
    // Anna-Cole involves the dead member -> NOT drifted by the survivor rule.
    expect($byPair['Anna-Cole'])->toBe(-20);
});

it('leaves neutral surviving pairs unchanged on a death', function () {
    $applier = app(EffectApplier::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: rosterOf(['Anna', 'Bex', 'Cole']),
        relationships: [
            ['a' => 'Anna', 'b' => 'Bex', 'value' => 0], // neutral
        ],
    );

    $applier->apply([['kill' => 'Cole']], $state, new SeededRng(1));

    $val = collect($state->relationships)->firstWhere(fn ($r) => $r['a'] === 'Anna' && $r['b'] === 'Bex')['value'];
    expect($val)->toBe(0);
});
```

NOTE: confirm `EffectApplier::apply(array $effects, RunState, SeededRng)` is the public entry
point (read the top of EffectApplier). If the signature differs (e.g. a single-effect method),
adjust the two `apply(...)` calls in the test to match the real public API — keep the assertions.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RelationshipTest`
Expected: FAIL — Anna-Bex stays 20 (no drift applied yet).

- [ ] **Step 4: Implement drift in applyKill**

In `backend/app/Game/Engine/EffectApplier.php`, change `applyKill` so that after a member is
marked dead, surviving pairs drift. Replace the method body:

```php
    private function applyKill(string $selector, RunState $state, SeededRng $rng): void
    {
        $index = $this->resolveTarget($selector, $state, $rng);
        if ($index === null) {
            return;
        }

        $deadName = $state->characters[$index]['name'] ?? null;
        $state->characters[$index]['alive'] = false;

        if ($deadName !== null) {
            $this->applyDeathDrift($deadName, $state);
        }
    }

    /**
     * On a death, surviving pairs drift divergently: warm pairs (bond/devotion)
     * grow closer, cold pairs (tension/hatred) grow colder. Pairs that include
     * the dead member, and neutral pairs, are left alone.
     */
    private function applyDeathDrift(string $deadName, RunState $state): void
    {
        $mag = (int) config('game.relationships.death_drift', 0);
        if ($mag === 0) {
            return;
        }

        foreach ($state->relationships as $i => $rel) {
            $a = $rel['a'] ?? null;
            $b = $rel['b'] ?? null;
            if ($a === $deadName || $b === $deadName) {
                continue; // pair involves the deceased — survivor rule doesn't apply
            }
            $band = $this->relationshipBand((int) ($rel['value'] ?? 0));
            $delta = match ($band) {
                'bond', 'devotion' => $mag,
                'tension', 'hatred' => -$mag,
                default => 0,
            };
            if ($delta !== 0) {
                $state->relationships[$i]['value'] = $this->clampSigned((int) ($rel['value'] ?? 0) + $delta, 100);
            }
        }
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter RelationshipTest`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 7: Commit**

```bash
git add backend/config/game.php backend/app/Game/Engine/EffectApplier.php backend/tests/Feature/RelationshipTest.php
git commit -m "feat: divergent relationship drift when a crew member dies"
```

---

## Task 5: Relationship-aware expeditions

**Files:**
- Modify: `backend/app/Game/Engine/ExpeditionResolver.php`
- Test: `backend/tests/Unit/ExpeditionRelationshipTest.php`

`score()` (lower = safer) gains a relationship term: if the expeditioner is in hatred with a
staying crew member, risk goes UP; in bond, risk goes DOWN. Neutral → no change (zero
regression).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/ExpeditionRelationshipTest.php`:

```php
<?php

use App\Game\Engine\ExpeditionResolver;
use App\Game\Engine\RunState;

function expState(array $relationships): RunState
{
    return new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: [
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0],
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'alive' => true, 'away_until' => 0],
        ],
        items: [],
        relationships: $relationships,
    );
}

it('raises expedition risk when the expeditioner is in hatred with a staying member', function () {
    $r = new ExpeditionResolver();
    $neutral = $r->score('Cole', 3, 2, expState([]));
    $hateful = $r->score('Cole', 3, 2, expState([['a' => 'Cole', 'b' => 'Anna', 'value' => -60]]));
    expect($hateful)->toBeGreaterThan($neutral);
});

it('lowers expedition risk when the expeditioner is in bond with a staying member', function () {
    $r = new ExpeditionResolver();
    $neutral = $r->score('Cole', 3, 2, expState([]));
    $bonded = $r->score('Cole', 3, 2, expState([['a' => 'Cole', 'b' => 'Anna', 'value' => 30]]));
    expect($bonded)->toBeLessThan($neutral);
});

it('does not change risk when all relationships are neutral (zero regression)', function () {
    $r = new ExpeditionResolver();
    $a = $r->score('Cole', 3, 2, expState([]));
    $b = $r->score('Cole', 3, 2, expState([['a' => 'Cole', 'b' => 'Anna', 'value' => 0]]));
    expect($a)->toBe($b);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter ExpeditionRelationshipTest`
Expected: FAIL — hateful/bonded equal neutral (no relationship term yet).

- [ ] **Step 3: Add the relationship term to score()**

In `backend/app/Game/Engine/ExpeditionResolver.php`, add a private helper and fold it into the
`score()` return. Replace the `return (...)` at the end of `score()` with:

```php
        return ($danger * 6)
            + (int) (($stress + $hunger) / 10)
            + max(0, $days - 2) * 2
            - ($gear * 4)
            + $traitShift
            + $this->relationshipRisk($who, $state);
```

and add these private methods to the class:

```php
    /**
     * Risk nudge from the expeditioner's relationships with crew who stay behind.
     * Hatred with a stayer frays the ship (risk up); a bond steadies it (risk
     * down). Summed over staying members; neutral pairs contribute nothing, so an
     * all-neutral run scores exactly as before.
     */
    private function relationshipRisk(string $who, RunState $state): int
    {
        $mag = (int) config('game.relationships.expedition_risk', 0);
        if ($mag === 0) {
            return 0;
        }

        $risk = 0;
        foreach ($state->relationships as $rel) {
            $other = $this->otherInPair($rel, $who);
            if ($other === null) {
                continue;
            }
            $band = $this->relationshipBand((int) ($rel['value'] ?? 0));
            $risk += match ($band) {
                'hatred' => $mag * 2,
                'tension' => $mag,
                'bond' => -$mag,
                'devotion' => -$mag * 2,
                default => 0,
            };
        }
        return $risk;
    }

    /** If $who is in the pair, return the other member's name; else null. */
    private function otherInPair(array $rel, string $who): ?string
    {
        if (($rel['a'] ?? null) === $who) {
            return $rel['b'] ?? null;
        }
        if (($rel['b'] ?? null) === $who) {
            return $rel['a'] ?? null;
        }
        return null;
    }

    /** Band name for a relationship value. Mirrors ConditionEvaluator::relationshipBand. */
    private function relationshipBand(int $value): string
    {
        return match (true) {
            $value < -40 => 'hatred',
            $value < -10 => 'tension',
            $value > 40 => 'devotion',
            $value > 10 => 'bond',
            default => 'neutral',
        };
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter ExpeditionRelationshipTest`
Expected: PASS (all 3, including zero-regression).

- [ ] **Step 5: Full suite (existing ExpeditionTest must stay green)**

Run: `cd backend && php artisan test`
Expected: 0 failures. (Existing expedition tests use neutral relationships, so scores are
unchanged. If any expedition test fails on a score number, STOP and report — it would mean a
test seeds non-neutral relationships unexpectedly.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/ExpeditionResolver.php backend/tests/Unit/ExpeditionRelationshipTest.php
git commit -m "feat: expeditions consult expeditioner's crew relationships"
```

---

## Task 6: Surface relationship shifts in reactions + diary

**Files:**
- Modify: `backend/app/Game/Engine/ReactionDeriver.php`
- Test: `backend/tests/Feature/RelationshipTest.php` (append)

When a choice's outcome contains a `relationship` effect, ReactionDeriver emits a reaction for
the involved pair. Assert on the STRUCTURED data (who + a tone reflecting the direction), NOT on
exact Italian prose.

- [ ] **Step 1: Append the failing test**

Append to `backend/tests/Feature/RelationshipTest.php`:

```php
it('emits a reaction reflecting a relationship shift in the outcome', function () {
    $deriver = app(\App\Game\Engine\ReactionDeriver::class);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: rosterOf(['Anna', 'Bex', 'Cole']),
    );

    // A choice whose outcome worsens Anna-Cole.
    $choice = ['label' => 'x', 'tags' => []];
    $outcome = ['effects' => [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -12]]], 'log' => 'x'];

    $reactions = $deriver->derive($choice, $outcome, $state);

    // A reaction names one of the pair and carries a negative ('anger'/'complicated') tone.
    $names = array_map(fn ($r) => $r['who'], $reactions);
    expect($names)->toContain('Anna')->or->toContain('Cole');
    $relReaction = collect($reactions)->first(fn ($r) => in_array($r['who'], ['Anna', 'Cole'], true));
    expect($relReaction)->not->toBeNull();
    expect(in_array($relReaction['tone'], ['anger', 'complicated'], true))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RelationshipTest`
Expected: FAIL — no reaction derived from the relationship effect.

- [ ] **Step 3: Add relationship-shift reactions to derive()**

In `backend/app/Game/Engine/ReactionDeriver.php`, in `derive()`, AFTER the existing tag/effect
reactions are built and BEFORE `return $out;`, add a block that scans the outcome's effects for
`relationship` shifts and appends a reaction for a living member of the pair:

```php
        // Relationship shifts surface as a spoken beat so the player SEES the
        // dynamic move: a worsening pair reads as friction, an improving one as
        // warmth. Tone is structural (derived from delta sign), not authored copy.
        foreach ($effects as $e) {
            if (! is_array($e) || ! array_key_exists('relationship', $e)) {
                continue;
            }
            $spec = $e['relationship'];
            $a = $spec['a'] ?? null;
            $b = $spec['b'] ?? null;
            $delta = (int) ($spec['delta'] ?? 0);
            if ($a === null || $b === null || $delta === 0) {
                continue;
            }
            $speaker = $this->isAlive($a, $state) ? $a : ($this->isAlive($b, $state) ? $b : null);
            $other = $speaker === $a ? $b : $a;
            if ($speaker === null) {
                continue;
            }
            if ($delta < 0) {
                $out[] = ['who' => $speaker, 'tone' => 'complicated', 'line' => "Qualcosa tra me e {$other} si è incrinato."];
            } else {
                $out[] = ['who' => $speaker, 'tone' => 'approve', 'line' => "Io e {$other} ci siamo capiti."];
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter RelationshipTest`
Expected: PASS.

- [ ] **Step 5: Full suite (reaction change must not break existing reaction/diary tests)**

Run: `cd backend && php artisan test`
Expected: 0 failures. (The new block only fires when an outcome carries a `relationship`
effect; existing outcomes don't, so prior reactions are unchanged. If a reaction/diary test
fails, STOP and report — a shared outcome may now emit an extra reaction.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/ReactionDeriver.php backend/tests/Feature/RelationshipTest.php
git commit -m "feat: surface crew relationship shifts in reactions and diary"
```

---

## Task 7: Player-choice relationship effects + dedicated pair events

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/RelationshipContentTest.php` (append)

Add the content: ~6 dedicated pair events (2 per pair), some gated on the pair's current band so
dynamics self-feed, plus relationship effects woven into a few existing triage/sacrifice choices.

- [ ] **Step 1: Append the failing data test**

Append to `backend/tests/Feature/RelationshipContentTest.php`:

```php
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

it('seeds dedicated pair events that move relationships', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);

    // Events whose choices carry a {relationship:{a,b,delta}} effect.
    $movers = Event::all()->filter(function (Event $e) {
        return str_contains(json_encode($e->choices), '"relationship"');
    });

    // At least the ~6 new pair events plus pre-existing relationship events.
    expect($movers->count())->toBeGreaterThanOrEqual(10);

    // Each crew pair is referenced by at least one relationship effect.
    $json = json_encode(Event::all()->pluck('choices'));
    foreach ([['Anna', 'Bex'], ['Anna', 'Cole'], ['Bex', 'Cole']] as [$x, $y]) {
        $hit = str_contains($json, "\"a\":\"{$x}\",\"b\":\"{$y}\"")
            || str_contains($json, "\"a\":\"{$y}\",\"b\":\"{$x}\"");
        expect($hit)->toBeTrue("pair {$x}-{$y} must be moved by some event");
    }
});

it('keeps every event valid against the DSL schema', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
    $schema = new EventSchema(array_keys(config('game.resources')));
    Event::all()->each(function (Event $e) use ($schema) {
        $schema->validate([
            'key' => $e->key, 'title' => $e->title, 'body' => $e->body,
            'choices' => $e->choices, 'requires' => $e->requires,
        ]);
        expect(true)->toBeTrue();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RelationshipContentTest`
Expected: FAIL — not enough relationship-moving events / a pair not covered.

- [ ] **Step 3: Add pair events**

In `backend/database/seeders/ContentEventSeeder.php`, add a `pairEvents()` method returning the
events below, and add `$this->pairEvents(),` to the `array_merge(...)` in `events()`. These cover
all three pairs, two archetypes each (friction / solidarity), with some gated on the pair's band:

```php
    // ---- Dedicated crew-pair events (relationships in content, Tier 2 #4) ----
    private function pairEvents(): array
    {
        return [
            // Anna-Cole: friction (engineer vs pilot over priorities)
            $this->ev([
                'key' => 'pair_anna_cole_blame', 'title' => 'Di chi è la colpa', 'speaker' => null,
                'body' => "Anna accusa Cole di aver forzato una manovra che ha stressato lo scafo. Cole dice che senza quella manovra sareste già morti. Ti guardano entrambi.",
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Dai ragione ad Anna', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -12]], ['modify_standing' => ['who' => 'Anna', 'delta' => 8]]], 'Cole stringe la mascella e tace.'),
                    $this->one('Dai ragione a Cole', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -12]], ['modify_standing' => ['who' => 'Cole', 'delta' => 8]]], 'Anna esce senza una parola.'),
                    $this->one('Fermali: nessuna colpa, solo lavoro', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => 8]], ['resource' => 'morale', 'delta' => -3]], 'Borbottano, ma tornano al lavoro fianco a fianco.'),
                ],
            ]),
            // Anna-Cole: solidarity, gated on them already being warm
            $this->ev([
                'key' => 'pair_anna_cole_cover', 'title' => 'Una copertura', 'speaker' => 'Cole',
                'body' => "Cole ti prende da parte: Anna ha commesso un errore che non ha confessato. Lui se n'è accorto e l'ha già sistemato. 'Non serve dirlo a nessuno, vero?'",
                'requires' => ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'bond']],
                'base_weight' => 8, 'cooldown_days' => 10,
                'choices' => [
                    $this->one('Lascia che la copra', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => 10]]], 'Restano uniti. Hanno un segreto, ora.'),
                    $this->one('Esigi trasparenza', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -6]], ['modify_trust' => 6]], 'Onestà imposta. Più fredda tra loro.'),
                ],
            ]),

            // Anna-Bex: friction (head vs heart on a patient)
            $this->ev([
                'key' => 'pair_anna_bex_triage', 'title' => 'Testa o cuore', 'speaker' => null,
                'body' => "Bex vuole spendere energia preziosa per tenere caldo un membro malato. Anna dice che è uno spreco che condanna tutti. Discutono a voce alta.",
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Con Anna: razionalità', [['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -12]], ['resource' => 'power', 'delta' => 6]], 'Bex ti guarda come se non ti riconoscesse.'),
                    $this->one('Con Bex: umanità', [['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -12]], ['resource' => 'power', 'delta' => -8], ['resource' => 'morale', 'delta' => 6]], 'Anna scuote la testa e se ne va.'),
                    $this->one('Trova un compromesso', [['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => 8]], ['resource' => 'power', 'delta' => -3]], 'Mezza soluzione. Ma restano alleate.'),
                ],
            ]),
            // Anna-Bex: solidarity
            $this->ev([
                'key' => 'pair_anna_bex_repair', 'title' => 'Quattro mani', 'speaker' => 'Bex',
                'body' => "Bex si offre di aiutare Anna in una riparazione lunga e noiosa, solo per non lasciarla sola. Anna esita: non è brava a farsi aiutare.",
                'base_weight' => 8, 'cooldown_days' => 9,
                'choices' => [
                    $this->one('Incoraggia Anna ad accettare', [['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => 12]]], 'Lavorano in silenzio, vicine. Qualcosa si scioglie.'),
                    $this->one('Lascia che Anna faccia da sola', [['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -4]], ['character' => 'Anna', 'stress' => 6]], 'Anna finisce da sola, esausta.'),
                ],
            ]),

            // Bex-Cole: friction, gated on tension so it follows a sour streak
            $this->ev([
                'key' => 'pair_bex_cole_reckless', 'title' => 'Troppo o troppo poco', 'speaker' => null,
                'body' => "Bex accusa Cole di prendersi rischi che mettono tutti in pericolo. Cole risponde che la prudenza di Bex vi farà morire lenti. La tensione è palpabile.",
                'requires' => ['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'state' => 'tension']],
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Frena Cole', [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => -8]], ['modify_standing' => ['who' => 'Bex', 'delta' => 6]]], 'Cole obbedisce, rancoroso.'),
                    $this->one('Sostieni Cole', [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => -8]], ['modify_standing' => ['who' => 'Cole', 'delta' => 6]]], 'Bex stringe le labbra.'),
                    $this->one('Costringili a parlarsi', [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => 12]], ['resource' => 'morale', 'delta' => -4]], 'Una conversazione dura. Ma alla fine, una tregua.'),
                ],
            ]),
            // Bex-Cole: solidarity
            $this->ev([
                'key' => 'pair_bex_cole_fear', 'title' => 'La paura condivisa', 'speaker' => 'Bex',
                'body' => "Cole ha avuto un attacco di panico al buio. Bex lo ha trovato e non lo ha deriso — è rimasta con lui finché non è passato. Te lo racconta, chiedendo di non dirlo.",
                'base_weight' => 8, 'cooldown_days' => 9,
                'choices' => [
                    $this->one('Rispetta il loro momento', [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => 12]]], 'Un patto silenzioso tra loro due.'),
                    $this->one('Usa la cosa per tenerli in riga', [['relationship' => ['a' => 'Bex', 'b' => 'Cole', 'delta' => -10]], ['modify_trust' => -8]], 'Funziona. Ma ti guardano diversamente, ora.'),
                ],
            ]),
        ];
    }
```

- [ ] **Step 4: Re-seed and run the content test**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter RelationshipContentTest`
Expected: PASS (≥10 relationship-moving events, all three pairs covered, schema valid).

- [ ] **Step 5: Full suite + Selector-never-stalls (existing ContentTest covers the pool)**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/RelationshipContentTest.php
git commit -m "feat: dedicated crew-pair events moving all three relationships"
```

---

## Task 8: Per-pair gated crisis variants

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/RelationshipContentTest.php` (append)

Add 2-3 crisis events that read the per-pair predicate so an ongoing crisis plays differently
depending on a specific pair's band (hatred → friction variant; bond → cooperation variant).

- [ ] **Step 1: Append the failing test**

Append to `backend/tests/Feature/RelationshipContentTest.php`:

```php
it('seeds crises gated on a specific named pair', function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);

    // Events whose requires reference a named-pair relationship (a + b present).
    $perPairGated = Event::all()->filter(function (Event $e) {
        $j = json_encode($e->requires);
        return str_contains($j, '"relationship"')
            && str_contains($j, '"a":') && str_contains($j, '"b":');
    });

    expect($perPairGated->count())->toBeGreaterThanOrEqual(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RelationshipContentTest`
Expected: FAIL — fewer than 2 per-pair gated events (the pair events from Task 7 that use band
gating count if they carry a/b; the two band-gated ones from Task 7 already use `{a,b,state}` in
`requires`, so this may already pass — if so, this task ADDS distinct CRISIS variants below to
make the dynamic land in general crises, not just dedicated pair cards).

NOTE: Task 7's `pair_anna_cole_cover` and `pair_bex_cole_reckless` already use per-pair
`requires`. If this test passes at Step 2, still proceed: the goal is per-pair gating in CRISIS
events (broad crises that branch on a pair), which is distinct from the dedicated pair cards.
Lower the threshold check to `>= 4` after adding the variants below so it meaningfully asserts
the new crisis variants exist.

- [ ] **Step 3: Add per-pair gated crisis variants**

In `ContentEventSeeder.php`, add to the `pairEvents()` array (or a new `pairCrisisVariants()`
method merged into `events()`) these crisis variants gated on a specific pair's band:

```php
            // A repair crisis that plays as friction when the two engineers-of-circumstance clash.
            $this->ev([
                'key' => 'crisis_repair_clash', 'title' => 'Riparazione a denti stretti', 'speaker' => null,
                'body' => "Una riparazione urgente richiede Anna e Cole insieme nello stesso condotto angusto. Con l'astio che si portano dietro, ogni gesto è una provocazione.",
                'requires' => ['all' => [
                    ['resource' => 'hull', 'op' => '<', 'value' => 55],
                    ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'hatred']],
                ]],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Imponi che collaborino', [['resource' => 'hull', 'delta' => 10], ['character' => 'all', 'stress' => 8], ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -4]]], 'Riparato. A malapena. Si odiano un po\' di più.'),
                    $this->one('Mandane uno solo, più lento', [['resource' => 'hull', 'delta' => 4], ['character' => 'Anna', 'stress' => 6]], 'Più lento, ma senza spargimento di sangue.'),
                ],
            ]),
            // The same kind of crisis, but COOPERATION when the pair is bonded.
            $this->ev([
                'key' => 'crisis_repair_sync', 'title' => 'Riparazione in sincronia', 'speaker' => 'Anna',
                'body' => "La stessa riparazione difficile, ma stavolta Anna e Cole si muovono come un solo organismo: si passano gli attrezzi senza guardarsi. Affiatati.",
                'requires' => ['all' => [
                    ['resource' => 'hull', 'op' => '<', 'value' => 55],
                    ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'state' => 'bond']],
                ]],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Lasciali fare', [['resource' => 'hull', 'delta' => 16], ['resource' => 'morale', 'delta' => 4]], 'Finito in metà tempo. È bello vederli così.'),
                ],
            ]),
```

Then update the Step 1 test threshold from `>= 2` to `>= 4` (Task 7's two band-gated pair cards
+ these two crisis variants all carry named-pair `requires`).

- [ ] **Step 4: Re-seed and run**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter RelationshipContentTest`
Expected: PASS (≥4 per-pair gated events).

- [ ] **Step 5: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures (ContentTest schema + Selector-never-stalls cover the enlarged pool).

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/RelationshipContentTest.php
git commit -m "feat: per-pair gated crisis variants (friction vs cooperation)"
```

---

## Task 9: Full suite + balance sim

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (202 prior + new Relationship* tests).

- [ ] **Step 2: Balance sim — relationships move, win-rate holds**

Run:
```bash
cd backend && php artisan sim:run --count=200 --items=welder,scanner,rifle,drone --memory --no-interaction
cd backend && php artisan sim:run --count=200 --items=medkit,comms,seedbank,rifle --memory --no-interaction
```
Expected: completes, 0 stalls, win-rate stays ~30-40% (the prior balance), run length not
collapsing. The relationship terms in expeditions are small; they should not swing the macro
balance. If win-rate moves materially out of 30-40%, note it (tune `relationships.expedition_risk`
down) — but a small shift is acceptable.

- [ ] **Step 3: Confirm relationships actually move during a run (not all neutral)**

Run this one-off check (greedy sim plays a run; then inspect the final relationships):
```bash
cd backend && php artisan tinker --execute="
\$sim = app(App\Game\Sim\Simulator::class);
\$r = \$sim->play(1, new App\Game\Sim\GreedySurvivalPolicy(), ['welder','scanner','rifle','drone']);
\$run = App\Models\Run::find(\$r->runId);
echo json_encode(\$run->relationships), PHP_EOL;
"
```
Expected: a non-empty relationships array with at least one non-zero value (the system is
exercised in a real run). If it's empty/all-zero across a few seeds, the pair events aren't
surfacing — note it (the dedicated events may need higher weight), but this is observational.

- [ ] **Step 4: Commit the verification note**

```bash
git commit --allow-empty -m "test: full suite green + balance sim sanity for crew relationships

<N> tests pass.
sim 200 welder/scanner/rifle/drone: wins <w>% / median <d>d / stalls 0
sim 200 medkit/comms/seedbank/rifle: wins <w>% / median <d>d / stalls 0
relationships exercised in-run (non-zero values observed)."
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** per-pair predicate w/ symmetry (Task 1) + schema accept (Task 2); death
  drift divergent (Tasks 3-4); expeditions (Task 5); reactions/diary visibility (Task 6); player
  choices + dedicated pair events incl. band-gated (Task 7); per-pair gated crisis variants
  (Task 8); sim + suite (Task 9). All spec sections mapped. Out-of-scope items (endings, initial
  seeds, stress drift, hunger-death drift) are explicitly excluded and the hunger-death gap is
  documented up front.
- **Placeholder scan:** Tasks 2 and 8 have a discovery/conditional step with the exact fallback
  spelled out (validator sub-key check; threshold adjust) — not placeholders. All code steps
  show full code.
- **Type/field consistency:** `samePair`/`relationshipBand` signatures match between
  ConditionEvaluator (Task 1) and EffectApplier (Tasks 3-4) and ExpeditionResolver (Task 5) —
  each class holds its own copy mirroring the same band thresholds (the codebase already
  duplicates `relationshipBand` rather than sharing; consistent with existing style).
  `config('game.relationships.death_drift'|'expedition_risk')` keys match between Task 4 config
  and consumers. `{relationship:{a,b,delta}}` (effect) and `{relationship:{a,b,state}}`
  (condition) shapes are consistent across all tasks and the seeder content.
- **Test-4-is-fragile guard:** Task 6 asserts on structured `who`/`tone`, not Italian prose, per
  the spec's explicit instruction.
- **Note carried from spec:** sim proves the system runs, not that it's fun — human playtest is
  the real validation (Task 9 step 3 only checks the system is exercised).
