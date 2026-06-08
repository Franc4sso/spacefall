# Tool-Items in Common Crises — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one item-gated tool choice to each of 7 high-weight crisis cards, so every grid item earns a role in crises (the most efficient lever against repetition).

**Architecture:** Pure seeder-data change. The engine already supports choice-level gating via `requires: {has_item}` (logic) + `requires_item` (UI hint), validated by `EventSchema` and exercised by `ItemTest`. We add choices to existing events in `EventSeeder.php` (4 cards) and `ContentEventSeeder.php` (3 cards), then add a data test plus a balance-sim sanity check. No engine, schema, or UI changes.

**Tech Stack:** Laravel, PHP, Pest (tests), Eloquent (`Event` model with JSON `choices`), `php artisan db:seed` / `sim:run` console command.

---

## Background the engineer needs

- **Events** are DB rows (`events` table). Each has a JSON `choices` array. A choice is
  `['label'=>..., 'hint'=>..., 'outcomes'=>[['weight'=>int,'effects'=>[...],'log'=>...]]]`.
- **Gating a choice on an item** = add two keys to that choice:
  - `'requires' => ['has_item' => 'X']` — the engine marks the choice `available:false`
    if the run lacks item X, and throws if it's resolved without X.
  - `'requires_item' => 'X'` — UI hint (icon). Set BOTH.
- **Effects vocabulary** (validated by `EventSchema::EFFECT_KEYS`): `resource`(+`delta`),
  `set_flag`, `spawn_event`, `character`(+`stress`/`hunger`), `relationship`, `damage_system`(+`amount`),
  `recruit`, `kill`, `grant_research_points`, `consume_item`, `grant_item`, `modify_trust`,
  `modify_standing`, `end_expedition`. Using any other key fails the seeder.
- **Two seeders**: `EventSeeder.php` writes choices as hand-written array literals.
  `ContentEventSeeder.php` uses helpers `one()` / `gamble()`:
  - `one(label, effects, log, hint=null, requires=null)` — single deterministic outcome.
    For the UI hint, wrap: `array_merge($this->one(...), ['requires_item'=>'X'])`.
  - `gamble(label, good, goodLog, bad, badLog, goodW, badW, hint=null)` — two outcomes.
    It does NOT take `requires`; wrap:
    `array_merge($this->gamble(...), ['requires'=>['has_item'=>'X'], 'requires_item'=>'X'])`.
- **Re-seeding**: `Event::updateOrCreate(['key'=>...], $event)` — running the seeder again
  overwrites by key, so edits take effect on re-seed.
- **Run tests**: `cd backend && php artisan test --filter <name>`.
- **Run balance sim**: `cd backend && php artisan sim:run --count=1000`.

## File Structure

- Modify: `backend/database/seeders/EventSeeder.php` — cards 1-4 (power_flicker, technician_panic, ration_crisis, ration_night).
- Modify: `backend/database/seeders/ContentEventSeeder.php` — cards 5-7 (trap_morale_collapse, trap_cascade_failure, food_sacrifice).
- Test: `backend/tests/Feature/ToolChoiceTest.php` — new data test asserting all 7 gated choices exist and are well-formed. (`ContentTest.php` already seeds both seeders; the new file mirrors its `beforeEach`.)

Each task = one card's choice + a test assertion for it. The data test is built incrementally (one assertion per card) so each task is independently verifiable.

---

## Task 0: Scaffold the data test file

**Files:**
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Create the test file with a shared helper and one placeholder assertion**

```php
<?php

use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/**
 * Assert that event $key has exactly one choice gated on $item, with both the
 * logic gate (requires.has_item) and the UI hint (requires_item) set.
 * Returns that choice for further effect assertions.
 */
function gatedChoice(string $key, string $item): array
{
    $event = Event::where('key', $key)->firstOrFail();
    $gated = collect($event->choices)->filter(
        fn ($c) => ($c['requires']['has_item'] ?? null) === $item
    )->values();

    expect($gated)->toHaveCount(1, "event {$key} should have exactly one choice gated on {$item}");
    expect($gated[0]['requires_item'] ?? null)->toBe($item, "event {$key} choice missing requires_item UI hint");

    return $gated[0];
}

it('seeds the 7 tool-gated crisis choices', function () {
    // Filled in per card by later tasks.
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run it to confirm the harness works**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: PASS (1 passing test).

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/ToolChoiceTest.php
git commit -m "test: scaffold tool-gated crisis choice test"
```

---

## Task 1: power_flicker — welder

**Card:** `Sbalzo di tensione` (EventSeeder.php:36). A fuse is failing. Base choices: shut down
non-essential (safe-ish), or ignore (gamble that can spawn `power_cascade`). Welder = solid fix
with a small power cost.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (power_flicker `choices`, around line 44-65)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Replace the body of `it('seeds the 7 tool-gated crisis choices', ...)` with:

```php
it('seeds the 7 tool-gated crisis choices', function () {
    $c = gatedChoice('power_flicker', 'welder');
    // Deterministic fix: single outcome, net positive power.
    expect($c['outcomes'])->toHaveCount(1);
    $deltas = collect($c['outcomes'][0]['effects'])->where('resource', 'power')->pluck('delta');
    expect($deltas->sum())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — "event power_flicker should have exactly one choice gated on welder" (count 0).

- [ ] **Step 3: Add the welder choice to power_flicker**

In `EventSeeder.php`, inside the `power_flicker` `'choices' => [ ... ]` array, add this as the
FIRST choice (before `'Spengo tutto il non essenziale'` at line 46):

```php
                    [
                        'label' => 'Salgo il bus e risaldo il fusibile',
                        'hint' => 'regge, ma scotta',
                        'requires' => ['has_item' => 'welder'],
                        'requires_item' => 'welder',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'power', 'delta' => 10]],
                                'log' => 'Saldi il contatto. Il quadro torna fermo. La punta ti resta calda in mano.'],
                        ],
                    ],
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/EventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: welder tool choice in power_flicker crisis"
```

---

## Task 2: technician_panic — scanner

**Card:** `Il tecnico è fuori di sé` (EventSeeder.php:102). Technician screams the air is
contaminated — maybe delirium, maybe not. Base choices: vent him (morale hit + flag) or listen.
Scanner = scan the air to settle it; usually calms, but sometimes proves a real leak →
spawns a follow-up. Tool is better-but-risky, not a free win.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (technician_panic `choices`, around line 110-129)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block, after Task 1's lines:

```php
    $c2 = gatedChoice('technician_panic', 'scanner');
    // Two outcomes: a good one (morale up) and a bad one that reveals a real leak.
    expect($c2['outcomes'])->toHaveCount(2);
    $spawns = collect($c2['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => array_key_exists('spawn_event', $e))
    );
    expect($spawns)->toBeTrue('scanner outcome should sometimes reveal a real leak via spawn_event');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — "exactly one choice gated on scanner" (count 0).

- [ ] **Step 3: Add the scanner choice to technician_panic**

In `EventSeeder.php`, inside `technician_panic`'s `'choices' => [ ... ]`, add as the FIRST choice
(before `'Lo chiudo nella camera stagna'` at line 111). Note `c_oxygen_leak` is an existing event
key (EventSeeder/ContentEventSeeder define oxygen events; use `c_oxygen_leak` from ContentEventSeeder):

```php
                    [
                        'label' => 'Scansiono l\'aria per dargli torto',
                        'hint' => 'la verità, comunque sia',
                        'requires' => ['has_item' => 'scanner'],
                        'requires_item' => 'scanner',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'morale', 'delta' => 6]],
                                'log' => 'Lo scanner non trova niente. Glielo mostri. Respira, finalmente.'],
                            ['weight' => 4, 'effects' => [
                                ['resource' => 'morale', 'delta' => -4],
                                ['spawn_event' => ['key' => 'c_oxygen_leak', 'in_days' => 1]],
                            ], 'log' => 'Lo scanner lampeggia rosso. Aveva ragione. C\'è una perdita.'],
                        ],
                    ],
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS. (If the seeder throws "unknown spawn key", confirm `c_oxygen_leak` exists with
`grep -n "c_oxygen_leak" backend/database/seeders/ContentEventSeeder.php`; the schema does not
validate spawn target keys, so any string passes — but use a real key for correctness.)

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/EventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: scanner tool choice in technician_panic crisis"
```

---

## Task 3: ration_crisis — rifle

**Card:** `Chi mangia stanotte` (EventSeeder.php:309). One hot portion, everyone watching.
Base choices: split equally, skip the turn (stress all), eat alone (rancor). Rifle = go hunt
for fresh meat; gamble between a real food gain and coming back hurt.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (ration_crisis `choices`, around line 317-349)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block:

```php
    $c3 = gatedChoice('ration_crisis', 'rifle');
    // Gamble: two outcomes, the good one adds food, the bad one costs stress.
    expect($c3['outcomes'])->toHaveCount(2);
    $foodGain = collect($c3['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => ($e['resource'] ?? null) === 'food' && ($e['delta'] ?? 0) > 0)
    );
    expect($foodGain)->toBeTrue('rifle outcome should be able to gain food');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — count 0 for rifle.

- [ ] **Step 3: Add the rifle choice to ration_crisis**

In `EventSeeder.php`, inside `ration_crisis`'s `'choices' => [ ... ]`, add as the FIRST choice
(before `'Dividiamo in parti uguali'` at line 318):

```php
                    [
                        'label' => 'Esco a caccia col fucile',
                        'hint' => 'rischioso, ma carne vera',
                        'requires' => ['has_item' => 'rifle'],
                        'requires_item' => 'rifle',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [
                                ['resource' => 'food', 'delta' => 18],
                                ['resource' => 'morale', 'delta' => 4],
                            ], 'log' => 'Torni con qualcosa. Stanotte si mangia caldo.'],
                            ['weight' => 4, 'effects' => [
                                ['character' => 'random', 'stress' => 14],
                                ['resource' => 'food', 'delta' => 3],
                            ], 'log' => 'Torni a mani quasi vuote, e con un graffio che brucia.'],
                        ],
                    ],
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/EventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: rifle tool choice in ration_crisis"
```

---

## Task 4: ration_night — drone

**Card:** `Razioni` (EventSeeder.php:155). Food short tonight. Base choices: eat yourself
(morale hit) or skip the meal (morale up, oxygen cost). Drone = recon for stray supplies;
gamble between a food find and losing the drone.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (ration_night `choices`, around line 163-183)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block:

```php
    $c4 = gatedChoice('ration_night', 'drone');
    // Gamble where the bad outcome consumes the drone.
    expect($c4['outcomes'])->toHaveCount(2);
    $consumes = collect($c4['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => ($e['consume_item'] ?? null) === 'drone')
    );
    expect($consumes)->toBeTrue('drone outcome should be able to consume the drone');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — count 0 for drone.

- [ ] **Step 3: Add the drone choice to ration_night**

In `EventSeeder.php`, inside `ration_night`'s `'choices' => [ ... ]`, add as the FIRST choice
(before `'Mangio io, mi serve la lucidità'` at line 164):

```php
                    [
                        'label' => 'Mando il drone a frugare',
                        'hint' => 'potrebbe non tornare',
                        'requires' => ['has_item' => 'drone'],
                        'requires_item' => 'drone',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'food', 'delta' => 14]],
                                'log' => 'Il drone torna con due casse dimenticate in un condotto.'],
                            ['weight' => 4, 'effects' => [
                                ['consume_item' => 'drone'],
                                ['resource' => 'food', 'delta' => 4],
                            ], 'log' => 'Un sibilo, poi silenzio. Il drone non torna. Solo qualche scatoletta.'],
                        ],
                    ],
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/EventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: drone tool choice in ration_night"
```

---

## Task 5: trap_morale_collapse — medkit

**Card:** `IL PUNTO DI ROTTURA` (ContentEventSeeder.php:1369). Crew at breaking point.
Base choices: burn food reserves for a real meal, or a hollow pep talk. Medkit = medical
sedation/triage; morale up, consumes the medkit. Uses the `one()` helper.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (trap_morale_collapse `choices`, around line 1373-1376)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block:

```php
    $c5 = gatedChoice('trap_morale_collapse', 'medkit');
    expect($c5['outcomes'])->toHaveCount(1);
    $effects = collect($c5['outcomes'][0]['effects']);
    expect($effects->contains(fn ($e) => ($e['consume_item'] ?? null) === 'medkit'))
        ->toBeTrue('medkit choice should consume the medkit');
    expect($effects->contains(fn ($e) => ($e['resource'] ?? null) === 'morale' && ($e['delta'] ?? 0) > 0))
        ->toBeTrue('medkit choice should raise morale');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — count 0 for medkit.

- [ ] **Step 3: Add the medkit choice to trap_morale_collapse**

In `ContentEventSeeder.php`, inside `trap_morale_collapse`'s `'choices' => [ ... ]`, add as the
FIRST entry (before the existing `$this->one('Consuma le ultime riserve...')` line):

```php
                    array_merge(
                        $this->one(
                            'Sedativi dal kit medico — un sonno vero, per una notte',
                            [['resource' => 'morale', 'delta' => 18], ['consume_item' => 'medkit']],
                            'Dormono davvero, la prima volta da settimane. Il kit ora è vuoto.'
                        ),
                        ['requires' => ['has_item' => 'medkit'], 'requires_item' => 'medkit']
                    ),
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: medkit tool choice in trap_morale_collapse"
```

---

## Task 6: trap_cascade_failure — comms

**Card:** `CASCATA DI GUASTI` (ContentEventSeeder.php:1358). Both base options damage a system
heavily (save life-support OR save propulsion). Comms = call for remote guidance to cut one
system's damage in half, at an oxygen cost (time spent on the radio). Uses `one()`.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (trap_cascade_failure `choices`, around line 1362-1365)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block:

```php
    $c6 = gatedChoice('trap_cascade_failure', 'comms');
    expect($c6['outcomes'])->toHaveCount(1);
    $effects = collect($c6['outcomes'][0]['effects']);
    // Reduced damage to one system, paid for in oxygen.
    expect($effects->contains(fn ($e) => array_key_exists('damage_system', $e)))
        ->toBeTrue('comms choice should still damage a system (reduced, not zero)');
    expect($effects->contains(fn ($e) => ($e['resource'] ?? null) === 'oxygen' && ($e['delta'] ?? 0) < 0))
        ->toBeTrue('comms choice should cost oxygen');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — count 0 for comms.

- [ ] **Step 3: Add the comms choice to trap_cascade_failure**

In `ContentEventSeeder.php`, inside `trap_cascade_failure`'s `'choices' => [ ... ]`, add as the
FIRST entry (before the existing `$this->one('Salva il sistema vita'...)` line). The base options
deal 40 / 35 damage; comms cuts power_grid damage to 20 but burns oxygen:

```php
                    array_merge(
                        $this->one(
                            'Chiamo guida remota via radio',
                            [['damage_system' => 'power_grid', 'amount' => 20], ['resource' => 'oxygen', 'delta' => -10]],
                            'Una voce gracchiante ti guida nodo per nodo. Salvi metà rete. L\'aria si fa rada.'
                        ),
                        ['requires' => ['has_item' => 'comms'], 'requires_item' => 'comms']
                    ),
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS. (`power_grid` is the same system key the existing base option uses, so it is
already a valid `damage_system` target.)

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: comms tool choice in trap_cascade_failure"
```

---

## Task 7: food_sacrifice — seedbank

**Card:** `Non c'è abbastanza per tutti` (ContentEventSeeder.php:1119). Highest-stakes food card
(`cooldown_days: 999`, one-shot). Base options: sacrifice the hungriest, or gamble that everyone
holds on. Seedbank = an emergency sprout: a THIRD path that avoids the kill but is a gamble
(slow crop may yield little). Uses `gamble()` wrapped for gating.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (food_sacrifice `choices`, around line 1126-1132)
- Test: `backend/tests/Feature/ToolChoiceTest.php`

- [ ] **Step 1: Add the test assertion**

Append inside the same `it(...)` block, then close it:

```php
    $c7 = gatedChoice('food_sacrifice', 'seedbank');
    // A gamble that AVOIDS the kill: no outcome may contain a 'kill' effect.
    expect($c7['outcomes'])->toHaveCount(2);
    $hasKill = collect($c7['outcomes'])->contains(
        fn ($o) => collect($o['effects'])->contains(fn ($e) => array_key_exists('kill', $e))
    );
    expect($hasKill)->toBeFalse('seedbank path must avoid any kill effect');
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `cd backend && php artisan test --filter ToolChoiceTest`
Expected: FAIL — count 0 for seedbank.

- [ ] **Step 3: Add the seedbank choice to food_sacrifice**

In `ContentEventSeeder.php`, inside `food_sacrifice`'s `'choices' => [ ... ]`, add as the FIRST
entry (before the existing `array_merge($this->one('Uno perché gli altri vivano'...))`):

```php
                    array_merge(
                        $this->gamble(
                            'Germoglio d\'emergenza dalla banca semi',
                            [['resource' => 'food', 'delta' => 16], ['character' => 'all', 'hunger' => -15]],
                            'I germogli spuntano in fretta sotto le lampade. Pochi, ma bastano per stanotte.',
                            [['resource' => 'food', 'delta' => 4], ['character' => 'all', 'stress' => 8]],
                            'Crescono troppo lenti. Qualche foglia amara, e fame ancora.',
                            5, 5, 'lento, incerto'
                        ),
                        ['requires' => ['has_item' => 'seedbank'], 'requires_item' => 'seedbank']
                    ),
```

- [ ] **Step 4: Re-seed and run test, verify it PASSES**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ToolChoiceTest`
Expected: PASS (all 7 assertions green).

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ToolChoiceTest.php
git commit -m "feat: seedbank tool choice in food_sacrifice"
```

---

## Task 8: Full suite + balance-sim sanity check

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (the prior 183 + the new ToolChoiceTest). In particular `ContentTest`
(schema validation over `Event::all()`) must stay green — it proves every new choice's effects
are valid DSL.

- [ ] **Step 2: TypeScript / frontend check (if the repo gates on it)**

Run: `cd frontend && npm run typecheck 2>/dev/null || echo "no frontend typecheck target"`
Expected: PASS or "no target". (No frontend changes were made; this only confirms nothing broke.)

- [ ] **Step 3: Run the balance simulator with the affected items**

Run:
```bash
cd backend && php artisan sim:run --count=1000 --items=welder,scanner,rifle,drone
cd backend && php artisan sim:run --count=1000 --items=medkit,comms,seedbank,rifle
```
Expected: completes without crash; win/loss/stall distribution in a sane range (no run length
collapse to ~0, no 100% wins). Record the numbers in the commit message. The tool choices give
the player more agency, so a modest win-rate uptick is expected and desirable — flag only a
drastic swing (e.g. wins jumping past ~80%) for tuning.

- [ ] **Step 4: Commit the verification note**

```bash
git commit --allow-empty -m "test: full suite green + balance sim sanity for tool-item crises

sim 1000 runs welder/scanner/rifle/drone: <wins>/<losses>/<stalls>
sim 1000 runs medkit/comms/seedbank/rifle: <wins>/<losses>/<stalls>"
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** all 7 cards mapped 1:1 to tasks 1-7; data test (Task 0 + per-card
  assertions) and balance sim (Task 8) cover the spec's testing section. `trap_hull_critical`
  correctly excluded. No `rations` used. Grid-only items used (welder, scanner, rifle, drone,
  medkit, comms, seedbank) — each appears exactly once.
- **Placeholder scan:** no TBD/TODO; every code step shows full code; sim numbers are the only
  fill-ins and are explicitly to be recorded at run time.
- **Type/field consistency:** all choices use `requires`/`requires_item`/`outcomes`/`effects`
  matching `EventSchema`; `gatedChoice()` helper signature is stable across all assertions;
  `spawn_event`/`consume_item`/`damage_system`/`character`/`resource` keys all match the
  validated vocabulary.
