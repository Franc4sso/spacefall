# Island Theme — Multi-Tema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a second playable theme (**isola**, LOST-style plane-crash survival) to the existing Starfall Station engine without duplicating the engine — the current game becomes the **space** theme, **island** is new data, the player picks the theme when starting a run.

**Architecture:** The engine is already data-driven and theme-agnostic. We introduce a `ThemeConfig` service that resolves `config("themes.{$theme}.{$key}")`, move the existing `config/game.php` into `config/themes/space.php`, add `config/themes/island.php`, and thread a `theme` string through `Run` → `RunState` → engine services. Events get a `theme` column scoped `(theme, key)`. Default `'space'` everywhere keeps the 271 existing tests green.

**Tech Stack:** Laravel 12, PHP 8.2, Pest. Server-authoritative; all content is data (config + DB seeders), never engine code.

---

## File Structure

**Created:**
- `backend/app/Game/ThemeConfig.php` — theme-scoped config resolver (the new seam).
- `backend/config/themes/space.php` — existing `config/game.php` content, moved 1:1.
- `backend/config/themes/island.php` — new island tuning/content config.
- `backend/database/seeders/IslandEventSeeder.php` — island events.
- `backend/database/migrations/*_add_theme_to_runs_table.php`
- `backend/database/migrations/*_add_theme_to_events_table.php`
- `backend/tests/Feature/ThemeConfigTest.php`
- `backend/tests/Feature/ThemeScopingTest.php`

**Modified (config consumers — `config('game.X')` → theme-aware):**
- `backend/app/Game/RunFactory.php` — accept `$theme`, read via `ThemeConfig`.
- `backend/app/Game/Engine/RunState.php` — carry `theme` (the threading vehicle for injected services).
- `backend/app/Game/Engine/EffectApplier.php`, `HintService.php`, `OutcomeWeigher.php` — injected with `ThemeConfig`, resolve per-call via `$state->theme`.
- `backend/app/Game/DayProcessor.php`, `EndingService.php`, `ExpeditionResolver.php`, `PhaseResolver.php`, `EpilogueComposer.php`, `EventEngine.php` — read via `$run`/`$state` theme.
- `backend/app/Http/Controllers/RunController.php`, `MetaController.php` — accept/validate `theme`.
- `backend/app/Providers/AppServiceProvider.php` — inject `ThemeConfig` into the three services.
- `backend/app/Game/Sim/Simulator.php` + the `sim:run` command — accept `--theme`.
- Seeders `ContentEventSeeder.php`, `EventSeeder.php`, `FillContentEventSeeder.php` — tag events `theme => 'space'`.
- `backend/app/Models/Run.php` — `theme` in `$fillable`.
- `backend/app/Models/Event.php` — `theme` fillable/usage if needed.
- `frontend/` — new-run screen theme picker, POST `theme`.

**Key design refinement vs spec:** `RunState` carries `theme`. The three services wired as singletons in `AppServiceProvider` (`EffectApplier`, `HintService`, `OutcomeWeigher`) cannot be theme-scoped at construction. Their public methods already receive `RunState`, so they take `ThemeConfig` (injected) and resolve `->for($state->theme)->get(...)` per call. This avoids re-architecting the DI container.

---

## Phase 1 — ThemeConfig service + config relocation

### Task 1: Move space config into a theme file

**Files:**
- Create: `backend/config/themes/space.php`
- Modify: `backend/config/game.php` (becomes a thin pointer or is emptied — see step 3)

- [ ] **Step 1: Copy game.php to the space theme file**

```bash
cd backend
mkdir -p config/themes
cp config/game.php config/themes/space.php
```

- [ ] **Step 2: Verify the copy loads**

Update the header comment in `config/themes/space.php` line ~5 from
`| Starfall Station — game tuning constants` to
`| Space theme — Starfall Station tuning constants`.

Run: `php artisan tinker --execute="dump(array_keys(config('themes.space')));"`
Expected: prints the top-level keys (`resources`, `systems`, `items`, `endings`, `epilogue`, etc.).

- [ ] **Step 3: Leave `config/game.php` returning the space theme for back-compat**

Replace the entire body of `config/game.php` with:

```php
<?php

// Back-compat shim: legacy config('game.X') resolves to the space theme.
// New code MUST go through App\Game\ThemeConfig instead. This shim keeps the
// existing 271 tests green until every call-site is migrated; the final task
// of this plan removes it.
return require __DIR__.'/themes/space.php';
```

- [ ] **Step 4: Run the full suite — must stay green**

Run: `php artisan test`
Expected: PASS (all existing tests; the shim makes `config('game.X')` identical to before).

- [ ] **Step 5: Commit**

```bash
git add config/game.php config/themes/space.php
git commit -m "refactor: move space tuning into config/themes/space.php (back-compat shim)"
```

---

### Task 2: ThemeConfig service

**Files:**
- Create: `backend/app/Game/ThemeConfig.php`
- Test: `backend/tests/Feature/ThemeConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Game\ThemeConfig;

it('resolves a key from the requested theme', function () {
    $tc = new ThemeConfig();
    expect($tc->for('space')->get('items_pick'))->toBe(config('themes.space.items_pick'));
});

it('returns the default when a key is missing', function () {
    expect((new ThemeConfig())->for('space')->get('does.not.exist', 'fallback'))
        ->toBe('fallback');
});

it('rejects an unknown theme', function () {
    (new ThemeConfig())->for('atlantis');
})->throws(InvalidArgumentException::class);

it('isolates state between for() calls', function () {
    $tc = new ThemeConfig();
    $space = $tc->for('space');
    expect($space->get('items_pick'))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ThemeConfigTest`
Expected: FAIL — `Class "App\Game\ThemeConfig" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Game;

use InvalidArgumentException;

/**
 * Resolves theme-scoped configuration. Replaces direct config('game.X') reads
 * so the engine can serve multiple themes (space, island) from the same code.
 * Each theme's data lives in config/themes/{theme}.php.
 */
final class ThemeConfig
{
    private const THEMES = ['space', 'island'];

    private ?string $theme = null;

    /**
     * Bind to a theme. Returns a fresh instance so callers never share state.
     */
    public function for(string $theme): self
    {
        if (! in_array($theme, self::THEMES, true)) {
            throw new InvalidArgumentException("Unknown theme: {$theme}");
        }
        $clone = new self();
        $clone->theme = $theme;
        return $clone;
    }

    /**
     * Read a dotted key within the bound theme, e.g. get('resources').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->theme === null) {
            throw new InvalidArgumentException('Call for($theme) before get().');
        }
        return config("themes.{$this->theme}.{$key}", $default);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::THEMES;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ThemeConfigTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Game/ThemeConfig.php tests/Feature/ThemeConfigTest.php
git commit -m "feat: ThemeConfig service for theme-scoped config resolution"
```

---

## Phase 2 — Run carries theme

### Task 3: Add `theme` column to runs

**Files:**
- Create: `backend/database/migrations/2026_06_11_000001_add_theme_to_runs_table.php`
- Modify: `backend/app/Models/Run.php` (`$fillable`)
- Test: `backend/tests/Feature/ThemeScopingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Run;

it('persists a theme on a run, defaulting to space', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [],
    ]);
    expect($run->fresh()->theme)->toBe('space');
});

it('accepts an explicit theme', function () {
    $run = Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    expect($run->fresh()->theme)->toBe('island');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ThemeScopingTest`
Expected: FAIL — `theme` column missing / not fillable.

- [ ] **Step 3: Write the migration**

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
            $table->string('theme')->default('space')->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
```

- [ ] **Step 4: Add `theme` to `$fillable`**

In `backend/app/Models/Run.php`, add `'theme',` to the `$fillable` array (after `'seed'`).

- [ ] **Step 5: Migrate and run the test**

Run: `php artisan migrate && php artisan test --filter=ThemeScopingTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_11_000001_add_theme_to_runs_table.php app/Models/Run.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: runs carry a theme (default space)"
```

---

### Task 4: RunState carries theme

**Files:**
- Modify: `backend/app/Game/Engine/RunState.php`
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append to ThemeScopingTest.php)**

```php
it('RunState carries the run theme', function () {
    $run = App\Models\Run::create([
        'seed' => 1, 'rng_cursor' => 0, 'day' => 1, 'resources' => [],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);
    $state = App\Game\Engine\RunState::fromRun($run->fresh());
    expect($state->theme)->toBe('island');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="RunState carries"`
Expected: FAIL — `Undefined property ...::$theme` or unknown named arg.

- [ ] **Step 3: Add `theme` to the constructor**

In `RunState.php`, add to the constructor (after `deathLog`):

```php
        public array $deathLog = [],
        public string $theme = 'space',
```

- [ ] **Step 4: Populate it in `fromRun`**

In `RunState::fromRun`, add to the `new self(...)` call (after `deathLog:`):

```php
            deathLog: $run->death_log ?? [],
            theme: $run->theme ?? 'space',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ThemeScopingTest`
Expected: PASS (3 tests). Then `php artisan test` — full suite still green.

- [ ] **Step 6: Commit**

```bash
git add app/Game/Engine/RunState.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: RunState carries theme for engine-side resolution"
```

---

## Phase 3 — Events theme-scoped

### Task 5: Add `theme` column to events, scope uniqueness

**Files:**
- Create: `backend/database/migrations/2026_06_11_000002_add_theme_to_events_table.php`
- Modify: `backend/app/Models/Event.php` (fillable, if mass-assigned by seeders)
- Modify: seeders `ContentEventSeeder.php`, `EventSeeder.php`, `FillContentEventSeeder.php`
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('scopes event lookup by theme', function () {
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'shared_key', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    expect(App\Models\Event::where('theme', 'island')->where('key', 'shared_key')->first()->title)
        ->toBe('I');
    expect(App\Models\Event::where('theme', 'space')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="scopes event lookup"`
Expected: FAIL — duplicate `key` violates the existing `unique(key)`, or `theme` column missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('theme')->default('space')->after('key');
            $table->dropUnique('events_key_unique');
            $table->unique(['theme', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_theme_key_unique');
            $table->unique('key');
            $table->dropColumn('theme');
        });
    }
};
```

- [ ] **Step 4: Ensure `theme` is fillable on the Event model**

In `backend/app/Models/Event.php`, if there is a `$fillable` array, add `'theme',`. If the model uses `$guarded = []`, no change needed — note which and move on.

- [ ] **Step 5: Migrate and run the test**

Run: `php artisan migrate && php artisan test --filter="scopes event lookup"`
Expected: PASS.

- [ ] **Step 6: Tag existing seeders as space**

In each of `ContentEventSeeder.php`, `EventSeeder.php`, `FillContentEventSeeder.php`: wherever events are inserted (via `Event::create`, `insert`, `updateOrCreate`, or an array of rows), add `'theme' => 'space'` to every row. If rows are built in a loop from an array, add the key once in the builder.

Run: `php artisan migrate:fresh --seed`
Then verify: `php artisan tinker --execute="dump(App\Models\Event::where('theme','space')->count(), App\Models\Event::whereNull('theme')->count());"`
Expected: space count > 0, null count == 0.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (existing tests now run against space-tagged events).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_11_000002_add_theme_to_events_table.php app/Models/Event.php database/seeders/ContentEventSeeder.php database/seeders/EventSeeder.php database/seeders/FillContentEventSeeder.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: events scoped by (theme, key); existing content tagged space"
```

---

### Task 6: EventEngine filters events by run theme

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php` (lines ~51, ~62, ~66, ~116)
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('an island run never draws space events', function () {
    App\Models\Event::query()->delete();
    App\Models\Event::create([
        'key' => 'space_only', 'theme' => 'space', 'title' => 'S', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    App\Models\Event::create([
        'key' => 'island_only', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => false,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);

    $run = App\Models\Run::create([
        'seed' => 7, 'rng_cursor' => 0, 'day' => 1, 'resources' => ['morale' => 50],
        'status' => 'active', 'flags' => [], 'characters' => [],
        'relationships' => [], 'items' => [], 'systems' => [], 'theme' => 'island',
    ]);

    $card = app(App\Game\Engine\EventEngine::class)->currentCard($run->fresh());
    expect($card['event']->key)->toBe('island_only');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="never draws space events"`
Expected: FAIL — may draw `space_only` (no theme filter).

- [ ] **Step 3: Add the theme filter to every Event query**

In `EventEngine.php`:
- Line ~66: `$pool = Event::all();` → `$pool = Event::where('theme', $run->theme)->get();`
- Line ~51: `Event::where('key', $this->trust->mutinyEventKey())->first()` → `Event::where('theme', $run->theme)->where('key', $this->trust->mutinyEventKey())->first()`
- Line ~62: `Event::where('key', $run->current_event_key)->first()` → add `->where('theme', $run->theme)` before `->first()`
- Line ~116: `Event::where('key', $run->current_event_key)->firstOrFail()` → add `->where('theme', $run->theme)` before `->firstOrFail()`

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter="never draws space events"`
Expected: PASS. Then `php artisan test` — full suite green.

- [ ] **Step 5: Commit**

```bash
git add app/Game/Engine/EventEngine.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: EventEngine draws only events of the run's theme"
```

---

## Phase 4 — Migrate config call-sites to ThemeConfig

> Each task below converts a group of `config('game.X')` reads to theme-aware
> resolution. After every task, the back-compat shim still makes `config('game.X')`
> work, so the suite stays green throughout. The shim is removed only in the final task.

### Task 7: RunFactory reads from ThemeConfig and accepts a theme

**Files:**
- Modify: `backend/app/Game/RunFactory.php`
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('RunFactory initialises resources from the requested theme', function () {
    $factory = app(App\Game\RunFactory::class);
    $run = $factory->create(seed: 1, itemKeys: [], profile: null, theme: 'space');
    $expected = collect(config('themes.space.resources'))->map(fn ($d) => $d['start'])->all();
    expect($run->resources)->toBe($expected);
    expect($run->theme)->toBe('space');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="RunFactory initialises resources from the requested"`
Expected: FAIL — `create()` has no `theme` parameter.

- [ ] **Step 3: Inject ThemeConfig and add the theme parameter**

In `RunFactory.php`:

Add a constructor:

```php
    public function __construct(private readonly ThemeConfig $theme)
    {
    }
```

Add the import at the top: `use App\Game\ThemeConfig;`

Change the signature and body of `create`:

```php
    public function create(?int $seed = null, array $itemKeys = [], ?Profile $profile = null, string $theme = 'space'): Run
    {
        $seed ??= random_int(PHP_INT_MIN, PHP_INT_MAX);
        $cfg = $this->theme->for($theme);

        $resources = [];
        foreach ($cfg->get('resources') as $code => $def) {
            $resources[$code] = $def['start'];
        }

        return Run::create([
            'seed' => $seed,
            'theme' => $theme,
            'rng_cursor' => 0,
            'day' => 1,
            'resources' => $resources,
            'status' => 'active',
            'flags' => ['crew_trust' => 60],
            'characters' => $this->roster($cfg),
            'relationships' => [],
            'items' => $this->sanitiseItems($itemKeys, $profile, $cfg),
            'systems' => $this->systems($cfg),
            'profile_id' => $profile?->id,
        ]);
    }
```

Change the private helpers to take the bound config:

```php
    private function systems(ThemeConfig $cfg): array
    {
        $systems = [];
        foreach ($cfg->get('systems') as $key => $def) {
            $systems[$key] = ['efficiency' => $def['start']];
        }
        return $systems;
    }

    private function sanitiseItems(array $itemKeys, ?Profile $profile, ThemeConfig $cfg): array
    {
        $pick = (int) $cfg->get('items_pick');
        $available = $this->availableItemKeys($profile, $cfg);

        $valid = array_values(array_unique(array_filter(
            $itemKeys,
            fn ($k) => in_array($k, $available, true),
        )));

        return array_slice($valid, 0, $pick);
    }

    public function availableItemKeys(?Profile $profile, ?ThemeConfig $cfg = null): array
    {
        $cfg ??= $this->theme->for('space');
        $unlocked = $profile?->unlocks ?? [];
        $grantedBy = [];
        foreach ($cfg->get('unlocks') as $u) {
            if (isset($u['grants_item'])) {
                $grantedBy[$u['grants_item']] = $u['key'];
            }
        }

        $keys = [];
        foreach ($cfg->get('items') as $item) {
            if (! ($item['locked'] ?? false)) {
                $keys[] = $item['key'];
                continue;
            }
            $unlockKey = $grantedBy[$item['key']] ?? null;
            if ($unlockKey !== null && in_array($unlockKey, $unlocked, true)) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
    }

    private function roster(ThemeConfig $cfg): array
    {
        $roster = [];
        foreach ($cfg->get('roster') as $member) {
            $roster[] = [
                'name' => $member['name'],
                'role' => $member['role'],
                'traits' => $member['traits'] ?? [],
                'stress' => 0,
                'hunger' => 0,
                'away_until' => 0,
                'alive' => true,
            ];
        }
        return $roster;
    }
```

> Note: `availableItemKeys` keeps a nullable `$cfg` second arg defaulting to space so the existing `/api/items` caller (Task 11) keeps working until updated.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ThemeScopingTest`
Expected: PASS. Then `php artisan test` — full suite green (callers still pass no theme → defaults to space).

- [ ] **Step 5: Commit**

```bash
git add app/Game/RunFactory.php tests/Feature/ThemeScopingTest.php
git commit -m "refactor: RunFactory resolves starting state via ThemeConfig"
```

---

### Task 8: Three injected services resolve config per-call via RunState theme

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`, `HintService.php`, `OutcomeWeigher.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Feature/ThemeConfigTest.php` (append a focused unit test)

> These three are registered as singletons in `AppServiceProvider` with a frozen
> config array. We change them to hold `ThemeConfig` and resolve from
> `$state->theme` (EffectApplier) / a passed theme (HintService, OutcomeWeigher).

- [ ] **Step 1: Write the failing test (append to ThemeConfigTest.php)**

```php
it('EffectApplier clamps using the resource max of the state theme', function () {
    $applier = app(App\Game\Engine\EffectApplier::class);
    $state = new App\Game\Engine\RunState(
        day: 1, resources: ['morale' => 90], theme: 'space',
    );
    $rng = new App\Game\SeededRng(1);
    $applier->apply([['type' => 'resource', 'code' => 'morale', 'delta' => 1000]], $state, $rng);
    $max = config('themes.space.resources.morale.max');
    expect($state->resources['morale'])->toBe($max);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="clamps using the resource max"`
Expected: FAIL — `EffectApplier` constructor currently needs a `$resourceMeta` array; resolution via `$state->theme` not yet wired. (Exact failure depends on current binding; treat any red as expected.)

- [ ] **Step 3: Rewire EffectApplier to ThemeConfig**

In `EffectApplier.php`, change the constructor from `private readonly array $resourceMeta` to:

```php
    public function __construct(private readonly \App\Game\ThemeConfig $theme)
    {
    }
```

At line ~42 where it reads `$this->resourceMeta[$code]['max'] ?? 100`, replace with a lookup bound to the state theme. In `apply()` resolve once:

```php
    public function apply(array $effects, RunState $state, SeededRng $rng, array $context = []): void
    {
        $resourceMeta = $this->theme->for($state->theme)->get('resources', []);
        foreach ($effects as $effect) {
            $this->applyOne($effect, $state, $rng, $resourceMeta, $context);
        }
    }
```

Thread `$resourceMeta` into `applyOne` and use `$resourceMeta[$code]['max'] ?? 100` there. (Add `array $resourceMeta` to the `applyOne` signature.)

- [ ] **Step 4: Rewire HintService and OutcomeWeigher**

`OutcomeWeigher.__construct(private readonly array $traits)` → `__construct(private readonly \App\Game\ThemeConfig $theme)`. In `weights(array $outcomes, ?array $speaker, string $theme = 'space')`, resolve `$traits = $this->theme->for($theme)->get('traits', [])` at the top and use locally. Update the single caller in `EventEngine` to pass `$run->theme`.

`HintService.__construct(private readonly array $riskBands, private readonly array $traits)` → `__construct(private readonly \App\Game\ThemeConfig $theme)`. In `hintFor(array $choice, ?array $speaker, string $theme = 'space')`, resolve both `riskBands` and `traits` from the bound theme. Update the single caller in `EventEngine` to pass `$run->theme`.

> Find the callers: `grep -n "->weights(\|->hintFor(" app/Game/Engine/EventEngine.php` and add `$run->theme` as the new trailing arg.

- [ ] **Step 5: Update AppServiceProvider bindings**

In `AppServiceProvider.php` replace the three bindings:

```php
        $this->app->singleton(EffectApplier::class, fn ($app) => new EffectApplier($app->make(\App\Game\ThemeConfig::class)));
        $this->app->singleton(HintService::class, fn ($app) => new HintService($app->make(\App\Game\ThemeConfig::class)));
        $this->app->singleton(OutcomeWeigher::class, fn ($app) => new OutcomeWeigher($app->make(\App\Game\ThemeConfig::class)));
```

(If the originals used `config('game.resources')` etc. directly in the closures, those reads are now gone.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ThemeConfigTest`
Expected: PASS. Then `php artisan test` — full suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Game/Engine/EffectApplier.php app/Game/Engine/HintService.php app/Game/Engine/OutcomeWeigher.php app/Providers/AppServiceProvider.php tests/Feature/ThemeConfigTest.php
git commit -m "refactor: EffectApplier/HintService/OutcomeWeigher resolve config per theme"
```

---

### Task 9: Per-run engine readers use run/state theme

**Files:**
- Modify: `backend/app/Game/DayProcessor.php`, `EndingService.php`, `ExpeditionResolver.php`, `PhaseResolver.php`, `EpilogueComposer.php`, `EventEngine.php`
- Test: existing suite is the guard (no behavior change for space)

> Each of these reads `config('game.X')`. They all have a `Run` or `RunState`
> in scope at the read site. Inject `ThemeConfig` (constructor) and replace each
> read with `$this->theme->for($run->theme ?? $state->theme)->get('X', default)`.
> Do ONE file per sub-step, run the suite after each, commit per file.

- [ ] **Step 1: DayProcessor** — inject `ThemeConfig`. Replace lines ~54 (`resources`), ~140 (`systems`), ~171 (`hunger`), ~235 (`hardship`), ~263 (`stress_bands`) with `$this->theme->for($state->theme)->get('...')` (DayProcessor operates on a `RunState`/`Run` — confirm which is in scope and use its theme). Run `php artisan test`. Commit `refactor: DayProcessor reads config via run theme`.

- [ ] **Step 2: EndingService** — inject `ThemeConfig`. Lines ~41, ~62 (`endings`): replace with `$this->theme->for($run->theme)->get('endings')`. Run `php artisan test`. Commit `refactor: EndingService reads endings via run theme`.

- [ ] **Step 3: ExpeditionResolver** — inject `ThemeConfig`. Line ~60 (`relationships.expedition_risk`): `$this->theme->for($state->theme)->get('relationships.expedition_risk', 0)`. Run `php artisan test`. Commit `refactor: ExpeditionResolver reads risk via theme`.

- [ ] **Step 4: PhaseResolver** — inject `ThemeConfig`. Lines ~17, ~48, ~61 (`phases.*`). Note `PhaseResolver` is `new`'d directly in `RunState::fromRun` — pass theme to its methods instead of constructor, OR resolve `app(ThemeConfig::class)` inside. Prefer threading the theme into the `resolve(...)` call: `resolve(int $day, array $resources, string $floor, string $theme = 'space')`. Update the `RunState::fromRun` call to pass `$run->theme ?? 'space'`. Run `php artisan test`. Commit `refactor: PhaseResolver reads phases via theme`.

- [ ] **Step 5: EpilogueComposer** — inject `ThemeConfig`. Lines ~24, ~25, ~42, ~51, ~63 (`epilogue.*`): replace each with `$this->theme->for($run->theme)->get('epilogue.X', [])` (confirm `$run`/`$state` in scope). Run `php artisan test`. Commit `refactor: EpilogueComposer reads epilogue via theme`.

- [ ] **Step 6: EventEngine** — line ~90 (`death_notice_phrases.*`): `$this->theme->for($run->theme)->get('death_notice_phrases.' . ($entry['cause'] ?? 'event'), 'Se n\'è andato.')`. Inject `ThemeConfig` into the constructor (add to the existing DI list). Run `php artisan test`. Commit `refactor: EventEngine reads death phrases via theme`.

> After all six sub-steps: `php artisan test` MUST be fully green. No engine
> file should call `config('game.` anymore — verify with
> `grep -rn "config('game" app/Game/`. Expected: no matches.

---

### Task 10: Controllers accept and validate theme

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php` (lines ~29 validation, ~39 create, ~54/60 items, ~147 resources, ~234 endings, ~253 catalogue)
- Modify: `backend/app/Http/Controllers/MetaController.php` (lines ~40, ~67 unlocks)
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('POST /api/runs starts a run in the requested theme', function () {
    $res = $this->postJson('/api/runs', ['seed' => 5, 'theme' => 'island']);
    $res->assertCreated();
    expect(App\Models\Run::latest('id')->first()->theme)->toBe('island');
});

it('POST /api/runs rejects an unknown theme', function () {
    $this->postJson('/api/runs', ['seed' => 5, 'theme' => 'atlantis'])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="requested theme"`
Expected: FAIL — `theme` not validated/passed; unknown theme not rejected.

- [ ] **Step 3: Validate + pass theme in `store`**

In `RunController::store`, add to the validation array:

```php
            'theme' => ['nullable', 'string', 'in:space,island'],
```

And pass it to the factory:

```php
        $run = $this->factory->create($data['seed'] ?? null, $data['items'] ?? [], $profile, $data['theme'] ?? 'space');
```

- [ ] **Step 4: Theme-scope the other RunController reads**

Inject `ThemeConfig` into `RunController`'s constructor. For the run-bound reads (line ~147 `resources`, ~234 `endings`, ~253 `items` catalogue), use `$this->theme->for($run->theme)->get('...')`. For the GET `/api/items` pick screen (lines ~54, ~60), read the theme from the query string: `$theme = $request->query('theme', 'space');` then `$this->theme->for($theme)->get('items')` / `->get('items_pick')`, and pass `$theme` to `availableItemKeys` via a bound `ThemeConfig`.

- [ ] **Step 5: Theme-scope MetaController**

`MetaController` lines ~40, ~67 read `config('game.unlocks')`. Unlocks are profile meta-progression. For v1 keep unlocks space-only OR read `$request->query('theme', 'space')`. Inject `ThemeConfig`, resolve `->for($theme)->get('unlocks')`. Use `$request->query('theme', 'space')`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ThemeScopingTest`
Expected: PASS. Then `php artisan test` — full suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/RunController.php app/Http/Controllers/MetaController.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: API accepts and validates run theme (space|island)"
```

---

### Task 11: Simulator accepts --theme

**Files:**
- Modify: `backend/app/Game/Sim/Simulator.php` (`play` signature)
- Modify: the `sim:run` command (in `app/Console/Commands/`) — add `--theme` option
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('Simulator plays a run in the given theme', function () {
    App\Models\Event::query()->delete();
    App\Models\Event::create([
        'key' => 'island_filler', 'theme' => 'island', 'title' => 'I', 'body' => 'b',
        'base_weight' => 100, 'cooldown_days' => 0, 'is_filler' => true,
        'choices' => [['label' => 'ok', 'outcomes' => []]],
    ]);
    $sim = app(App\Game\Sim\Simulator::class);
    $result = $sim->play(seed: 3, policy: new App\Game\Sim\RandomPolicy(), items: [], maxDays: 5, theme: 'island');
    expect($result)->not->toBeNull();
    expect(App\Models\Run::latest('id')->first()->theme)->toBe('island');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="Simulator plays a run in the given theme"`
Expected: FAIL — `play()` has no `theme` parameter.

- [ ] **Step 3: Add theme to `Simulator::play`**

Change the signature to `public function play(int $seed, Policy $policy, array $items = [], int $maxDays = 80, string $theme = 'space'): SimResult` and the factory call to `$run = $this->factory->create($seed, $items, null, $theme);`.

- [ ] **Step 4: Add `--theme` to the sim command**

Find the command: `grep -rln "sim:run" app/Console/`. Add a `--theme=space` option to its signature, validate it is in `['space','island']`, and pass it through to `Simulator::play(...)` (and into the result aggregation loop).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="Simulator plays a run in the given theme"`
Expected: PASS. Then sanity-run: `php artisan sim:run --count=10 --policy=random --theme=space`
Expected: completes without error.

- [ ] **Step 6: Commit**

```bash
git add app/Game/Sim/Simulator.php app/Console/Commands/
git commit -m "feat: sim:run accepts --theme for per-theme balancing"
```

---

### Task 12: Remove the back-compat shim

**Files:**
- Delete: `backend/config/game.php`
- Verify: no `config('game.` remains anywhere

- [ ] **Step 1: Confirm no engine/seeder reads `config('game.`**

Run: `grep -rn "config('game" app/ database/`
Expected: NO matches. If any remain, convert them (per Task 9 pattern) before deleting the shim.

- [ ] **Step 2: Delete the shim**

```bash
git rm config/game.php
```

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS — all reads now go through `config('themes.*')` / `ThemeConfig`.

- [ ] **Step 4: Commit**

```bash
git commit -m "refactor: drop config/game.php back-compat shim (all reads theme-aware)"
```

---

## Phase 5 — Island content

### Task 13: Island config skeleton (water/food/fire/shelter/morale)

**Files:**
- Create: `backend/config/themes/island.php`
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append)

- [ ] **Step 1: Write the failing test (append)**

```php
it('island theme defines its five resources and three systems', function () {
    expect(array_keys(config('themes.island.resources')))
        ->toBe(['water', 'food', 'fire', 'shelter', 'morale']);
    expect(array_keys(config('themes.island.systems')))
        ->toBe(['water_still', 'signal_fire', 'shelter_frame']);
});

it('island morale is two-sided like space', function () {
    expect(config('themes.island.resources.morale.two_sided'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="island theme defines"`
Expected: FAIL — `config/themes/island.php` does not exist.

- [ ] **Step 3: Create the island config**

Create `config/themes/island.php` by copying the STRUCTURE of `themes/space.php` and substituting island keys/values. Resources block:

```php
    'resources' => [
        'water'   => ['max' => 100, 'start' => 100, 'daily' => 3, 'two_sided' => false],
        'food'    => ['max' => 100, 'start' => 100, 'daily' => 1, 'two_sided' => false],
        'fire'    => ['max' => 100, 'start' => 95,  'daily' => 3, 'two_sided' => false],
        'shelter' => ['max' => 100, 'start' => 100, 'daily' => 1, 'two_sided' => false],
        'morale'  => ['max' => 100, 'start' => 65,  'daily' => 2, 'two_sided' => true],
    ],
    'systems' => [
        'water_still'  => ['start' => 100, 'penalty' => ['resource' => 'water', 'delta' => -3]],
        'signal_fire'  => ['start' => 100, 'penalty' => ['resource' => 'fire', 'delta' => -3]],
        'shelter_frame'=> ['start' => 100, 'penalty' => ['resource' => 'shelter', 'delta' => -2]],
    ],
```

Then port every remaining top-level key that `space.php` has (`items`, `items_pick`, `roster`, `traits`, `risk_bands`, `hunger`, `hardship`, `stress_bands`, `phases`, `relationships`, `recruit_names`, `death_notice_phrases`, `endings`, `epilogue`, `unlocks`) with island-appropriate Italian text and the resource keys above. Death conditions in `endings` must reference `water`/`fire`/`shelter` instead of `oxygen`/`power`/`hull`. Keep `morale` two-sided death conditions. Use the space file as the exact template for shape; only data/text differs.

> This is the largest content task. Keep it runnable: a minimal-but-complete
> island config that passes the simulator. Rich event content comes in Task 14.

- [ ] **Step 4: Run tests + a smoke sim**

Run: `php artisan test --filter="island theme"`
Expected: PASS.
Then seed a minimal island filler event (Task 14 expands it) is NOT yet required for config tests.

- [ ] **Step 5: Commit**

```bash
git add config/themes/island.php tests/Feature/ThemeScopingTest.php
git commit -m "feat: island theme config skeleton (water/food/fire/shelter/morale)"
```

---

### Task 14: IslandEventSeeder + rescue chain

**Files:**
- Create: `backend/database/seeders/IslandEventSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php` (register the new seeder)
- Test: `backend/tests/Feature/ThemeScopingTest.php` (append a sim-survives test)

- [ ] **Step 1: Write the failing test (append)**

```php
it('an island run can be played to a conclusion by the sim', function () {
    $this->artisan('migrate:fresh --seed')->run();
    $sim = app(App\Game\Sim\Simulator::class);
    $result = $sim->play(seed: 42, policy: new App\Game\Sim\GreedySurvivalPolicy(), items: [], maxDays: 80, theme: 'island');
    expect($result->days)->toBeGreaterThan(1);
});
```

(Adjust `$result->days` to the actual SimResult accessor — `grep -n "public" app/Game/Sim/SimResult.php`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="island run can be played"`
Expected: FAIL — no island events seeded, run stalls immediately.

- [ ] **Step 3: Write IslandEventSeeder**

Model it on `ContentEventSeeder.php`. Every `Event::create`/row MUST include `'theme' => 'island'`. Provide:
- Enough filler + crisis events that a run does not stall (mirror the space event count density loosely; start with ~20–30 events, expand during balancing).
- A **rescue chain** mirroring the space escape chain (`grep -n "escape_" config/themes/space.php` and the chain events in `ContentEventSeeder.php` for the exact pattern). Stage flags, island-named: e.g. `rescue_signal_built`, `rescue_raft_ready`, `rescue_launched`. The terminal flag (`rescue_launched`) is what the island `win_rescue`-equivalent ending gates on — define that ending in `config/themes/island.php` `endings`, and the witness/outcome lines in its `epilogue` block (mirror `escape_outcome_lines`).

> The rescue chain is the island's identity. Reuse the proven escape-chain
> mechanics exactly — only keys and Italian text differ. No engine changes.

- [ ] **Step 4: Register the seeder**

In `DatabaseSeeder.php`, call `$this->call(IslandEventSeeder::class);` alongside the existing space seeders.

- [ ] **Step 5: Run tests**

Run: `php artisan migrate:fresh --seed && php artisan test --filter="island run can be played"`
Expected: PASS. Then `php artisan test` — full suite green.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/IslandEventSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/ThemeScopingTest.php config/themes/island.php
git commit -m "feat: island events + rescue chain (mirrors escape-chain pattern)"
```

---

### Task 15: Balance the island theme

**Files:**
- Modify: `backend/config/themes/island.php`, `backend/database/seeders/IslandEventSeeder.php` (data only)

- [ ] **Step 1: Run the auto-player on the island theme**

```bash
php artisan sim:run --count=5000 --policy=greedy_survival --theme=island
php artisan sim:run --count=5000 --policy=random --theme=island
```

- [ ] **Step 2: Compare the survival/death distribution to the space theme**

Run the same two commands with `--theme=space`. The island death-vs-win curve should be in the same ballpark (most early runs end in death, losses come from accumulated decisions). Adjust ONLY data (`daily` drains, `start` values, event weights, chain gate thresholds) in `config/themes/island.php` and `IslandEventSeeder.php`. Never touch the engine.

- [ ] **Step 3: Re-run until balanced, then commit**

```bash
git add config/themes/island.php database/seeders/IslandEventSeeder.php
git commit -m "balance: island theme tuned via sim auto-player"
```

---

## Phase 6 — Frontend theme picker

### Task 16: New-run screen theme selection

**Files:**
- Modify: the new-run/start component in `frontend/src/` (the screen that POSTs to `/api/runs`)
- Modify: the API client that builds the start-run request
- Test: `frontend/src/**/__tests__` (Vitest + Testing Library) for the picker

- [ ] **Step 1: Locate the start-run flow**

```bash
cd frontend
grep -rn "api/runs\|/runs\|startRun\|newRun" src/ | head
```

Identify the component rendering the start screen and the function issuing the POST.

- [ ] **Step 2: Write the failing test**

In the start component's test file, assert the user can choose a theme and that choosing "Isola" includes `theme: 'island'` in the start-run request. Mock the API client; render the component; select Isola; click start; assert the client was called with `theme: 'island'`. (Match the existing test style in that folder.)

- [ ] **Step 3: Run test to verify it fails**

Run: `npm run test -- <picker test file>`
Expected: FAIL — no theme control exists.

- [ ] **Step 4: Add the picker UI + thread theme into the request**

Add a Spazio / Isola selector (default Spazio) to the start screen. Pass the chosen theme into the start-run API call body as `theme`. The item pick screen must fetch items for the chosen theme (`GET /api/items?theme=<theme>`).

- [ ] **Step 5: Run test to verify it passes**

Run: `npm run test`
Expected: PASS.

- [ ] **Step 6: Manual smoke**

```bash
# backend: php artisan serve   frontend: npm run dev
```
Start a run as Isola, confirm island resources (acqua/cibo/fuoco/riparo/morale) render and island events appear.

- [ ] **Step 7: Commit**

```bash
git add frontend/src
git commit -m "feat: new-run theme picker (Spazio | Isola)"
```

---

## Final verification

- [ ] `cd backend && php artisan test` — all green (existing 271 + new theme tests).
- [ ] `grep -rn "config('game" backend/app backend/database` — NO matches (shim gone, all theme-aware).
- [ ] `php artisan sim:run --count=2000 --policy=greedy_survival --theme=space` — healthy distribution.
- [ ] `php artisan sim:run --count=2000 --policy=greedy_survival --theme=island` — healthy distribution.
- [ ] `cd frontend && npm run test` — all green.
- [ ] Manual: start a Spazio run (unchanged behavior) and an Isola run (island content) end-to-end.

---

## Notes for the implementing engineer

- **Why the shim (Task 1) and its removal (Task 12):** it lets every call-site migrate one at a time with the full suite green between steps, instead of one giant breaking change. Don't skip removing it — a lingering `config('game.X')` silently always reads space.
- **`RunState.theme` is the spine.** Engine services that can't be theme-scoped at construction (the three singletons) read the theme off the `RunState`/`Run` they're already handed.
- **Language separation (from README):** keys/flags/identifiers in English (`water`, `rescue_launched`), all player-facing text in Italian. The island content must honor this.
- **Content is data.** Tasks 13–15 add ZERO engine code. If you find yourself editing `app/Game/` to make island work, stop — the seam is missing, not the content.
- **Death conditions** in the island `endings` block must reference island resource keys (`water`/`fire`/`shelter`), or runs will never die from those resources.
