# Epilogues, Visible Deaths & Felt Choices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make choices feel real and the ending tell the player's story — capture who/when/how each crew member died, announce deaths the moment they happen, compose a sectioned epilogue from the run's facts, gate wins on real actions, fix the items-persist bug, and surface effect deltas in the UI.

**Architecture:** Two parts. PART A (story): a `death_log` on the run captured at the kill sites, an immediate "In memoria" card, a pure `EpilogueComposer` that builds sections from death_log + witness flags + survivors, and action-gated win conditions (with `lone_survivor` as guaranteed fallback). PART B (felt choices): fix `RunState::applyTo` to persist items, record per-choice effect deltas in the choice log, and surface deltas + epilogue in the frontend. All backend logic is config/data-driven and unit-testable; the frontend is one focused block.

**Tech Stack:** Laravel, PHP, Pest (backend). React + Vite + Vitest + TypeScript (frontend). Sim via `sim:run --memory`.

---

## Background the engineer needs

- **Deaths happen at two code sites, both ending up as `alive=false`:**
  - `EffectApplier::applyKill(string $selector, RunState, SeededRng)` — fires for any `kill`
    effect in an event outcome. **Expedition loss is also this** (the `lost`-tier return event
    uses `['kill' => 'expeditioner']`, see ContentEventSeeder ~1425). So an event-context death
    and an expedition death both flow through `applyKill`.
  - `DayProcessor::applyHunger` — starvation sets `alive=false` and `flags['died_of_hunger']`.
- **`EffectApplier::apply(array $effects, RunState, SeededRng)`** is the entry point; it does NOT
  currently know the event key or day. We thread an optional context.
- **`EventEngine::resolveChoice`** applies effects, builds the `choiceLog` entry (one spot,
  fields `day/event_key/choice_index/choice_label/tags/reaction_summary`), persists via
  `RunState::applyTo`, then calls `EndingService::check`. It already returns
  `['log', 'effects', 'ending', 'reactions']`.
- **`RunState::applyTo(Run)`** writes day/resources/flags/recent_events/scheduled_events/
  characters/relationships/systems/choice_log/phase_floor back — **but NOT `items`** (the bug).
- **`EndingService::check`** iterates `config('game.endings')` in order, first `when` match wins,
  stores `ending_key`/`ending_type`. Wins are gated on items/day/resource/flags. `lone_survivor`
  (oxygen>0 + day>25) is the catch-all.
- **`RunController::present`** returns the run state incl. `choice_log` (last 15), `epithet`, and
  `ending` (via `endingPayload`, which reads `config` by `ending_key` and returns
  key/type/name/text/epithet). The epilogue will be added here.
- **Frontend:** `frontend/src/api.ts` has types `Ending` (key/type/name/text/epithet),
  `Resolution` (`{log, effects: unknown[], ending, reactions}`), `ChoiceLogEntry`, `RunState`.
  `useRun.ts::choose` keeps only `log`+`reactions` (discards `effects`). `GameOverScreen.tsx`
  shows `ending.name`/`ending.text`. `Diario.tsx` shows day/choice_label/reaction_summary.
  Tests: `npm run test` (vitest) in `frontend/`.
- **Backend tests:** `php artisan test [--filter X]`. Re-seed: `php artisan migrate:fresh --seed --quiet`.
  Sim: `php artisan sim:run --count=200 --items=<csv> --memory --no-interaction`.

## File Structure

PART B1 (bug) first — it's a correctness fix everything else benefits from.

- Modify: `backend/app/Game/Engine/RunState.php` — persist `items` (B1); carry `deathLog`.
- Create: `backend/database/migrations/2026_06_09_000000_add_death_log_to_runs.php` — `death_log` column.
- Modify: `backend/app/Models/Run.php` — `death_log` fillable/cast.
- Modify: `backend/app/Game/Engine/EffectApplier.php` — record deaths into a death-log on the state, with context (A1).
- Modify: `backend/app/Game/DayProcessor.php` — record hunger deaths into the death-log (A1).
- Modify: `backend/app/Game/Engine/EventEngine.php` — pass event-context to apply; schedule `death_notice`; add `effects_summary` to choice log (A2, B3).
- Create: `backend/app/Game/Engine/EffectSummarizer.php` — pure: effects[] → readable delta summary (B2/B3 shared).
- Create: `backend/database/seeders/EndingContentSeeder.php` is NOT needed — `death_notice` event goes in ContentEventSeeder.
- Modify: `backend/database/seeders/ContentEventSeeder.php` — `death_notice` card (A2).
- Create: `backend/app/Game/Engine/EpilogueComposer.php` — pure: state+death_log+flags → sections (A3).
- Modify: `backend/config/game.php` — epilogue fragment text; action-gated win `when`s (A3/A4).
- Modify: `backend/app/Http/Controllers/RunController.php` — surface `epilogue` in ending payload (A3).
- Modify: `backend/database/seeders/ContentEventSeeder.php` / item events — set win-action flags (A4) where missing.
- Modify: `frontend/src/api.ts`, `useRun.ts`, `GameOverScreen.tsx`, `Diario.tsx`, + a small `effectFormat.ts` — surface deltas + epilogue (B2).
- Tests: `backend/tests/Feature/DeathLogTest.php`, `EpilogueTest.php`, `ChoiceLinkedEndingTest.php`, `ItemsPersistTest.php`; `frontend/src/effectFormat.test.ts`.

---

## Task 1 (B1): Fix items-persist bug

**Files:**
- Modify: `backend/app/Game/Engine/RunState.php`
- Test: `backend/tests/Feature/ItemsPersistTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/ItemsPersistTest.php`:

```php
<?php

use App\Game\Engine\RunState;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('persists items mutated on the state back to the run', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    $state = RunState::fromRun($run);
    $state->items[] = 'scanner';      // simulate a grant_item effect
    $state->applyTo($run);
    $run->save();

    expect($run->fresh()->items)->toContain('scanner');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter ItemsPersistTest`
Expected: FAIL — `scanner` not persisted (applyTo drops items).

- [ ] **Step 3: Persist items in applyTo**

In `backend/app/Game/Engine/RunState.php`, in `applyTo()`, add this line alongside the other
writes (e.g. after `$run->systems = $this->systems;`):

```php
        $run->items = $this->items;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter ItemsPersistTest`
Expected: PASS.

- [ ] **Step 5: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures (persisting items is additive; nothing relied on dropping them).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/RunState.php backend/tests/Feature/ItemsPersistTest.php
git commit -m "fix: persist items mutated mid-run (grant_item/consume_item were dropped on save)"
```

---

## Task 2 (A1): death_log column + RunState wiring

**Files:**
- Create: `backend/database/migrations/2026_06_09_000000_add_death_log_to_runs.php`
- Modify: `backend/app/Models/Run.php`
- Modify: `backend/app/Game/Engine/RunState.php`
- Test: `backend/tests/Feature/DeathLogTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/DeathLogTest.php`:

```php
<?php

use App\Game\Engine\RunState;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('defaults death_log to empty and round-trips through RunState', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    expect($run->death_log)->toBe([]);

    $state = RunState::fromRun($run);
    expect($state->deathLog)->toBe([]);

    $state->deathLog[] = ['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck'];
    $state->applyTo($run);
    $run->save();

    expect($run->fresh()->death_log)->toHaveCount(1);
    expect($run->fresh()->death_log[0]['name'])->toBe('Cole');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter DeathLogTest`
Expected: FAIL — `death_log` column / `deathLog` property missing.

- [ ] **Step 3: Migration**

Create `backend/database/migrations/2026_06_09_000000_add_death_log_to_runs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('death_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('death_log');
        });
    }
};
```

- [ ] **Step 4: Run model**

In `backend/app/Models/Run.php`: add `'death_log'` to `$fillable`; add `'death_log' => 'array'`
to the `$casts` (or `casts()` method — match the existing style used for `characters`/`flags`);
add `'death_log' => '[]'` to `$attributes` if the model defines defaults there (match how
`flags`/`items` default), so a fresh run reads `[]` not null.

- [ ] **Step 5: Wire RunState**

In `backend/app/Game/Engine/RunState.php`:
(a) add a constructor field at the END: `public array $deathLog = [],`
(b) in `fromRun`, pass `deathLog: $run->death_log ?? [],`
(c) in `applyTo`, add `$run->death_log = $this->deathLog;`

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter DeathLogTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_06_09_000000_add_death_log_to_runs.php backend/app/Models/Run.php backend/app/Game/Engine/RunState.php backend/tests/Feature/DeathLogTest.php
git commit -m "feat: death_log column + RunState wiring"
```

---

## Task 3 (A1): Capture deaths in EffectApplier (event + expedition) with context

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Test: `backend/tests/Feature/DeathLogTest.php` (append)

`apply()` gains an optional context so a kill can record where it happened. Default empty keeps
all existing callers working.

- [ ] **Step 1: Append the failing test**

Append to `backend/tests/Feature/DeathLogTest.php`:

```php
it('records an event kill into the death log with context', function () {
    $applier = app(\App\Game\Engine\EffectApplier::class);
    $state = new RunState(
        day: 12,
        resources: ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80],
        characters: [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0],
        ],
    );

    $applier->apply([['kill' => 'Anna']], $state, new \App\Game\SeededRng(1), ['event_key' => 'hull_breach', 'day' => 12]);

    expect($state->deathLog)->toHaveCount(1);
    expect($state->deathLog[0])->toMatchArray(['name' => 'Anna', 'day' => 12, 'cause' => 'event', 'context' => 'hull_breach']);
});
```

NOTE: confirm the `app(EffectApplier::class)` resolves (it has a constructor needing
`resourceMeta` — the container binds it; existing tests use `app(EffectApplier::class)`, so this
works).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter DeathLogTest`
Expected: FAIL — `apply()` has no 4th arg / no death recorded.

- [ ] **Step 3: Thread context through apply + record in applyKill**

In `backend/app/Game/Engine/EffectApplier.php`:

(a) Change `apply` signature + pass context down:

```php
    /**
     * @param  list<array<string,mixed>>  $effects
     * @param  array<string,mixed>  $context  optional {event_key, day} for death attribution
     */
    public function apply(array $effects, RunState $state, SeededRng $rng, array $context = []): void
    {
        foreach ($effects as $effect) {
            $this->applyOne($effect, $state, $rng, $context);
        }
    }
```

(b) Change `applyOne` signature to accept `array $context = []` and pass it to the kill branch.
Find `private function applyOne(array $effect, RunState $state, SeededRng $rng)` and add the
param: `private function applyOne(array $effect, RunState $state, SeededRng $rng, array $context = [])`.
In its `kill` branch change the call to `$this->applyKill($effect['kill'], $state, $rng, $context);`.

(c) Update `applyKill` to record the death. Replace the method with:

```php
    private function applyKill(string $selector, RunState $state, SeededRng $rng, array $context = []): void
    {
        $index = $this->resolveTarget($selector, $state, $rng);
        if ($index === null) {
            return;
        }

        $deadName = $state->characters[$index]['name'] ?? null;
        $state->characters[$index]['alive'] = false;

        if ($deadName !== null) {
            $state->deathLog[] = [
                'name' => $deadName,
                'day' => $state->day,
                'cause' => $context['cause'] ?? 'event',
                'context' => $context['event_key'] ?? '',
            ];
            $this->applyDeathDrift($deadName, $state);
        }
    }
```

(`day` comes from `$state->day`; `cause` defaults to `event`, overridable via context for
expedition/hunger callers; `context` carries the event key.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter DeathLogTest`
Expected: PASS.

- [ ] **Step 5: Full suite (apply() signature change must not break callers)**

Run: `cd backend && php artisan test`
Expected: 0 failures. Existing `apply(...)` calls omit the 4th arg → default `[]` → unchanged
behaviour, only now deaths populate the log (with empty context where not passed). If any test
asserted death_log stays empty after a kill, update it — that's the intended new behaviour.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php backend/tests/Feature/DeathLogTest.php
git commit -m "feat: record event/expedition deaths into death_log with context"
```

---

## Task 4 (A1): Pass event context from resolveChoice + capture hunger deaths

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Modify: `backend/app/Game/DayProcessor.php`
- Test: `backend/tests/Feature/DeathLogTest.php` (append)

- [ ] **Step 1: Append the failing tests**

Append to `backend/tests/Feature/DeathLogTest.php`:

```php
it('attributes a death to the event the player resolved', function () {
    // food_sacrifice can kill the hungriest; drive the run into it deterministically.
    $run = app(RunFactory::class)->create(7, ['welder']);
    // Set up the sacrifice precondition: low food + high crew hunger.
    $run->resources = ['oxygen' => 80, 'food' => 4, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['hunger'] = 80; }
    $run->characters = $chars;
    $run->current_event_key = 'food_sacrifice';
    $run->save();

    $engine = app(\App\Game\Engine\EventEngine::class);
    $card = $engine->currentCard($run->fresh());
    // Choice 0 of food_sacrifice kills the hungriest.
    $engine->resolveChoice($run->fresh(), 0);

    $after = $run->fresh();
    expect($after->death_log)->not->toBeEmpty();
    expect($after->death_log[0]['context'])->toBe('food_sacrifice');
    expect($after->death_log[0]['cause'])->toBe('event');
});

it('records a starvation death with cause starvation', function () {
    $run = app(RunFactory::class)->create(3, ['welder']);
    // One crew member one tick from starving.
    $chars = $run->characters;
    $chars[0]['hunger'] = 99; // daily_rise pushes >= starve_at (100)
    $run->characters = $chars;
    $run->day = 5;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    $after = $run->fresh();
    $starved = collect($after->death_log)->firstWhere('cause', 'starvation');
    expect($starved)->not->toBeNull();
    expect($starved['name'])->toBe($chars[0]['name']);
});
```

NOTE: the food_sacrifice test assumes choice index 0 is the kill option and the event is
reachable when forced via `current_event_key`. Confirm by reading the `food_sacrifice` event in
ContentEventSeeder; if the kill is a different index, adjust the index in the test (keep the
assertion). If forcing the event via `current_event_key` doesn't present it (gating), instead
drive a simpler kill event, or unit-test `applyKill` context via EventEngine with a minimal
seeded event — keep the assertion that context == the event key.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter DeathLogTest`
Expected: FAIL — context is empty (resolveChoice doesn't pass event_key) and hunger death not
recorded.

- [ ] **Step 3: Pass event context in resolveChoice**

In `backend/app/Game/Engine/EventEngine.php`, the line:

```php
        $this->applier->apply($outcome['effects'] ?? [], $state, $rng);
```

becomes:

```php
        $this->applier->apply($outcome['effects'] ?? [], $state, $rng, [
            'event_key' => $event->key,
            'day' => $state->day,
            'cause' => 'event',
        ]);
```

- [ ] **Step 4: Capture hunger deaths in DayProcessor**

In `backend/app/Game/DayProcessor.php`, find `applyHunger` where a starving member is killed
(sets `$characters[$i]['alive'] = false;` and `$someoneStarved = true;`). The method works on a
raw `$characters` array and returns a starved flag, NOT a RunState — so collect the starved
NAMES and write them to the death log in `advance()` where `$run` is available.

(a) In `applyHunger`, build a list of starved entries. Change the starvation branch to also
record the name+day, and return it. Where it currently does:

```php
            if ($hunger >= $starveAt) {
                $characters[$i]['alive'] = false;
                $someoneStarved = true;
                continue;
            }
```

change to capture the name (add a `$starvedNames = [];` at the method top, near `$someoneStarved = false;`):

```php
            if ($hunger >= $starveAt) {
                $characters[$i]['alive'] = false;
                $someoneStarved = true;
                $starvedNames[] = $characters[$i]['name'] ?? '?';
                continue;
            }
```

and change the method's return to include the names. Find the return
`return [$characters, $scheduled, $someoneStarved];` and change to
`return [$characters, $scheduled, $someoneStarved, $starvedNames];`. Update the call site in
`advance()` to destructure the 4th value:
`[$characters, $scheduled, $hungerDeath, $starvedNames] = $this->applyHunger($characters, $scheduled, $run->day);`

(b) In `advance()`, after the write-back block where `$run->flags['died_of_hunger']` is set,
append the starvation deaths to the run's death log:

```php
        if (! empty($starvedNames)) {
            $log = $run->death_log ?? [];
            foreach ($starvedNames as $name) {
                $log[] = ['name' => $name, 'day' => $run->day, 'cause' => 'starvation', 'context' => 'hunger'];
            }
            $run->death_log = $log;
        }
```

(Place this before `$run->save();` in advance so it persists. `$run->day` at this point is the
day the death occurred — acceptable; if the day was already incremented, use `$run->day` anyway,
it's within ±1 and consistent.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter DeathLogTest`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php backend/app/Game/DayProcessor.php backend/tests/Feature/DeathLogTest.php
git commit -m "feat: attribute event deaths to their event + record starvation deaths"
```

---

## Task 5 (A2): "In memoria" death-notice card + scheduling

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Modify: `backend/app/Game/DayProcessor.php`
- Test: `backend/tests/Feature/DeathLogTest.php` (append)

When a death is recorded AND the run is still active, schedule a single-choice `death_notice`
card for the current day so the player sees the loss before continuing.

- [ ] **Step 1: Add the death_notice event**

In `backend/database/seeders/ContentEventSeeder.php`, add to `silentEvents()` (single-choice
narrative card). Its body is generic; the specific name/day is surfaced by the epilogue/diary —
keep the card a quiet beat:

```php
            $this->ev([
                'key' => 'death_notice',
                'title' => 'In memoria',
                'speaker' => null,
                'body' => "Un nome in meno all'appello. La stazione sembra più grande, e più vuota. Ti fermi un momento. Poi si va avanti — non c'è altro da fare.",
                // Scheduled-only: fired explicitly when a death is logged, never drawn at random.
                'requires' => ['flag' => '__never', 'is' => true],
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Un momento di silenzio', [['resource' => 'morale', 'delta' => -3]], 'Poi torni al lavoro.'),
                ],
            ]),
```

(The `__never` flag is never set, so it only appears via forced scheduling — same trick as
`power_cascade`'s `__scheduled_only`. Confirm the Selector force-picks scheduled events by key
without checking `requires`; it does, per existing scheduled events.)

- [ ] **Step 2: Schedule death_notice when a death is logged (still-active runs only)**

In `backend/app/Game/Engine/EventEngine.php` resolveChoice, AFTER `$this->applier->apply(...)`
(which may have appended to `$state->deathLog`) and BEFORE the choiceLog append, detect new
deaths and schedule the notice. Capture the death-log length before apply and compare:

Right before the `apply(...)` call add:
```php
        $deathsBefore = count($state->deathLog);
```
After the `apply(...)` call add:
```php
        if (count($state->deathLog) > $deathsBefore) {
            // Someone just died from this choice. Surface it as a beat next —
            // unless this choice also ends the run (the epilogue covers that).
            $state->scheduledEvents[] = ['key' => 'death_notice', 'fire_on_day' => $state->day];
        }
```

Then, because the ending check happens at the end of resolveChoice, prevent a dangling notice
when the run ended: after `$ending = $this->endings->check($run);`, if `$ending !== null` strip
any scheduled `death_notice` (it won't be shown anyway since the run is over — harmless, but
keep state clean):
```php
        if ($ending !== null) {
            $run->scheduled_events = array_values(array_filter(
                $run->scheduled_events ?? [],
                fn ($s) => ($s['key'] ?? null) !== 'death_notice',
            ));
            $run->save();
        }
```

- [ ] **Step 3: Schedule death_notice for starvation deaths in DayProcessor**

In `backend/app/Game/DayProcessor.php` advance(), in the `if (! empty($starvedNames))` block
from Task 4, also schedule the notice (only matters if the run survives the day — if starvation
ends the run, EndingService marks it ended and the card never shows):

```php
            $scheduled[] = ['key' => 'death_notice', 'fire_on_day' => $run->day];
```

(add inside that block, and ensure `$run->scheduled_events = $scheduled;` is written — it is, in
the existing write-back. If the write-back already happened above this block, set
`$run->scheduled_events = array_merge($run->scheduled_events ?? [], [['key'=>'death_notice','fire_on_day'=>$run->day]]);` instead. Read the method and pick the spot that persists.)

- [ ] **Step 4: Append the test**

Append to `backend/tests/Feature/DeathLogTest.php`:

```php
it('schedules a death_notice when a death occurs mid-run', function () {
    $run = app(RunFactory::class)->create(7, ['welder']);
    $run->resources = ['oxygen' => 80, 'food' => 4, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['hunger'] = 80; }
    $run->characters = $chars;
    $run->current_event_key = 'food_sacrifice';
    $run->save();

    $engine = app(\App\Game\Engine\EventEngine::class);
    $engine->currentCard($run->fresh());
    $engine->resolveChoice($run->fresh(), 0); // the kill choice

    $after = $run->fresh();
    if ($after->status === 'active') {
        $keys = collect($after->scheduled_events ?? [])->pluck('key');
        expect($keys)->toContain('death_notice');
    } else {
        // Death ended the run — epilogue covers it; no dangling notice.
        $keys = collect($after->scheduled_events ?? [])->pluck('key');
        expect($keys)->not->toContain('death_notice');
    }
});
```

- [ ] **Step 5: Re-seed, run filter, full suite**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter "DeathLogTest|ContentTest"`
Expected: PASS (death_notice schema-valid; scheduling works).
Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/app/Game/Engine/EventEngine.php backend/app/Game/DayProcessor.php backend/tests/Feature/DeathLogTest.php
git commit -m "feat: In memoria death-notice card scheduled the moment a crew member dies"
```

---

## Task 6 (B3): Per-choice effect summary in the choice log

**Files:**
- Create: `backend/app/Game/Engine/EffectSummarizer.php`
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Test: `backend/tests/Unit/EffectSummarizerTest.php`

A pure summarizer turns an effects list into a compact, readable structure used both by the
choice log (B3) and the frontend delta display (B2).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/EffectSummarizerTest.php`:

```php
<?php

use App\Game\Engine\EffectSummarizer;

it('summarizes resource deltas and notable events', function () {
    $s = new EffectSummarizer();
    $out = $s->summarize([
        ['resource' => 'oxygen', 'delta' => -12],
        ['resource' => 'morale', 'delta' => 8],
        ['character' => 'Anna', 'stress' => 10],
        ['kill' => 'Cole'],
        ['damage_system' => 'power_grid', 'amount' => 8],
        ['consume_item' => 'medkit'],
    ]);

    // Resource deltas keyed by code.
    expect($out['resources'])->toBe(['oxygen' => -12, 'morale' => 8]);
    // Notable markers present.
    expect($out['notes'])->toContain('Anna: stress +10');
    expect($out['notes'])->toContain('morte');
    expect($out['notes'])->toContain('power_grid danneggiato');
    expect($out['notes'])->toContain('medkit consumato');
});

it('returns empty summary for no effects', function () {
    $s = new EffectSummarizer();
    expect($s->summarize([]))->toBe(['resources' => [], 'notes' => []]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter EffectSummarizerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement EffectSummarizer**

Create `backend/app/Game/Engine/EffectSummarizer.php`:

```php
<?php

namespace App\Game\Engine;

/**
 * Turns a raw effects list into a compact, readable summary: net resource deltas
 * plus short Italian notes for notable non-resource effects (a death, a damaged
 * system, a consumed item, crew stress). Pure. Used by the choice log (so the
 * timeline records what each choice DID) and by the UI delta display.
 */
final class EffectSummarizer
{
    /**
     * @param  list<array<string,mixed>>  $effects
     * @return array{resources: array<string,int>, notes: list<string>}
     */
    public function summarize(array $effects): array
    {
        $resources = [];
        $notes = [];

        foreach ($effects as $e) {
            if (! is_array($e)) {
                continue;
            }
            if (array_key_exists('resource', $e)) {
                $code = $e['resource'];
                $resources[$code] = ($resources[$code] ?? 0) + (int) ($e['delta'] ?? 0);
            } elseif (array_key_exists('character', $e)) {
                $who = $e['character'];
                if (($e['stress'] ?? 0) != 0) {
                    $notes[] = "{$who}: stress " . $this->signed((int) $e['stress']);
                }
                if (($e['hunger'] ?? 0) != 0) {
                    $notes[] = "{$who}: fame " . $this->signed((int) $e['hunger']);
                }
            } elseif (array_key_exists('kill', $e)) {
                $notes[] = 'morte';
            } elseif (array_key_exists('damage_system', $e)) {
                $notes[] = $e['damage_system'] . ' danneggiato';
            } elseif (array_key_exists('consume_item', $e)) {
                $notes[] = $e['consume_item'] . ' consumato';
            } elseif (array_key_exists('grant_item', $e)) {
                $notes[] = $e['grant_item'] . ' ottenuto';
            } elseif (array_key_exists('relationship', $e)) {
                $notes[] = 'rapporto cambiato';
            }
        }

        // Drop zero-net resource entries (a +5/-5 cancels to nothing notable).
        $resources = array_filter($resources, fn ($v) => $v !== 0);

        return ['resources' => $resources, 'notes' => array_values($notes)];
    }

    private function signed(int $n): string
    {
        return ($n > 0 ? '+' : '') . $n;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter EffectSummarizerTest`
Expected: PASS.

- [ ] **Step 5: Add effects_summary to the choice log entry**

In `backend/app/Game/Engine/EventEngine.php`, the class constructor injects services. Add an
`EffectSummarizer` dependency (constructor param `private readonly EffectSummarizer $summarizer,`
— the container auto-resolves it, zero-arg constructor). Then in resolveChoice, where the
choiceLog `$entry` is built, add the summary field:

```php
            'effects_summary' => $this->summarizer->summarize($outcome['effects'] ?? []),
```

- [ ] **Step 6: Run the full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures (added field is additive; choice_log is JSON, no migration needed).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EffectSummarizer.php backend/app/Game/Engine/EventEngine.php backend/tests/Unit/EffectSummarizerTest.php
git commit -m "feat: EffectSummarizer + per-choice effects_summary in the choice log"
```

---

## Task 7 (A3): EpilogueComposer

**Files:**
- Create: `backend/app/Game/Engine/EpilogueComposer.php`
- Modify: `backend/config/game.php` (epilogue fragments)
- Test: `backend/tests/Unit/EpilogueComposerTest.php`

Pure composer: builds ordered sections from the ending, death_log, witness flags, survivors,
epithet. Each section is `{title, lines}` with terse Italian lines.

- [ ] **Step 1: Add epilogue fragment config**

In `backend/config/game.php`, add a top-level `epilogue` block (after the `endings` block):

```php
    /*
     | Epilogue fragments. The EpilogueComposer reads these to build the
     | sectioned end-of-run report. Witness flags map to a one-line consequence;
     | death causes map to a phrasing; survivor bands map to a closing line.
     */
    'epilogue' => [
        'cause_phrases' => [
            'event' => 'caduto',
            'expedition' => 'perso in spedizione',
            'starvation' => 'morto di fame',
            'morale' => 'spezzato',
        ],
        'witness_flags' => [
            'cannibalism' => 'Avete mangiato uno dei vostri. Nessuno ne parla.',
            'ate_alone' => 'Hai mangiato voltando le spalle agli altri.',
            'made_the_sacrifice' => 'Sei rimasto indietro perché gli altri vivessero.',
            'sos_sent' => 'Hai gridato nel buio. Qualcuno, forse, ha sentito.',
            'mutiny_occurred' => "L'equipaggio ti ha tolto il comando.",
            'log_falsified' => 'Hai riscritto la verità nei registri.',
            'vented_the_technician' => 'Hai chiuso un uomo nella camera stagna.',
            'lost_on_expedition' => 'Hai sigillato il portello su chi non era ancora rientrato.',
        ],
    ],
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Unit/EpilogueComposerTest.php`:

```php
<?php

use App\Game\Engine\EpilogueComposer;
use App\Game\Engine\RunState;

function endedState(array $overrides = []): RunState
{
    return new RunState(
        day: $overrides['day'] ?? 26,
        resources: $overrides['resources'] ?? ['oxygen' => 30, 'food' => 30, 'power' => 30, 'morale' => 30, 'hull' => 30],
        flags: $overrides['flags'] ?? [],
        characters: $overrides['characters'] ?? [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 70, 'hunger' => 0, 'away_until' => 0],
        ],
        deathLog: $overrides['deathLog'] ?? [],
    );
}

it('builds a fallen section from the death log', function () {
    $c = new EpilogueComposer();
    $state = endedState(['deathLog' => [
        ['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck'],
    ]]);

    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'ULTIMO IN PIEDI', 'text' => '...']);
    $fallen = collect($sections)->firstWhere('title', 'Caduti');
    expect($fallen)->not->toBeNull();
    expect(implode(' ', $fallen['lines']))->toContain('Cole');
    expect(implode(' ', $fallen['lines']))->toContain('14');
    expect(implode(' ', $fallen['lines']))->toContain('spedizione');
});

it('includes a key-choices section from witness flags', function () {
    $c = new EpilogueComposer();
    $state = endedState(['flags' => ['cannibalism' => true]]);
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $choices = collect($sections)->firstWhere('title', 'Le tue scelte');
    expect($choices)->not->toBeNull();
    expect(implode(' ', $choices['lines']))->toContain('mangiato');
});

it('reports survivors with a colored line', function () {
    $c = new EpilogueComposer();
    $state = endedState(); // Anna alive, stress 70
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $surv = collect($sections)->firstWhere('title', 'I superstiti');
    expect($surv)->not->toBeNull();
    expect(implode(' ', $surv['lines']))->toContain('Anna');
});

it('always opens with the outcome section using the ending text', function () {
    $c = new EpilogueComposer();
    $sections = $c->compose(endedState(), ['key' => 'lone_survivor', 'name' => 'ULTIMO IN PIEDI', 'text' => 'Hai salvato la stazione.']);
    expect($sections[0]['title'])->toBe('Esito');
    expect(implode(' ', $sections[0]['lines']))->toContain('Hai salvato la stazione.');
});

it('omits empty sections (no deaths => no Caduti)', function () {
    $c = new EpilogueComposer();
    $sections = $c->compose(endedState(['deathLog' => []]), ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    expect(collect($sections)->firstWhere('title', 'Caduti'))->toBeNull();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test --filter EpilogueComposerTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement EpilogueComposer**

Create `backend/app/Game/Engine/EpilogueComposer.php`:

```php
<?php

namespace App\Game\Engine;

/**
 * Builds the sectioned end-of-run epilogue from the run's facts: the outcome,
 * who fell (death_log), the key choices that defined the run (witness flags),
 * what became of the survivors, and the earned epithet. Pure and config-driven;
 * each section is terse. Empty sections are omitted.
 *
 * @phpstan-type Section array{title: string, lines: list<string>}
 */
final class EpilogueComposer
{
    /**
     * @param  array<string,mixed>  $ending  the matched ending config (key/name/text)
     * @return list<array{title: string, lines: list<string>}>
     */
    public function compose(RunState $state, array $ending): array
    {
        $sections = [];

        // 1. Esito — the base ending text.
        $sections[] = ['title' => 'Esito', 'lines' => [(string) ($ending['text'] ?? '')]];

        // 2. Caduti — one line per death.
        $causes = config('game.epilogue.cause_phrases', []);
        $fallen = [];
        foreach ($state->deathLog as $d) {
            $name = $d['name'] ?? '?';
            $day = $d['day'] ?? '?';
            $phrase = $causes[$d['cause'] ?? 'event'] ?? 'caduto';
            $fallen[] = "{$name}, giorno {$day}. " . ucfirst($phrase) . '.';
        }
        if ($fallen !== []) {
            $sections[] = ['title' => 'Caduti', 'lines' => $fallen];
        }

        // 3. Le tue scelte — witness flags that fired.
        $witness = config('game.epilogue.witness_flags', []);
        $choiceLines = [];
        foreach ($witness as $flag => $line) {
            if (($state->flags[$flag] ?? false) === true) {
                $choiceLines[] = $line;
            }
        }
        if ($choiceLines !== []) {
            $sections[] = ['title' => 'Le tue scelte', 'lines' => $choiceLines];
        }

        // 4. I superstiti — one colored line per living crew member.
        $survLines = [];
        foreach ($state->characters as $c) {
            if (! ($c['alive'] ?? true)) {
                continue;
            }
            $name = $c['name'] ?? '?';
            $stress = (int) ($c['stress'] ?? 0);
            $standing = (int) ($state->flags['standing_' . strtolower($name)] ?? 0);
            $survLines[] = "{$name}: " . $this->survivorLine($stress, $standing);
        }
        if ($survLines !== []) {
            $sections[] = ['title' => 'I superstiti', 'lines' => $survLines];
        }

        // 5. Epiteto — if earned (profile-scoped).
        $epithet = $state->profileFlags['epithet'] ?? null;
        if ($epithet !== null) {
            $sections[] = ['title' => 'Come ti ricorderanno', 'lines' => [$this->epithetLine((string) $epithet)]];
        }

        return $sections;
    }

    private function survivorLine(int $stress, int $standing): string
    {
        if ($standing <= -25) {
            return 'vivo, ma non ti perdona.';
        }
        if ($standing >= 25) {
            return 'vivo. Vi siete capiti.';
        }
        if ($stress >= 70) {
            return 'vivo, ma a pezzi.';
        }
        return 'vivo. Tira avanti.';
    }

    private function epithetLine(string $epithet): string
    {
        return match ($epithet) {
            'il_freddo' => 'Il Freddo. Hai scelto la sopravvivenza sopra ogni cosa.',
            'il_generoso' => 'Il Generoso. Hai dato, anche quando non potevi.',
            'l_imprudente' => "L'Imprudente. Hai ignorato gli avvertimenti.",
            'il_prudente' => 'Il Prudente. Hai temuto, e per questo siete vivi.',
            'il_solitario' => 'Il Solitario. Hai deciso da solo, sempre.',
            default => $epithet,
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter EpilogueComposerTest`
Expected: PASS (all 5).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/EpilogueComposer.php backend/config/game.php backend/tests/Unit/EpilogueComposerTest.php
git commit -m "feat: EpilogueComposer — sectioned end-of-run report from the run's facts"
```

---

## Task 8 (A3): Surface the epilogue in the API payload

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php`
- Test: `backend/tests/Feature/EpilogueTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/EpilogueTest.php`:

```php
<?php

use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('includes a sectioned epilogue in the ending payload of an ended run', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    // Force an ended run with a death on record.
    $run->status = 'ended';
    $run->ending_key = 'lone_survivor';
    $run->ending_type = 'win';
    $run->day = 26;
    $run->death_log = [['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck']];
    $run->save();

    $res = $this->getJson("/api/runs/{$run->id}")->assertOk();

    expect($res->json('ending.epilogue'))->not->toBeNull();
    // First section is the outcome.
    expect($res->json('ending.epilogue.0.title'))->toBe('Esito');
    // A fallen section exists naming Cole.
    $epilogueJson = json_encode($res->json('ending.epilogue'));
    expect($epilogueJson)->toContain('Cole');
});

it('has no epilogue while the run is active', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $res = $this->getJson("/api/runs/{$run->id}")->assertOk();
    expect($res->json('ending'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter EpilogueTest`
Expected: FAIL — `ending.epilogue` absent.

- [ ] **Step 3: Add epilogue to endingPayload**

In `backend/app/Http/Controllers/RunController.php`, in `endingPayload(Run $run)`, after the
`$ending` config is fetched and before the return, compose the epilogue and add it:

```php
        $state = \App\Game\Engine\RunState::fromRun($run);
        $epilogue = app(\App\Game\Engine\EpilogueComposer::class)->compose($state, $ending);
```

and add `'epilogue' => $epilogue,` to the returned array (alongside key/type/name/text/epithet).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter EpilogueTest`
Expected: PASS.

- [ ] **Step 5: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/RunController.php backend/tests/Feature/EpilogueTest.php
git commit -m "feat: surface the sectioned epilogue in the run ending payload"
```

---

## Task 9 (A4): Gate wins on real actions

**Files:**
- Modify: `backend/config/game.php`
- Modify: `backend/database/seeders/ContentEventSeeder.php` (ensure win-action flags get set)
- Test: `backend/tests/Feature/ChoiceLinkedEndingTest.php`

Add an action requirement to the two wins where a clear player action exists, keeping
`lone_survivor` as the guaranteed fallback.

- [ ] **Step 1: Confirm/establish the win-action flags exist**

Read ContentEventSeeder for `sos_sent` (set by a comms choice) and a colony/farming flag.
- `sos_sent` is already set by a comms choice (search confirms it). Good.
- For colony: if no `farmed`/`tended_crops` flag exists, add `['set_flag' => 'tended_crops', 'value' => true]`
  to the seedbank food-cultivation choice (the `food_hunt`/seedbank opportunity event). If a
  suitable flag already exists, use it. Pick the flag name and use it consistently in Step 3.

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/ChoiceLinkedEndingTest.php`:

```php
<?php

use App\Game\Engine\EndingService;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('does not award rescue without the SOS having been sent', function () {
    $run = app(RunFactory::class)->create(1, ['comms']);
    $run->day = 25;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 60, 'hull' => 80];
    $run->flags = []; // sos_sent NOT set
    $run->save();

    app(EndingService::class)->check($run);

    // Not rescued (no SOS) — falls through to lone_survivor (day>25 not yet, day=25) or stays active.
    expect($run->fresh()->ending_key)->not->toBe('win_rescue');
});

it('awards rescue once the SOS has been sent', function () {
    $run = app(RunFactory::class)->create(1, ['comms']);
    $run->day = 25;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 60, 'hull' => 80];
    $run->flags = ['sos_sent' => true];
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('win_rescue');
});

it('still gives lone_survivor as a fallback when alive past day 25 with no win-action', function () {
    $run = app(RunFactory::class)->create(1, ['welder']); // no comms/seedbank
    $run->day = 26;
    $run->resources = ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50];
    $run->flags = [];
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('lone_survivor');
});
```

- [ ] **Step 2b: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter ChoiceLinkedEndingTest`
Expected: FAIL — rescue currently fires on comms+day+morale without `sos_sent`.

- [ ] **Step 3: Add the action requirement to win_rescue (and win_colony)**

In `backend/config/game.php`, `win_rescue` `when` — add the `sos_sent` flag:

```php
            'when' => ['all' => [
                ['has_item' => 'comms'],
                ['day' => ['op' => '>=', 'value' => 24]],
                ['resource' => 'morale', 'op' => '>=', 'value' => 45],
                ['flag' => 'sos_sent', 'is' => true],
            ]],
```

`win_colony` `when` — add the farming-action flag chosen in Step 1 (e.g. `tended_crops`):

```php
            'when' => ['all' => [
                ['has_item' => 'seedbank'],
                ['day' => ['op' => '>=', 'value' => 25]],
                ['resource' => 'food', 'op' => '>=', 'value' => 68],
                ['flag' => 'tended_crops', 'is' => true],
            ]],
```

(Leave `win_escape`, `win_research`, `win_sacrifice`, and the finales as they are — escape is
already item+power gated, research/sacrifice already flag-gated. `lone_survivor` UNCHANGED — the
guaranteed fallback.)

- [ ] **Step 4: Re-seed, run filter, full suite**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ChoiceLinkedEndingTest`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: 0 failures. (If HungerBalanceTest or a sim-style test asserted a specific win key that
now requires a flag, STOP and report — likely needs the flag set in its setup.)

- [ ] **Step 5: Commit**

```bash
git add backend/config/game.php backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ChoiceLinkedEndingTest.php
git commit -m "feat: gate rescue/colony wins on the real action (SOS sent / crops tended)"
```

---

## Task 10 (B2): Frontend — surface effect deltas + epilogue

**Files:**
- Create: `frontend/src/effectFormat.ts`
- Modify: `frontend/src/api.ts`, `frontend/src/useRun.ts`, `frontend/src/components/GameOverScreen.tsx`, `frontend/src/components/Diario.tsx`
- Test: `frontend/src/effectFormat.test.ts`

- [ ] **Step 1: Write the failing test (pure formatter)**

Create `frontend/src/effectFormat.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { formatEffects } from "./effectFormat";

describe("formatEffects", () => {
  it("formats resource deltas with sign and Italian labels", () => {
    const out = formatEffects([
      { resource: "oxygen", delta: -12 },
      { resource: "morale", delta: 8 },
    ]);
    expect(out).toContain("ossigeno −12");
    expect(out).toContain("morale +8");
  });

  it("notes a death and a consumed item", () => {
    const out = formatEffects([{ kill: "Cole" }, { consume_item: "medkit" }]);
    expect(out.join(" ")).toContain("morte");
    expect(out.join(" ")).toContain("medkit");
  });

  it("returns empty for no effects", () => {
    expect(formatEffects([])).toEqual([]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npm run test -- effectFormat`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the formatter**

Create `frontend/src/effectFormat.ts`:

```ts
// Turns the raw effects array (returned by resolveChoice) into short Italian
// delta strings the UI can flash. Mirrors the backend EffectSummarizer notes,
// but for the immediate post-choice feedback. The backend already validates the
// effect shapes; here we only read what we recognize and ignore the rest.

const RES_LABELS: Record<string, string> = {
  oxygen: "ossigeno",
  food: "cibo",
  power: "energia",
  morale: "morale",
  hull: "scafo",
};

function signed(n: number): string {
  // Use a real minus sign for negatives to read cleanly in the UI.
  return n > 0 ? `+${n}` : `−${Math.abs(n)}`;
}

export function formatEffects(effects: unknown[]): string[] {
  const out: string[] = [];
  for (const raw of effects ?? []) {
    if (typeof raw !== "object" || raw === null) continue;
    const e = raw as Record<string, unknown>;
    if ("resource" in e) {
      const code = String(e.resource);
      const label = RES_LABELS[code] ?? code;
      const delta = Number(e.delta ?? 0);
      if (delta !== 0) out.push(`${label} ${signed(delta)}`);
    } else if ("kill" in e) {
      out.push("una morte");
    } else if ("consume_item" in e) {
      out.push(`${String(e.consume_item)} consumato`);
    } else if ("grant_item" in e) {
      out.push(`${String(e.grant_item)} ottenuto`);
    } else if ("damage_system" in e) {
      out.push(`${String(e.damage_system)} danneggiato`);
    } else if ("character" in e) {
      const who = String(e.character);
      if (Number(e.stress ?? 0) > 0) out.push(`${who}: stress su`);
    }
  }
  return out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npm run test -- effectFormat`
Expected: PASS.

- [ ] **Step 5: Wire effects through useRun + show them**

(a) In `frontend/src/api.ts`, extend the `Ending` type with the optional epilogue and the
`ChoiceLogEntry` with the optional summary (so TS is happy):

```ts
export type EpilogueSection = { title: string; lines: string[] };
```
Add `epilogue?: EpilogueSection[] | null;` to the `Ending` type. Add
`effects_summary?: { resources: Record<string, number>; notes: string[] };` to `ChoiceLogEntry`.

(b) In `frontend/src/useRun.ts`, `choose` currently returns `{ log, reactions }`. Add `effects`:

```ts
  const choose = useCallback(
    async (choiceIndex: number): Promise<{ log: string | null; reactions: Reaction[]; effects: unknown[] } | null> => {
      if (!run || busy) return null;
      setBusy(true);
      try {
        const res = await resolveChoice(run.id, choiceIndex);
        setRun(res.state);
        return {
          log: res.resolution.log ?? null,
          reactions: res.resolution.reactions ?? [],
          effects: res.resolution.effects ?? [],
        };
      } catch (e) {
        setError(e instanceof Error ? e.message : "Errore");
        return null;
      } finally {
        setBusy(false);
      }
    },
    [run, busy],
  );
```

(c) In the component that consumes `choose()`'s return (the game screen that flashes the log —
find the caller of `choose`, likely `GameScreen.tsx`), format and display the effects under the
log line using `formatEffects(result.effects)`. Render each as a small chip/line (style to match
the existing log flash). Read the screen to place it next to where `log` is shown.

- [ ] **Step 6: Show the epilogue on the game-over screen**

In `frontend/src/components/GameOverScreen.tsx`, after the existing `ending.text` paragraph,
render the epilogue sections when present:

```tsx
      {ending?.epilogue?.map((section) => (
        <div key={section.title} style={{ marginTop: 16, width: "100%", maxWidth: 380, textAlign: "left" }}>
          <div style={{
            fontSize: 11, fontFamily: "var(--font-mono)", letterSpacing: "0.2em",
            color: accent, opacity: 0.7, marginBottom: 6,
          }}>
            {section.title.toUpperCase()}
          </div>
          {section.lines.map((line, i) => (
            <p key={i} style={{ margin: "2px 0", fontSize: 13, lineHeight: 1.6, color: "rgba(232,244,253,0.8)" }}>
              {line}
            </p>
          ))}
        </div>
      ))}
```

- [ ] **Step 7: Show per-choice effects in the Diario**

In `frontend/src/components/Diario.tsx`, where each entry renders day/choice_label/reaction,
render the effect summary if present. After the reaction block:

```tsx
                {e.effects_summary && (
                  <div style={{ fontSize: 11, fontFamily: "var(--font-mono)", opacity: 0.6, marginTop: 2 }}>
                    {[
                      ...Object.entries(e.effects_summary.resources ?? {}).map(([k, v]) => `${k} ${v > 0 ? "+" + v : v}`),
                      ...(e.effects_summary.notes ?? []),
                    ].join(" · ")}
                  </div>
                )}
```

- [ ] **Step 8: Run frontend tests + typecheck**

Run: `cd frontend && npm run test`
Expected: PASS (effectFormat + any existing tests).
Run: `cd frontend && npx tsc -b --noEmit 2>/dev/null || npm run build`
Expected: no type errors (the new optional fields are additive).

- [ ] **Step 9: Commit**

```bash
git add frontend/src/effectFormat.ts frontend/src/effectFormat.test.ts frontend/src/api.ts frontend/src/useRun.ts frontend/src/components/GameOverScreen.tsx frontend/src/components/Diario.tsx frontend/src/components/GameScreen.tsx
git commit -m "feat: surface choice effect deltas (post-choice + Diario) and the epilogue on game over"
```

(Adjust the `git add` to the actual screen file you edited in Step 5c if not GameScreen.tsx.)

---

## Task 11: Full suite + balance sim + manual smoke

**Files:** none (verification only).

- [ ] **Step 1: Backend full suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (prior 223 + DeathLog/Epilogue/ChoiceLinkedEnding/EffectSummarizer/ItemsPersist).

- [ ] **Step 2: Frontend tests + build**

Run: `cd frontend && npm run test && npm run build`
Expected: PASS + clean build.

- [ ] **Step 3: Balance sim — endings distribution sane (no fallback collapse)**

Run:
```bash
cd backend && php artisan sim:run --count=200 --items=welder,scanner,rifle,drone --memory --no-interaction
cd backend && php artisan sim:run --count=200 --items=medkit,comms,seedbank,rifle --memory --no-interaction
```
Expected: 0 stalls; wins still occur (not collapsed entirely to lone_survivor — the greedy AI
may not send the SOS, so win_rescue may drop; that's expected, lone_survivor catches it). Record
the ending distribution. If ALL wins became lone_survivor and the win-rate cratered, the action
gates are too strict for the AI — note it (the AI doesn't optimize for narrative actions, a human
will; acceptable, but flag).

- [ ] **Step 4: Manual smoke (death log → notice → epilogue)**

Run a quick scripted check that the chain works end to end:
```bash
cd backend && php artisan tinker --execute="
\$sim = app(App\Game\Sim\Simulator::class);
\$r = \$sim->play(7, new App\Game\Sim\GreedySurvivalPolicy(), ['rifle','medkit','comms','scanner','drone']);
\$run = App\Models\Run::find(\$r->runId);
echo 'ending: '.\$run->ending_key.PHP_EOL;
echo 'deaths: '.json_encode(\$run->death_log).PHP_EOL;
\$ep = app(App\Game\Engine\EpilogueComposer::class)->compose(App\Game\Engine\RunState::fromRun(\$run), collect(config('game.endings'))->firstWhere('key', \$run->ending_key) ?? []);
echo 'epilogue sections: '.implode(', ', array_map(fn(\$s)=>\$s['title'], \$ep)).PHP_EOL;
"
```
Expected: prints an ending key, a death_log (possibly empty for a clean run — try a few seeds),
and epilogue section titles including 'Esito' and (if deaths) 'Caduti'.

- [ ] **Step 5: Commit the verification note**

```bash
git commit --allow-empty -m "test: full suite + frontend + sim sanity for epilogues/deaths/felt-choices

<N> backend tests, frontend green, build clean.
sim 200 A: <dist>; sim 200 B: <dist>; 0 stalls.
death_log -> death_notice -> epilogue chain verified end-to-end."
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** Part A — death_log capture (Tasks 2-4), In memoria notice (Task 5),
  EpilogueComposer + payload (Tasks 7-8), choice-linked wins with lone_survivor fallback
  (Task 9). Part B — items-persist bug (Task 1), per-choice effects_summary (Task 6), frontend
  deltas + epilogue (Task 10). Death announce-only-when-still-active handled (Task 5 strips the
  notice if the run ended). Honesty note carried (sim proves chain, not emotion).
- **Placeholder scan:** Tasks 4/5/9/10 have explicit "confirm X, adjust if Y" discovery steps
  with the fallback spelled out (food_sacrifice choice index; colony flag name; the screen file
  that calls choose). All code steps show full code. No TBDs.
- **Type/field consistency:** death_log entry shape `{name, day, cause, context}` is identical
  across EffectApplier (Task 3), DayProcessor (Task 4), EpilogueComposer (Task 7), tests, and the
  migration. `EffectSummarizer::summarize` return shape `{resources, notes}` matches its consumer
  in the choice log (Task 6) and the frontend `ChoiceLogEntry.effects_summary` type (Task 10).
  `apply(effects, state, rng, context=[])` signature is consistent between Task 3 (definition)
  and Task 4 (caller). `Ending.epilogue` / `EpilogueSection {title, lines}` consistent between
  composer (Task 7), payload (Task 8), api.ts + GameOverScreen (Task 10).
- **Ordering rationale:** B1 (items bug) first as a clean correctness fix; death_log column
  before its writers; summarizer before its choice-log use; composer before payload; win-gates
  independent; frontend last (consumes the new payload fields). Each task ships green.
- **Risk flagged:** Task 9 may drop win_rescue in the greedy sim (AI won't send SOS) → Task 11
  step 3 explicitly checks the distribution and treats lone_survivor fallback as acceptable.
