# Starfall Station — Progress

Mono-repo: `backend/` (Laravel 12, PHP 8.2) + `frontend/` (React 19 + Vite 8 + TS, Tailwind 4).
Design + resolved forks: `docs/superpowers/specs/2026-06-04-starfall-station-design.md`.

## Phase checklist

- [x] **Phase 0 — Foundations**
- [x] **Phase 1 — Run state & day counter**
- [x] **Phase 2 — Event Engine** (DSL reviewed & approved at the gate)
- [x] **Phase 3 — Characters, traits, stress, relationships**
- [x] **Phase 4 — Items**
- [x] **Phase 5 — Daily loop assembled + rationing**
- [ ] Phase 6 — Endings & fair-failure cascade
- [ ] Phase 7 — Meta progression & cross-run memory
- [ ] Phase 8 — Content pass (50+ events, Italian)
- [ ] Phase 9 — Frontend cadence + flow
- [ ] Phase 10 — Balance via simulation

> **Stopping here for review** (user choice): plan + DSL design reviewed before Phase 2 code,
> as the build prompt requires.

## Phase 0 — Foundations ✅

- Laravel 12.61 scaffolded in `backend/` (PHP `^8.2`). `install:api` for `routes/api.php`.
- Pest 3.8 installed (PHPUnit pinned to `^11.5` to satisfy Pest's conflict). `RefreshDatabase`
  enabled for Feature tests in `tests/Pest.php`.
- `GET /api/health` → `{status, service}`.
- CORS published (`config/cors.php`), origins from `CORS_ALLOWED_ORIGINS`
  (default `http://localhost:5173,http://127.0.0.1:5173`).
- Frontend: Vite + React-TS, Tailwind 4 (`@tailwindcss/postcss`), phosphor-terminal theme tokens
  in `src/index.css`. `src/api.ts` thin client (`VITE_API_URL`, default `http://localhost:8000/api`).
  `App.tsx` renders health status in Italian. Vitest + RTL + jsdom configured.
- **Tested:** `php artisan test` → 15 passed. `npm run test` → 3 passed. `npm run build` clean.
  Live smoke through both endpoints confirmed.

**DoD met:** both suites green; build typechecks; health string renders.

## Phase 1 — Run state & day counter ✅

- `runs` migration: `seed`, `rng_cursor`, `day`, `resources` (JSON), `status`. Resources in JSON
  (not 5 columns) so adding a resource is a `config/game.php` edit, not a migration. MySQL-compatible.
- `config/game.php` — the five resources with `max/start/daily/two_sided`. `morale` is `two_sided`
  (max is also dangerous; consequences arrive Phase 6).
- `App\Game\SeededRng` — deterministic PRNG. Draw = SHA-256 of `"seed:cursor"` → 52 bits → double.
  Cursor is monotonic and persisted on the Run, so reload-mid-run never desyncs. Chosen over a
  hand-rolled 64-bit mix because PHP ints overflow to float and silently break determinism; SHA-256
  keeps every value inside the safe integer range and is stable across PHP versions/platforms.
- `App\Game\RunFactory` — starts runs from config (no hard-coded resource names).
- `App\Game\DayProcessor` — end-of-day: subtract `daily`, clamp `[0, max]`, advance day. One method
  per day; Phase 5 thickens this same pipeline.
- `RunController` + routes: `POST /api/runs`, `GET /api/runs/{run}`, `POST /api/runs/{run}/advance`.
  Response includes `resource_meta` (max + two_sided) so the thin client renders without tuning numbers.
- **Tested:** SeededRng unit (reproducibility, cursor-resume, bounds, weighting). Run feature (start,
  fetch, deterministic consumption, zero-clamp, identical trajectory per seed).

**DoD met:** feature tests assert deterministic consumption for a fixed seed.

## Phase 2 — Event Engine ✅

- **`events` table** (one JSON row per event: `requires`, `choices`→`outcomes`→`effects`,
  `base_weight`, `cooldown_days`, `is_filler`). Runs gained engine state: `flags`,
  `recent_events` (cooldowns), `scheduled_events` (delayed), `current_event_key` (pinned card).
- **`RunState`** — plain mutable snapshot decoupling the pure services from Eloquent. Carries
  later-phase fields (characters/items/systems/relationships) as empty defaults so conditions are
  *total* before that content exists.
- **`ConditionEvaluator`** — pure, total. all/any/not + resource/day/flag(run|profile)/has_item/
  has_role/trait_present/relationship-band/system. Unknown kinds & bad ops fail closed, never throw.
- **`EffectApplier`** — every effect type. Resource deltas clamp `[0,max]` (gate decision; death is
  a separate condition). Character targeting (`random`/`highest_stress`/`lowest_loyalty`/`<name>`)
  draws from SeededRng → deterministic. Relationships symmetric & clamped `[-100,100]`.
- **`Selector`** — **always returns a card.** Order: scheduled-due (forced, ignores requires) →
  eligible themed (requires ✓, not on cooldown) → filler → last-resort filler → any event. Empty
  pool throws (real misconfig); `EventEngine` degrades to no-card when the table is truly empty.
- **`EventEngine`** — orchestration over a persisted Run: `currentCard` (picks + pins, advances
  cursor once, reload-stable) and `resolveChoice` (weighted outcome branch → apply → record
  cooldown → consume schedule → unpin). Only engine class touching persistence.
- **`EventSchema`** — seed-time structural validator; malformed content fails the seeder loudly.
- **`EventSeeder`** — 5 themed + 2 filler events, **Italian** copy / English keys. Exercises
  flag callback (`vented_the_technician` → `technician_ghost`), spawn_event chain
  (`power_flicker` → `power_cascade`), multi-branch weighted outcomes, choice hints.
- **Endpoints:** `GET /runs/{id}/card`, `POST /runs/{id}/choices`. Every run response carries the
  current card (one round-trip; flow §1.5).
- **Tested:** 57 backend assertions across evaluator (every condition + nesting + totality),
  applier (every effect + determinism), Selector (deterministic pick, scheduled priority, cooldown,
  **3000-state fuzz → always a card**, last-resort), engine playthrough (12 days no stall, flag
  callback, spawn chain), HTTP. Live smoke confirmed card variety + flag memory firing.

**DoD met:** heavy evaluator/applier unit tests; Selector fuzz never empty; multi-day real-event
feature test; ~5 events seeded.

## Phase 3 — Characters, traits, stress, relationships ✅

- **Run columns** `characters` + `relationships` (JSON). `RunState`/`RunFactory` load/seed/save them.
  Roster comes from `config('game.roster')` (Anna/genius, Bex/optimist, Cole/coward by default).
- **Traits = data** (`config('game.traits')`): `hint_bias` (reliable/inflate/downplay) +
  `luck_shift`. No trait name hard-coded anywhere in code.
- **`HintService`** — the signature feature. Computes a choice's *true* risk band from its outcome
  spread (probability-weighted resource loss), then the **speaker's trait distorts** which band's
  Italian phrase is shown: Genius reliable, Coward/Paranoid inflate, Optimist downplay. Author-written
  hints override. The player never sees a number.
- **`OutcomeWeigher`** — `luck_shift` reweights outcome branches by the speaker's traits (good vs bad
  branch classified by net resource effect). Lucky tilts toward good outcomes, Reckless toward bad —
  shifts the realised distribution over many seeds, zero per-event authoring.
- **Weight modifiers** — events gained an optional `weight_modifiers: [{when: Condition, factor}]`.
  Selector multiplies base_weight by each factor whose condition holds, via the *same* Condition DSL
  (a trait, resource, flag, relationship… can all bias selection). Generic, not trait-only.
- **Stress bands** (`config('game.stress_bands')`) — `DayProcessor` schedules a survivor's
  self-initiated event when their stress crosses INTO a higher band (tracked per-survivor via
  `stress_band` so it fires on entry, not daily). Fires through the normal scheduled-event path.
- **Content:** seeded `survivor_strained` / `survivor_breaks` (scheduled-only) so stress behaviour
  has events to fire.
- **API** now surfaces the living roster (name/role/traits/stress/alive) for the Phase 9 panel.
- **Tested (71 total):** Coward hint ≠ Genius hint for the same risk; Optimist downplays; author
  override; luck_shift changes outcome distribution over 2000 seeds (Lucky > neutral > Reckless on
  good-branch rate); weight modifier biases selection only when its condition holds; stress crossing
  schedules behaviour and doesn't double-fire; roster seeded + surfaced.

**DoD met:** Coward vs Genius hint differs for the same risk; a trait changes an outcome
distribution over many seeds.

## Phase 4 — Items ✅

- **20 items** in `config('game.items')` (English key, Italian name/description). `items_pick = 5`.
  Pure data — what an item *does* lives entirely in the events that gate on it; no item code.
- **Run column** `items` (JSON list of keys). `RunState`/`RunFactory` load/save it.
- **`RunFactory::create($seed, $itemKeys)`** sanitises the pick: drops unknown keys, de-dupes,
  caps at `items_pick`. Single gate, so the engine trusts `has_item` blindly.
- **Items gate CHOICES, not stats** — via the existing `has_item` condition in a choice's
  `requires`. Seeded `hull_breach`: the "Saldo la breccia" choice is available only with the
  `welder`, giving a different route per pick-5 (design §2.1).
- **Endpoints:** `GET /api/items` (catalogue + pick count for the start screen);
  `POST /api/runs` accepts `items[]`. Run state surfaces inventory as full item objects.
- **Tested (76 total):** catalogue/pick exposed; pick sanitised (unknown dropped, deduped, capped);
  item-gated choice available only when held; resolving a gated choice without the item is refused;
  inventory carried through the API. *(Two earlier tests that hard-coded choice 0 were made to pick
  the first available choice — correct real-play behaviour, since some cards now gate choice 0.)*

**DoD met:** tests prove an item-gated choice is present/absent based on inventory.

## Phase 5 — Daily loop assembled + rationing ✅

- **Station systems** (`config('game.systems')`: life_support, power_grid, hull_integrity). Run
  column `systems` = `{key: {efficiency}}`. Initialised by RunFactory, loaded/saved via RunState
  (so `damage_system` effects persist), surfaced in the API.
- **Full end-of-day pipeline** in `DayProcessor`, ordered & clamped:
  1. resource consumption → 2. system degradation + below-threshold resource penalty (failing
  life-support bleeds oxygen — neglect compounds) → 3. hardship stress (scarce resource → whole crew
  gains stress) → 4. stress-band self-initiated behaviour → 5. advance day. All config-driven.
- **Rationing primitive:** added `character: "all"` to EffectApplier — one swipe hits every living
  survivor, weighing *more* with a bigger crew (the 60 Seconds weight). Seeded `ration_crisis`
  (appears when food < 30): split fairly (food cost), tighten belts (stress to all), or eat alone
  (morale crash + `ate_alone` flag for a later callback).
- **Tested (85 total):** systems init; degrade + penalty math; hardship stress to all; **15-day
  end-to-end playthrough stays internally consistent** (every resource/efficiency/stress in range,
  never stalls, day = 16); **same seed + same choices → identical 15-day end state** (reproducibility
  contract holds across the whole loop); rationing appears only when food scarce; "tighten belts"
  stress scales with crew size; "eat alone" crashes morale + sets flag.

**DoD met:** a feature test plays ~15 days end to end and the state stays internally consistent.

## Decisions / assumptions

- **PHP 8.2** (env has 8.2.31; spec asked 8.3+). Laravel 12 supports 8.2. No 8.3-only syntax. *(user-approved)*
- **Mono-repo** layout, backend API ↔ frontend SPA separated. *(user-approved)*
- **React 19 / Vite 8 / Tailwind 4** — the current `npm create vite` defaults. Spec pinned React 18 +
  Tailwind 3. Deviation taken to avoid dependency friction with the current toolchain; React 19 is
  backward-compatible for our usage and RTL 16 supports it. **Flag for review at the gate.**
- **PHPUnit downgraded to `^11.5`** to satisfy Pest 3.8's conflict with `>11.5.50`.
- **RNG = SHA-256 per (seed, cursor)** rather than SplitMix64, for cross-platform determinism (see above).
- **Dev DB** is the committed-ignored `database/database.sqlite`; run `php artisan migrate` after clone.
- Resource values stored as a JSON map keyed by config codes; death/two-sided *consequences* are Phase 6,
  Phase 1 only does flat consumption + clamping.
- **Scheduled-only events convention (Phase 2):** an event meant to fire *only* when spawned (e.g.
  `power_cascade`) is gated with `requires: {flag: "__scheduled_only", is: true}` — a flag never set.
  Normal selection skips it; the Selector's scheduled-due branch force-picks it by key ignoring
  `requires`, so `spawn_event` still fires it. No engine special-case — pure data.
- **`grant_research_points`** stashes into `profileFlags['__research_points']` for now; real
  profile-scoped meta currency persistence lands in Phase 7.

## DSL design — FOR REVIEW BEFORE PHASE 2

See `docs/superpowers/specs/2026-06-04-starfall-station-design.md` §8. Summary:

- **Storage:** one `events` row per event; `requires` and `choices` (choices embed `outcomes`→`effects`)
  as JSON columns. One row = one whole event ⇒ "new event = new seeder row" (Prime Directive #1).
  A seeder-time schema validator rejects malformed rows loudly.
- **Three services:** Selector (always returns a card via the guaranteed filler pool), Condition
  Evaluator (pure/total), Effect Applier (pure given a seed).
- **Open questions to confirm:** comparison `op` set; clamp-vs-overshoot for resource effects (proposed:
  clamp `[0,max]`, death is a separate `=0` condition); cooldown tracking via `recent_events` map;
  delayed events via per-run `{key, fire_on_day}` queue.

### How to run

```
# backend
cd backend && composer install && php artisan migrate && php artisan test
php artisan serve            # http://localhost:8000

# frontend
cd frontend && npm install && npm run test
npm run dev                  # http://localhost:5173  (set VITE_API_URL if API not on :8000)
```
