# Acts / Phases Structure — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a run evolve through three phases (isolation → deterioration → reckoning) that gate events, scale resource/system decay, and announce transitions — so day 30 feels different from day 3.

**Architecture:** A pure `PhaseResolver` computes the current phase from `day` + resource pressure + a persisted monotonic `phase_floor`. The phase is precomputed once into `RunState` so the (dependency-free) `ConditionEvaluator` can read it via two new leaf predicates (`phase`, `phase_index`). `DayProcessor` reads the phase to scale daily decay and to enqueue a transition-marker event when the floor rises. All thresholds and decay multipliers live in `config/game.php`. Content (retag + new per-phase events + 2 markers) is seeder data.

**Tech Stack:** Laravel, PHP, Pest, Eloquent (`Run` model with JSON columns), `php artisan test` / `migrate:fresh --seed` / `sim:run`.

---

## Background the engineer needs

- **Run state** lives on the `runs` table (JSON columns) and is mirrored into a plain
  `App\Game\Engine\RunState` (positional constructor) via `RunState::fromRun(Run)` /
  `RunState::applyTo(Run)`. Unit tests build `RunState` directly.
- **Resources**: `config('game.resources')` — keys oxygen/food/power/morale/hull, each with
  `max`/`start`/`daily`/`two_sided`. Current values held in `RunState::$resources` (code=>int).
- **Systems**: `config('game.systems')` — life_support/power_grid/hull_integrity, each with
  `daily_decay` and a below-threshold `penalty`.
- **Day pipeline**: `App\Game\DayProcessor::advance(Run)` runs end-of-day steps (resource drain,
  system degrade, hardship, hunger, stress) then `$run->day++; $run->save(); endings->check($run)`.
  Scheduled events are appended as `['key'=>X, 'fire_on_day'=>$day+1]` to `$run->scheduled_events`.
- **Conditions**: `App\Game\Engine\ConditionEvaluator::evaluate(?array, RunState): bool`. Pure,
  total, **fails closed** (`return false`) on unknown shapes. Leaf predicates are added as
  `if (array_key_exists('X', $condition)) { ... }` blocks. It is resolved from the DI container
  with a zero-arg constructor — DO NOT add constructor dependencies to it.
- **Schema**: `App\Game\Engine\EventSchema::CONDITION_KEYS` lists every allowed condition key.
  A condition key not in this list makes the seeder throw. Both new predicates must be added here.
- **Seeders**: `EventSeeder.php` (hand-written arrays) and `ContentEventSeeder.php` (uses helpers
  `ev`/`one`/`gamble`). `Event::updateOrCreate(['key'=>...], $event)`. `DatabaseSeeder` seeds both.
- **Tests**: `php artisan test [--filter X]`. Re-seed: `php artisan migrate:fresh --seed --quiet`.
  Balance sim: `php artisan sim:run --count=200 --items=<csv>`.

## File Structure

- Create: `backend/app/Game/Engine/PhaseResolver.php` — pure phase computation. One responsibility.
- Create: `backend/database/migrations/2026_06_08_000000_add_phase_floor_to_runs.php` — `phase_floor` column.
- Modify: `backend/app/Game/Engine/RunState.php` — carry `phaseFloor` + precomputed `phase`/`phaseIndex`.
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php` — `phase` + `phase_index` predicates.
- Modify: `backend/app/Game/Engine/EventSchema.php` — allow the two new condition keys.
- Modify: `backend/app/Game/DayProcessor.php` — scale decay by phase; enqueue transition markers; persist floor.
- Modify: `backend/config/game.php` — `phases` (order + day bands + pressure rule), `phase_decay`, display labels.
- Modify: `backend/app/Game/Engine/EventEngine.php` — expose phase in the card payload.
- Modify: `backend/database/seeders/ContentEventSeeder.php` — 2 markers + per-phase new events + selective retag.
- Test: `backend/tests/Unit/PhaseResolverTest.php`, `backend/tests/Feature/PhaseTest.php`.

---

## Task 1: Phase config

**Files:**
- Modify: `backend/config/game.php`

- [ ] **Step 1: Add the phases + decay config block**

In `backend/config/game.php`, add these two top-level keys to the returned array (place them
after the `'systems' => [ ... ],` block; match the file's array style and add an explanatory
comment like the neighbors):

```php
    /*
     | Phases (acts). The run evolves through ordered phases. The current phase is
     | DERIVED (never stored): max of the day-band, the resource-pressure band, and
     | a persisted monotonic floor (a run that recovers never drops to a calmer
     | phase). All thresholds here; the engine reads them.
     |
     |   order          canonical ordering; index drives `phase_index` conditions
     |   day_bands      [{ phase, from_day }] — highest from_day <= day wins
     |   pressure       a resource is "critical" at/below `critical_at_or_below`;
     |                  `bands` map a minimum critical-count to a phase floor
     |   labels         key => Italian display string (for the UI)
     */
    'phases' => [
        'order' => ['isolation', 'deterioration', 'reckoning'],
        'day_bands' => [
            ['phase' => 'isolation', 'from_day' => 1],
            ['phase' => 'deterioration', 'from_day' => 10],
            ['phase' => 'reckoning', 'from_day' => 21],
        ],
        'pressure' => [
            'critical_at_or_below' => 20,
            // Conservative: only 3+ critical resources pull the phase forward.
            'bands' => [
                ['min_critical' => 3, 'phase' => 'deterioration'],
                ['min_critical' => 5, 'phase' => 'reckoning'],
            ],
        ],
        'labels' => [
            'isolation' => 'Isolamento',
            'deterioration' => 'Deterioramento',
            'reckoning' => 'Resa dei conti',
        ],
    ],

    /*
     | Per-phase decay multiplier. DayProcessor multiplies resource daily drain and
     | system daily_decay by this factor. isolation = 1.0 => early game unchanged.
     */
    'phase_decay' => [
        'isolation' => 1.0,
        'deterioration' => 1.25,
        'reckoning' => 1.6,
    ],
```

- [ ] **Step 2: Verify config loads**

Run: `cd backend && php artisan config:clear && php -r "require 'vendor/autoload.php'; \$a=require 'config/game.php'; echo implode(',', \$a['phases']['order']), PHP_EOL, \$a['phase_decay']['reckoning'], PHP_EOL;"`
Expected: prints `isolation,deterioration,reckoning` then `1.6`.

- [ ] **Step 3: Commit**

```bash
git add backend/config/game.php
git commit -m "feat: phase config (day bands, pressure rule, per-phase decay)"
```

---

## Task 2: PhaseResolver (pure)

**Files:**
- Create: `backend/app/Game/Engine/PhaseResolver.php`
- Test: `backend/tests/Unit/PhaseResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/PhaseResolverTest.php`:

```php
<?php

use App\Game\Engine\PhaseResolver;

function fullResources(): array
{
    // All resources comfortably above the critical threshold (20).
    return ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90];
}

it('maps day bands to phases', function () {
    $r = new PhaseResolver();
    expect($r->resolve(1, fullResources(), 'isolation'))->toBe('isolation');
    expect($r->resolve(9, fullResources(), 'isolation'))->toBe('isolation');
    expect($r->resolve(10, fullResources(), 'isolation'))->toBe('deterioration');
    expect($r->resolve(20, fullResources(), 'isolation'))->toBe('deterioration');
    expect($r->resolve(21, fullResources(), 'isolation'))->toBe('reckoning');
    expect($r->resolve(40, fullResources(), 'isolation'))->toBe('reckoning');
});

it('does not advance on 2 critical resources (conservative)', function () {
    $r = new PhaseResolver();
    $res = fullResources();
    $res['oxygen'] = 10;
    $res['food'] = 10; // 2 critical
    expect($r->resolve(3, $res, 'isolation'))->toBe('isolation');
});

it('advances to deterioration on 3 critical resources', function () {
    $r = new PhaseResolver();
    $res = ['oxygen' => 10, 'food' => 10, 'power' => 10, 'morale' => 90, 'hull' => 90];
    expect($r->resolve(3, $res, 'isolation'))->toBe('deterioration');
});

it('advances to reckoning on 5 critical resources', function () {
    $r = new PhaseResolver();
    $res = ['oxygen' => 5, 'food' => 5, 'power' => 5, 'morale' => 5, 'hull' => 5];
    expect($r->resolve(3, $res, 'isolation'))->toBe('reckoning');
});

it('never drops below the floor (monotonic)', function () {
    $r = new PhaseResolver();
    // Day 1, calm resources, but floor already at reckoning -> stays reckoning.
    expect($r->resolve(1, fullResources(), 'reckoning'))->toBe('reckoning');
});

it('takes the max of day, pressure, and floor', function () {
    $r = new PhaseResolver();
    // Day band = deterioration (day 12), pressure = none, floor = isolation -> deterioration.
    expect($r->resolve(12, fullResources(), 'isolation'))->toBe('deterioration');
    // Day band = isolation, pressure = reckoning (5 critical), floor = isolation -> reckoning.
    $res = ['oxygen' => 5, 'food' => 5, 'power' => 5, 'morale' => 5, 'hull' => 5];
    expect($r->resolve(2, $res, 'isolation'))->toBe('reckoning');
});

it('exposes the index of a phase from config order', function () {
    $r = new PhaseResolver();
    expect($r->indexOf('isolation'))->toBe(0);
    expect($r->indexOf('deterioration'))->toBe(1);
    expect($r->indexOf('reckoning'))->toBe(2);
    expect($r->indexOf('bogus'))->toBe(0); // unknown -> floor at 0, fail safe
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter PhaseResolverTest`
Expected: FAIL with "Class PhaseResolver not found".

- [ ] **Step 3: Implement PhaseResolver**

Create `backend/app/Game/Engine/PhaseResolver.php`:

```php
<?php

namespace App\Game\Engine;

/**
 * Computes the current run phase. Pure and config-driven: the phase is the
 * highest (most advanced) of three inputs — the day band, the resource-pressure
 * band, and a persisted monotonic floor. A run that recovers never drops back to
 * a calmer phase. No phase name or threshold is hard-coded; all live in
 * config('game.phases').
 */
final class PhaseResolver
{
    /** @return list<string> canonical phase order */
    private function order(): array
    {
        return config('game.phases.order', ['isolation']);
    }

    public function indexOf(string $phase): int
    {
        $i = array_search($phase, $this->order(), true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * @param  array<string,int>  $resources  code => value
     */
    public function resolve(int $day, array $resources, string $floor): string
    {
        $candidates = [
            $this->dayBand($day),
            $this->pressureBand($resources),
            $floor,
        ];

        $best = 0;
        foreach ($candidates as $phase) {
            $best = max($best, $this->indexOf($phase));
        }

        return $this->order()[$best] ?? $this->order()[0];
    }

    private function dayBand(int $day): string
    {
        $phase = $this->order()[0];
        foreach (config('game.phases.day_bands', []) as $band) {
            if ($day >= (int) ($band['from_day'] ?? 1)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }

    /**
     * @param  array<string,int>  $resources
     */
    private function pressureBand(array $resources): string
    {
        $cfg = config('game.phases.pressure', []);
        $threshold = (int) ($cfg['critical_at_or_below'] ?? 0);

        $critical = 0;
        foreach ($resources as $value) {
            if ((int) $value <= $threshold) {
                $critical++;
            }
        }

        $phase = $this->order()[0];
        foreach ($cfg['bands'] ?? [] as $band) {
            if ($critical >= (int) ($band['min_critical'] ?? PHP_INT_MAX)) {
                $phase = $band['phase'] ?? $phase;
            }
        }
        return $phase;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter PhaseResolverTest`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/PhaseResolver.php backend/tests/Unit/PhaseResolverTest.php
git commit -m "feat: PhaseResolver — pure day+pressure+floor phase computation"
```

---

## Task 3: phase_floor column + RunState wiring

**Files:**
- Create: `backend/database/migrations/2026_06_08_000000_add_phase_floor_to_runs.php`
- Modify: `backend/app/Game/Engine/RunState.php`
- Test: `backend/tests/Feature/PhaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/PhaseTest.php`:

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

it('defaults a new run phase floor to isolation and exposes phase', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    expect($run->phase_floor)->toBe('isolation');

    $state = RunState::fromRun($run);
    expect($state->phaseFloor)->toBe('isolation');
    expect($state->phase)->toBe('isolation');
    expect($state->phaseIndex)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: FAIL — `phase_floor` column / `phaseFloor` property missing.

- [ ] **Step 3: Create the migration**

Create `backend/database/migrations/2026_06_08_000000_add_phase_floor_to_runs.php`:

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
            $table->string('phase_floor')->default('isolation');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('phase_floor');
        });
    }
};
```

- [ ] **Step 4: Add `phase_floor` to the Run model fillable/casts**

Open `backend/app/Models/Run.php`. Add `'phase_floor'` to the `$fillable` array (and, if there
is a `$casts`/`casts()` block, it needs no cast — it's a plain string, leave casts alone). If
`$fillable` uses `guarded = []` instead, skip this step (mass assignment already open).

- [ ] **Step 5: Wire RunState**

In `backend/app/Game/Engine/RunState.php`:

(a) Add a `use` for the resolver at the top (after the existing `use App\Models\Run;`):

```php
use App\Game\Engine\PhaseResolver;
```

(b) Add three public fields to the constructor signature, after `$choiceLog`:

```php
        public array $choiceLog = [],
        public string $phaseFloor = 'isolation',
        public string $phase = 'isolation',
        public int $phaseIndex = 0,
```

(c) In `fromRun()`, compute the phase once and pass it. Replace the `return new self(...)` block
so it reads (keep all existing named args; add the three new ones at the end):

```php
        $resolver = new PhaseResolver();
        $floor = $run->phase_floor ?? 'isolation';
        $phase = $resolver->resolve($run->day, $run->resources ?? [], $floor);

        return new self(
            day: $run->day,
            resources: $run->resources ?? [],
            flags: $run->flags ?? [],
            recentEvents: $run->recent_events ?? [],
            scheduledEvents: $run->scheduled_events ?? [],
            profileFlags: $profileFlags,
            characters: $run->characters ?? [],
            relationships: $run->relationships ?? [],
            items: $run->items ?? [],
            systems: $run->systems ?? [],
            choiceLog: $run->choice_log ?? [],
            phaseFloor: $floor,
            phase: $phase,
            phaseIndex: $resolver->indexOf($phase),
        );
```

(d) In `applyTo()`, persist the floor (add at the end of the method body):

```php
        $run->phase_floor = $this->phaseFloor;
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter "PhaseTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_06_08_000000_add_phase_floor_to_runs.php backend/app/Models/Run.php backend/app/Game/Engine/RunState.php backend/tests/Feature/PhaseTest.php
git commit -m "feat: phase_floor column + RunState carries derived phase"
```

---

## Task 4: phase & phase_index condition predicates

**Files:**
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Test: `backend/tests/Unit/PhaseResolverTest.php` is separate; add a new evaluator test file.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/PhaseConditionTest.php`:

```php
<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function stateInPhase(string $phase, int $index): RunState
{
    return new RunState(
        day: 1,
        resources: ['oxygen' => 90, 'food' => 90, 'power' => 90, 'morale' => 90, 'hull' => 90],
        phaseFloor: $phase,
        phase: $phase,
        phaseIndex: $index,
    );
}

it('matches the exact phase with {phase}', function () {
    $e = new ConditionEvaluator();
    expect($e->evaluate(['phase' => 'deterioration'], stateInPhase('deterioration', 1)))->toBeTrue();
    expect($e->evaluate(['phase' => 'deterioration'], stateInPhase('isolation', 0)))->toBeFalse();
});

it('matches a phase floor with {phase_index} numeric comparison', function () {
    $e = new ConditionEvaluator();
    $cond = ['phase_index' => ['op' => '>=', 'value' => 1]];
    expect($e->evaluate($cond, stateInPhase('deterioration', 1)))->toBeTrue();
    expect($e->evaluate($cond, stateInPhase('reckoning', 2)))->toBeTrue();
    expect($e->evaluate($cond, stateInPhase('isolation', 0)))->toBeFalse();
});
```

Note: this requires the `RunState` constructor to accept `phaseFloor`/`phase`/`phaseIndex` as
named args — added in Task 3. The other named args use their defaults.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter PhaseConditionTest`
Expected: FAIL — `{phase}` falls through to the unknown-shape branch and returns false for the
true case.

- [ ] **Step 3: Add the predicates to ConditionEvaluator**

In `backend/app/Game/Engine/ConditionEvaluator.php`, add these two blocks immediately AFTER the
existing `day` block (after line ~66, before the `flag` block):

```php
        if (array_key_exists('phase', $condition)) {
            return $state->phase === $condition['phase'];
        }

        if (array_key_exists('phase_index', $condition)) {
            $spec = $condition['phase_index'];
            return $this->compare($state->phaseIndex, $spec['op'] ?? '=', $spec['value'] ?? 0);
        }
```

Also extend the grammar docblock comment near the top (add `{ phase } | { phase_index: { op, value } }`
to the listed grammar) so the documentation stays accurate.

- [ ] **Step 4: Allow the keys in EventSchema**

In `backend/app/Game/Engine/EventSchema.php`, add `'phase'` and `'phase_index'` to the
`CONDITION_KEYS` const array (so seeded events using them validate instead of throwing).

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter PhaseConditionTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/ConditionEvaluator.php backend/app/Game/Engine/EventSchema.php backend/tests/Unit/PhaseConditionTest.php
git commit -m "feat: phase & phase_index condition predicates"
```

---

## Task 5: Per-phase decay in DayProcessor

**Files:**
- Modify: `backend/app/Game/DayProcessor.php`
- Test: `backend/tests/Feature/PhaseTest.php`

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/PhaseTest.php`:

```php
it('scales resource drain by the phase decay multiplier', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);

    // Force reckoning via the floor; calm resources so only the floor drives phase.
    $run->phase_floor = 'reckoning';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->day = 1;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    // oxygen daily drain = 3; reckoning multiplier = 1.6 => 3 * 1.6 = 4.8 -> 4 (int).
    // Day-1 systems are at 100 (no below-threshold penalty yet), so the only oxygen
    // change is the scaled daily drain. 100 - 4 = 96.
    expect($run->fresh()->resources['oxygen'])->toBe(96);
});

it('leaves isolation decay identical to the base daily drain', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->phase_floor = 'isolation';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->day = 1;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    // isolation multiplier = 1.0 => oxygen drain stays 3 => 100 - 3 = 97.
    expect($run->fresh()->resources['oxygen'])->toBe(97);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: FAIL — reckoning case gives 97 (unscaled), expected 96.

- [ ] **Step 3: Implement phase-scaled decay**

In `backend/app/Game/DayProcessor.php`:

(a) Add the resolver import at the top (after `use App\Models\Run;`):

```php
use App\Game\Engine\PhaseResolver;
```

(b) At the START of `advance()`, after the `if ($run->status !== 'active')` guard and after the
existing `$resources = $run->resources;` ... `$scheduled = ...;` reads, compute the phase + factor:

```php
        $resolver = new PhaseResolver();
        $phase = $resolver->resolve($run->day, $resources, $run->phase_floor ?? 'isolation');
        $decay = (float) config("game.phase_decay.$phase", 1.0);
```

(c) Replace the resource-consumption loop (step 1) so the drain is scaled:

```php
        // 1. Resource consumption (scaled by the current phase).
        foreach (config('game.resources') as $code => $def) {
            $value = $resources[$code] ?? $def['start'];
            $drain = (int) round($def['daily'] * $decay);
            $resources[$code] = $this->clampResource($value - $drain, $def['max']);
        }
```

(d) Pass the factor into system degradation. Change the call:

```php
        // 2. System degradation + below-threshold resource penalties (scaled).
        [$systems, $resources] = $this->degradeSystems($systems, $resources, $decay);
```

and update `degradeSystems` signature + the decay line:

```php
    private function degradeSystems(array $systems, array $resources, float $decay = 1.0): array
    {
        foreach (config('game.systems') as $key => $def) {
            $eff = $systems[$key]['efficiency'] ?? $def['start'];
            $eff = $this->clampResource($eff - (int) round($def['daily_decay'] * $decay), 100);
            $systems[$key] = ['efficiency' => $eff];
```

(leave the rest of `degradeSystems` — the penalty block — unchanged).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: PASS (both the 96 and 97 cases).

- [ ] **Step 5: Run the full suite to catch decay regressions**

Run: `cd backend && php artisan test`
Expected: 0 failures. (isolation=1.0 keeps early-game numbers; only deteriorated/reckoning runs
decay faster. If a pre-existing test that runs into late game now fails on resource numbers,
STOP and report — it may need its expectation updated or indicates the decay is too aggressive.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/DayProcessor.php backend/tests/Feature/PhaseTest.php
git commit -m "feat: scale daily resource/system decay by phase"
```

---

## Task 6: Transition markers + floor persistence in DayProcessor

**Files:**
- Modify: `backend/app/Game/DayProcessor.php`
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/PhaseTest.php`

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/PhaseTest.php`:

```php
it('raises the floor and schedules a marker when the phase advances', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    // Day 9 -> advancing makes it day 10 = deterioration band.
    $run->day = 9;
    $run->phase_floor = 'isolation';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->scheduled_events = [];
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());
    $after = $run->fresh();

    expect($after->phase_floor)->toBe('deterioration');
    $keys = collect($after->scheduled_events)->pluck('key');
    expect($keys)->toContain('phase_enter_deterioration');
});

it('does not re-schedule a marker when the phase does not advance', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 2; // stays isolation after advancing to day 3
    $run->phase_floor = 'isolation';
    $run->resources = ['oxygen' => 100, 'food' => 100, 'power' => 100, 'morale' => 100, 'hull' => 100];
    $run->scheduled_events = [];
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());
    $after = $run->fresh();

    expect($after->phase_floor)->toBe('isolation');
    $keys = collect($after->scheduled_events)->pluck('key');
    expect($keys)->not->toContain('phase_enter_deterioration');
    expect($keys)->not->toContain('phase_enter_reckoning');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: FAIL — floor not persisted / marker not scheduled.

- [ ] **Step 3: Implement floor-raise + marker scheduling**

In `backend/app/Game/DayProcessor.php`, in `advance()`, AFTER step 4 (`processStress`) and
BEFORE the `$run->resources = $resources; ...` write-back block, insert:

```php
        // Phase transition: recompute on the NEW day's state. If the phase has
        // advanced past the stored floor, raise the floor and enqueue the
        // transition marker once (markers are keyed phase_enter_<phase>; there is
        // no marker for the initial isolation phase).
        $oldFloor = $run->phase_floor ?? 'isolation';
        $newDay = $run->day + 1;
        $newPhase = $resolver->resolve($newDay, $resources, $oldFloor);
        if ($resolver->indexOf($newPhase) > $resolver->indexOf($oldFloor)) {
            $run->phase_floor = $newPhase;
            $marker = 'phase_enter_' . $newPhase;
            $scheduled[] = ['key' => $marker, 'fire_on_day' => $newDay];
        }
```

(`$resolver` is already in scope from Task 5 step 3b. The `$scheduled` array is written back
in the existing `$run->scheduled_events = $scheduled;` line — confirm that line exists; it does.)

- [ ] **Step 4: Add the two marker events**

In `backend/database/seeders/ContentEventSeeder.php`, find the silent/narrative events section
(the `silentEvents()` method, which builds single-/no-choice narrative cards). Add these two
events to the array it returns (use the `ev` + `one` helpers; single choice = a quiet
acknowledgement). They are gated to their phase so they can only surface in-phase, and given a
high enough base_weight + zero cooldown that the scheduled fire reliably resolves:

```php
            $this->ev([
                'key' => 'phase_enter_deterioration',
                'title' => 'Qualcosa è cambiato',
                'speaker' => null,
                'body' => "Lo senti nei suoni della stazione: un ronzio nuovo, una vibrazione che prima non c'era. I sistemi iniziano a cedere. Da qui in avanti, ogni giorno costa di più.",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 1, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Stringo i denti', [], 'Non c\'è altro da fare. Si va avanti.'),
                ],
            ]),

            $this->ev([
                'key' => 'phase_enter_reckoning',
                'title' => 'Non c\'è più tempo',
                'speaker' => null,
                'body' => "Le margini si sono esauriti. Ogni scelta adesso pesa il doppio, e gli errori non si recuperano più. Qualunque cosa succeda, succede ora.",
                'requires' => ['phase' => 'reckoning'],
                'base_weight' => 1, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Affronto quello che viene', [], 'Qualunque cosa sia.'),
                ],
            ]),
```

- [ ] **Step 5: Re-seed and run the phase test**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter "PhaseTest"`
Expected: PASS (floor raised, marker scheduled; non-advance case clean).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/DayProcessor.php backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/PhaseTest.php
git commit -m "feat: phase transition markers + monotonic floor persistence"
```

---

## Task 7: Expose phase in the card payload (UI hook)

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Test: `backend/tests/Feature/PhaseTest.php`

- [ ] **Step 1: Find the card-state response shape**

Open `backend/app/Game/Engine/EventEngine.php` and read `currentCard(Run $run): array` (starts
~line 40). Identify the array it returns to the API for the player's current card/state. (It
returns at least `['event' => ..., 'choices' => ...]`.) Determine where run-level fields like
day/resources are surfaced to the frontend — if `currentCard` already includes a `state`/`run`
sub-array with `day`, add `phase` next to it; if the card response is purely event+choices and
day/resources are serialized elsewhere (e.g. a Run API resource), add `phase` + `phase_label`
there instead. The goal: the frontend can read the current phase key and Italian label.

- [ ] **Step 2: Write the failing test**

Append to `backend/tests/Feature/PhaseTest.php` (adjust the assertion path in step 4 to match
where you surface it; this test asserts via the HTTP state endpoint the frontend uses — find the
route that returns the current run state, e.g. `GET /api/runs/{id}` or `/api/card`):

```php
it('surfaces the current phase and its label in the run state payload', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 25;
    $run->phase_floor = 'reckoning';
    $run->save();

    // Hit the endpoint the frontend reads for run state. Replace the URI below
    // with the actual state route discovered in step 1.
    $res = $this->getJson("/api/runs/{$run->id}")->assertOk();

    expect($res->json('phase'))->toBe('reckoning');
    expect($res->json('phase_label'))->toBe('Resa dei conti');
});
```

If no single run-state JSON route exists, instead assert directly on the engine method you
modified (call `app(EventEngine::class)->currentCard($run->fresh())` and assert the returned
array contains `phase`/`phase_label`). Use whichever matches the codebase; keep ONE test.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: FAIL — `phase`/`phase_label` absent from the payload.

- [ ] **Step 4: Add phase to the payload**

Compute the phase from the run and include it in the response identified in step 1. Use:

```php
$resolver = new \App\Game\Engine\PhaseResolver();
$phase = $resolver->resolve($run->day, $run->resources ?? [], $run->phase_floor ?? 'isolation');
$phaseLabel = config("game.phases.labels.$phase", $phase);
```

and add `'phase' => $phase, 'phase_label' => $phaseLabel,` to the returned array (or the
appropriate API resource). Prefer reading from `RunState::fromRun($run)->phase` if a RunState is
already built in that method — reuse it rather than constructing a second resolver.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter "PhaseTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php backend/tests/Feature/PhaseTest.php
git commit -m "feat: expose current phase + label in run state payload"
```

---

## Task 8: New per-phase events + selective retag

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/PhaseContentTest.php`

This task adds the distinctive per-phase content and converts a few generic day-gated crises to
phase gates. Author 2-3 NEW events per phase (English keys, Italian text) and retag selectively.

- [ ] **Step 1: Write the failing data test**

Create `backend/tests/Feature/PhaseContentTest.php`:

```php
<?php

use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/** Collect every event whose requires tree mentions {phase: X} (at any depth). */
function eventsGatedToPhase(string $phase): \Illuminate\Support\Collection
{
    return Event::all()->filter(function (Event $e) use ($phase) {
        return str_contains(json_encode($e->requires), '"phase":"' . $phase . '"');
    });
}

it('has at least two distinctive events gated to each phase', function () {
    // Markers count as one each; require at least 2 new per phase beyond isolation's marker-less start.
    expect(eventsGatedToPhase('isolation')->count())->toBeGreaterThanOrEqual(2);
    expect(eventsGatedToPhase('deterioration')->count())->toBeGreaterThanOrEqual(3); // incl. marker
    expect(eventsGatedToPhase('reckoning')->count())->toBeGreaterThanOrEqual(3); // incl. marker
});

it('keeps every event valid against the DSL schema', function () {
    // Re-run the existing schema guard over the full pool to prove new phase
    // gates and content are well-formed (mirrors ContentTest's schema check).
    $schema = new \App\Game\Engine\EventSchema(array_keys(config('game.resources')));
    Event::all()->each(function (Event $e) use ($schema) {
        $schema->validate([
            'key' => $e->key,
            'title' => $e->title,
            'body' => $e->body,
            'choices' => $e->choices,
            'requires' => $e->requires,
        ]);
        expect(true)->toBeTrue();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter PhaseContentTest`
Expected: FAIL — not enough phase-gated events yet (only the 2 markers exist).

- [ ] **Step 3: Add the new per-phase events**

In `backend/database/seeders/ContentEventSeeder.php`, add a new method `phaseEvents()` returning
the events below, and call it from the `events()` aggregator (add `$this->phaseEvents(),` to the
`array_merge(...)` list alongside the other section calls). Each event is gated to its phase and
uses existing helpers. (These are concrete starter cards; tone matches neighbors.)

```php
    // ---- Phase-flavoured events (acts: isolation / deterioration / reckoning) ----
    private function phaseEvents(): array
    {
        return [
            // --- ISOLATION: mystery, presentation, low stakes ---
            $this->ev([
                'key' => 'iso_signal', 'title' => 'Un segnale', 'speaker' => 'Anna',
                'body' => "Una frequenza che non riconosci, debole, ripetitiva. Forse un eco, forse qualcuno. Anna ti guarda: 'Lo registro o lo lascio andare?'",
                'requires' => ['phase' => 'isolation'],
                'base_weight' => 8, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Registralo', [['resource' => 'morale', 'delta' => 4]], 'Forse non è niente. Ma è qualcosa a cui pensare.'),
                    $this->one('Lascia perdere, abbiamo altro', [['resource' => 'morale', 'delta' => -2]], 'Il segnale svanisce. Resta il silenzio.'),
                ],
            ]),
            $this->ev([
                'key' => 'iso_previous_crew', 'title' => 'Chi c\'era prima', 'speaker' => null,
                'body' => "In un cassetto, una foto sbiadita: volti che non conosci, sorridenti davanti a questa stessa paratia. Sul retro, una data e una frase cancellata.",
                'requires' => ['phase' => 'isolation'],
                'base_weight' => 7, 'cooldown_days' => 8,
                'choices' => [
                    $this->one('La tengo', [['resource' => 'morale', 'delta' => 3]], 'La metti in tasca. Non sei il primo, qui.'),
                    $this->one('La rimetto a posto', [], 'Richiudi il cassetto. Alcune cose è meglio non saperle.'),
                ],
            ]),

            // --- DETERIORATION: systems failing, tension, medium stakes ---
            $this->ev([
                'key' => 'det_chain_fault', 'title' => 'Un guasto tira l\'altro', 'speaker' => 'Cole',
                'body' => "Cole è coperto di grasso fino ai gomiti. 'Ho tamponato il primo, ma ne è saltato un altro. Posso tenerne in piedi uno solo. Quale?'",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 12, 'cooldown_days' => 4,
                'choices' => [
                    $this->one('Tieni in piedi l\'energia', [['damage_system' => 'life_support', 'amount' => 15], ['resource' => 'power', 'delta' => 6]], 'Le luci reggono. L\'aria si fa un po\' più pesante.'),
                    $this->one('Tieni in piedi il supporto vitale', [['damage_system' => 'power_grid', 'amount' => 15], ['resource' => 'oxygen', 'delta' => 4]], 'Si respira. Ma qualcosa, al buio, smette di funzionare.'),
                ],
            ]),
            $this->ev([
                'key' => 'det_ration_strain', 'title' => 'La cinghia si stringe', 'speaker' => null,
                'body' => "Le porzioni si sono ridotte di nuovo. Stavolta qualcuno lo dice ad alta voce, e l'aria nella stanza cambia.",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 11, 'cooldown_days' => 5,
                'choices' => [
                    $this->gamble('Imponi il razionamento', [['character' => 'all', 'stress' => 6]], 'Mugugnano, ma obbediscono.', [['character' => 'all', 'stress' => 12], ['resource' => 'morale', 'delta' => -6]], 'Qualcuno sbatte la porta. La frattura si allarga.', 6, 4, 'rischioso'),
                    $this->one('Dividi la tua parte', [['resource' => 'morale', 'delta' => 6], ['character' => 'random', 'hunger' => 8]], 'Un gesto che pesa. Loro lo notano.'),
                ],
            ]),

            // --- RECKONING: terminal, irreversible, high stakes ---
            $this->ev([
                'key' => 'rec_unrecoverable', 'title' => 'Quello che non torna', 'speaker' => 'Anna',
                'body' => "Anna posa gli strumenti. 'Non si ripara. Non con quello che abbiamo. Possiamo solo decidere come spenderlo, prima che se ne vada del tutto.'",
                'requires' => ['phase' => 'reckoning'],
                'base_weight' => 13, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Bruciamo tutto adesso', [['resource' => 'power', 'delta' => 20], ['damage_system' => 'power_grid', 'amount' => 60]], 'Un ultimo lampo di potenza. Poi il freddo.'),
                    $this->one('Lo centelliniamo', [['resource' => 'power', 'delta' => -4]], 'Razioni di energia. Si tira avanti, per ora.'),
                ],
            ]),
            $this->ev([
                'key' => 'rec_promises', 'title' => 'Il conto delle promesse', 'speaker' => null,
                'body' => "Tutte le cose che hai detto che avresti sistemato, le persone che hai detto avresti protetto. Adesso il conto è qui, e va pagato.",
                'requires' => ['phase' => 'reckoning'],
                'base_weight' => 12, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Affronto chi ho deluso', [['resource' => 'morale', 'delta' => -8], ['modify_trust' => 12]], 'Parole dure. Ma alla fine, qualcosa si ricuce.'),
                    $this->one('Tengo la testa bassa', [['resource' => 'morale', 'delta' => 4], ['modify_trust' => -10]], 'Eviti i loro occhi. È più facile, e peggio.'),
                ],
            ]),
        ];
    }
```

- [ ] **Step 4: Selective retag of existing day-gated crises**

In `ContentEventSeeder.php`, find GENERIC crisis events (NOT character-thread events
anna_/bex_/cole_) that use `'requires' => ['day' => ['op' => '>=', 'value' => N]]` where N maps
to a phase boundary, and convert them:
- N in 8..14 → `'requires' => ['phase_index' => ['op' => '>=', 'value' => 1]]`
- N >= 21 → `'requires' => ['phase' => 'reckoning']`

Convert AT MOST 3-4 such events, one at a time. After EACH conversion, re-seed and run
`php artisan test --filter "ContentTest|PhaseContentTest"` to confirm green before the next.
**Do NOT** retag character-thread events (their fine-grained day gates are intentional) or
events where `day` is combined with other thread-specific conditions. If unsure whether an
event is generic, leave it as `day` — retag is opportunistic, not mandatory.

- [ ] **Step 5: Re-seed and run the content tests**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter "PhaseContentTest|ContentTest"`
Expected: PASS — phase-gated counts met, all events schema-valid, Selector pool intact.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/PhaseContentTest.php
git commit -m "feat: per-phase events + selective retag of generic day-gated crises"
```

---

## Task 9: Selector-never-stalls per phase

**Files:**
- Test: `backend/tests/Feature/PhaseContentTest.php`

- [ ] **Step 1: Write the test**

Append to `backend/tests/Feature/PhaseContentTest.php`:

```php
it('always has a drawable card in every phase', function () {
    $selector = app(\App\Game\Engine\Selector::class);
    $rng = new \App\Game\SeededRng(1);

    foreach (['isolation' => 5, 'deterioration' => 15, 'reckoning' => 25] as $phase => $day) {
        $state = new \App\Game\Engine\RunState(
            day: $day,
            resources: ['oxygen' => 60, 'food' => 60, 'power' => 60, 'morale' => 60, 'hull' => 60],
            phaseFloor: $phase,
            phase: $phase,
            phaseIndex: $selector ? array_search($phase, config('game.phases.order'), true) : 0,
        );

        $picked = $selector->pick(\App\Models\Event::all(), $state, $rng);
        expect($picked)->not->toBeNull("phase {$phase} must always yield a card");
    }
});
```

NOTE: confirm the Selector's public method name and signature first (it may be `pick`,
`select`, or `next`, and may take the event collection + state + rng in a different order). Open
`backend/app/Game/Engine/Selector.php`, read the public entry point, and adjust the call above to
match exactly. Keep the assertion (non-null in every phase).

- [ ] **Step 2: Run test**

Run: `cd backend && php artisan test --filter PhaseContentTest`
Expected: PASS. (If a phase yields null, the filler pool isn't covering it — filler events are
`is_filler` and ungated, so this should hold; if it fails, STOP and report which phase starved.)

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/PhaseContentTest.php
git commit -m "test: Selector never stalls in any phase"
```

---

## Task 10: Full suite + balance sim

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (184 prior + new PhaseResolverTest / PhaseConditionTest / PhaseTest /
PhaseContentTest). If any pre-existing late-game test fails on resource numbers, the phase decay
changed its trajectory — read the failure, decide whether to update the expectation (decay is
intended) or tune `phase_decay` down; report the decision.

- [ ] **Step 2: Frontend typecheck (if present)**

Run: `cd frontend && npm run typecheck 2>/dev/null || echo "no frontend typecheck target"`
Expected: PASS or "no target". (The payload gained `phase`/`phase_label`; if the frontend has a
typed run-state shape, it may need the new optional fields — if typecheck fails on that, add the
fields to the type. If no frontend consumes it yet, skip.)

- [ ] **Step 3: Balance sim — confirm no early death-spiral**

Run:
```bash
cd backend && php artisan sim:run --count=200 --items=welder,scanner,rifle,drone --no-interaction
cd backend && php artisan sim:run --count=200 --items=medkit,comms,seedbank,rifle --no-interaction
```
Expected: completes without crash; run length stays in a sane band (median in the tens of days,
not collapsing to single digits); wins not pinned at 0% or ~100%; **stalls 0**. Compare against
the pre-phase baseline (welder/scanner/rifle/drone was ~72.5% wins, median ~26 days). A modest
shift is fine; a collapse in run length signals the pressure+decay combo pushes reckoning too
early — tune `phases.pressure.bands` (raise `min_critical`) or `phase_decay` down in config and
re-run. Record the numbers.

- [ ] **Step 4: Commit the verification note**

```bash
git commit --allow-empty -m "test: full suite green + balance sim sanity for phases

<N> tests pass.
sim 200 welder/scanner/rifle/drone: wins <w>% / median <d>d / stalls 0
sim 200 medkit/comms/seedbank/rifle: wins <w>% / median <d>d / stalls 0
(baseline pre-phases: ~72.5% wins, median ~26d)"
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** phase model (Task 2), phase_floor + derived phase (Task 3), DSL predicates
  phase/phase_index (Task 4), per-phase decay with isolation=1.0 (Task 5), transition markers +
  monotonic floor (Task 6), UI payload (Task 7), gating + new per-phase events + selective retag
  (Task 8), Selector-per-phase (Task 9), sim + suite (Task 10). All spec sections mapped.
- **Placeholder scan:** Tasks 7 and 9 deliberately ask the engineer to confirm one real signature
  (run-state route / Selector entry point) before asserting — these are discovery steps with the
  exact fallback spelled out, not placeholders. All code steps show full code.
- **Type/field consistency:** `phaseFloor`/`phase`/`phaseIndex` names are identical across
  RunState (Task 3), evaluator tests (Task 4), DayProcessor (Tasks 5-6), and Selector test
  (Task 9). `PhaseResolver::resolve(int,array,string)` and `::indexOf(string)` signatures are
  stable across Tasks 2/3/5/6/7. Config keys (`game.phases.order`, `game.phase_decay.<phase>`,
  `game.phases.labels.<phase>`, `game.phases.pressure`) match between Task 1 and consumers.
- **Risk flagged in-plan:** the spec's #1 risk (early death-spiral from pressure+decay) has an
  explicit tune-and-retry instruction in Task 10 step 3, and retag is done one-at-a-time with
  per-step verification (Task 8 step 4).
