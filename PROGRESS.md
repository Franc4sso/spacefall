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
- [x] **Phase 6 — Endings & fair-failure cascade**
- [x] **Phase 7 — Meta progression & cross-run memory**
- [x] **Phase 8 — Content pass (50+ events, Italian)**
- [x] **Phase 9 — Frontend cadence + flow**
- [x] **Phase 10 — Balance via simulation**

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

## Phase 6 — Endings & fair-failure cascade ✅

- **11 data-driven endings** in `config('game.endings')`: each a `when` Condition (same DSL),
  `type` (win|lose), Italian name + epilogue. 6 deaths (oxygen/hull/food/power/morale=0 + the
  two-sided **morale=100 recklessness** death) and 5 wins (Escape/Rescue/Colony/Research/Sacrifice).
  Adding an ending is one config entry.
- **Two-sided danger:** `morale` kills at BOTH ends — 0 (breakdown) and 100 (fatal euphoria),
  satisfying design §1.5.
- **`EndingService`** — evaluates endings in config order (deaths first, so a lethal state
  pre-empts a simultaneous win), marks the run `ended` + records `ending_key`/`ending_type`,
  clears the pinned card. Reuses the total ConditionEvaluator.
- **Wired** into `EventEngine::resolveChoice` (effects may be lethal/winning) and
  `DayProcessor::advance` (the day's drain may cross a threshold). Both no-op on an ended run;
  `currentCard` shows no card once ended. API surfaces the ending object for the game-over screen.
- **Win-enabling content:** seeded `research_breakthrough` (scanner+time → `research_complete`) and
  `the_sacrifice` (→ `made_the_sacrifice`) so those wins are reachable through play, not just by flag.
- **Tested (100 total):** each of the 11 endings driven and asserted; lethal pre-empts win;
  ended run stops the loop + card flow; **the fair-failure cascade** — an ignored `power_flicker`
  schedules `power_cascade` for a *future* day (traceable), which fires as the forced card and, with
  low reserves, drives oxygen toward a death caused by the earlier choice.

**DoD met:** tests drive a run into each ending; a test proves an ignored fault cascades via
`spawn_event` into a later disaster.

## Phase 7 — Meta progression & cross-run memory ✅

- **`Profile` model + table** (`handle`, `research_points`, `unlocks`, `flags`). Resolve-or-create
  by handle (no auth scaffolding — out of scope). Runs link via nullable `profile_id`.
- **Real profile-scoped flag store (the signature feature):** `RunState::fromRun` loads
  `profileFlags` from the linked profile, so a `flag … scope:profile` condition sees what *earlier
  runs* left behind. `ProfileSync` flushes mutated profile flags back after every resolution.
  Seeded `reactor_gamble` (sets profile flag) → `old_scorch` (requires it) demonstrates a callback
  spanning two separate runs.
- **Research points earned even on loss:** the EffectApplier accumulates `grant_research_points`
  into a transient accumulator; `ProfileSync` moves the delta into `profile.research_points` on each
  flush — so points are banked the moment they're earned, surviving a later death.
- **Unlocks = content, not boosts (design §2.1):** `config('game.unlocks')` each `grants_item` a
  *locked* catalogue item. `RunFactory::availableItemKeys` is the single source of truth: locked
  items are pickable only once their unlock is owned, and `/api/items` filters to it. An unlock
  changes the *next run's hand of cards*, not its numbers.
- **Endpoints:** `GET /api/meta` (points, owned + buyable unlocks with affordability),
  `POST /api/meta/unlock` (spend points). `POST /api/runs` / `GET /api/items` take an optional
  `handle`.
- **Tested (107 total):** meta exposed; points accrue to profile on a granting event; points
  survive a loss; unlock spends + records; unaffordable unlock refused; **an unlock changes the next
  run's pickable items**; **a profile flag set in one run is read by a later run's condition** (and
  the callback event surfaces in run 2).

**DoD met:** points accrue (incl. on loss); an unlock changes the next run's options; a profile flag
set in one run is readable by a condition in a later run.

## Phase 8 — Content pass ✅

- **53 events total** (47 themed + 6 filler). New `ContentEventSeeder` (~38 events) keeps the core
  `EventSeeder` as the stable test fixture; both validated by `EventSchema` at seed time.
- **Italian, Reigns × 60 Seconds voice:** short, dry, graspable in a couple of seconds. English
  keys/flags throughout. Female-safe phrasing (no gender-agreement traps).
- **Variety across every trigger family:** resource thresholds, system efficiency, items (open
  routes), traits (`coward` freeze, `genius` idea), roles (doctor/engineer), relationship bands
  (hatred sabotage, bond, tension), run-flag callbacks (promise → broken promise), **profile-flag
  callbacks** (`blew_a_reactor` → déjà-vu across runs), and `spawn_event` consequence chains
  (ignored creak → paratia cede; ignored life-support → aria viziata; sensor ghost → real threat).
- Speakers (Anna/Bex/Cole) attached so computed hints are trait-distorted in play.
- **Tested (114 total):** ≥50 events; filler pool present; **every event validates against the DSL
  schema**; every event has non-empty Italian text + well-formed choices/outcomes; keys unique;
  **the Selector never stalls against the full 53-event pool over 2000 random states**; coverage
  guard asserts at least one event on each trigger surface (resource/system/item/relationship/
  profile-flag/spawn).

**DoD met:** ≥50 events exist and every event's requires/choices validate against the DSL schema.

## Phase 9 — Frontend cadence + flow ✅

- **Full card UI** in the failing-terminal aesthetic (§2.2): phosphor-green on black, CRT scanlines
  + vignette + flicker, red alarm pulses, glitch on the game-over title — all CSS/SVG, no heavy
  assets. Distinctive monospace (JetBrains Mono).
- **Layout:** day on top, resource bars left, card centre, crew right, inventory bottom (stacks on
  small screens).
- **Flow (§1.5) — the priority:**
  - **No mid-run round-trip wait:** the choice POST returns the *next* state (card included) in the
    same response, so resolving and "prefetching the next card" are one request — there is never a
    second fetch to wait on. The swipe animates optimistically while it's in flight.
  - **Animations are CSS-only and interruptible** (card-in, tilt, pulse, jolt) — they never gate
    input; a fast player taps again immediately.
  - **No loading state mid-run;** **two-tap restart** after death (ANCORA → DISTACCO).
- **Micro-feedback:** card tilts toward the drag with a left/right "tell" preview (Reigns binary
  swipe), resource bars pulse red when critical (oxygen/low + two-sided morale at max), the screen
  desaturates + flickers harder as `decay` rises toward game over, death jolt on the ending screen.
- **Start screen:** the pick-5 (filtered to the profile's unlocked items), then DISTACCO.
- **`useRun` hook** owns the server-authoritative state; `useHandle` persists a per-browser handle
  → the profile carrying cross-run memory + unlocks (no login, out of scope).
- **Tested:** 5 RTL tests — start screen renders picks; starting a run shows the first card; a
  choice advances to the next card with no spinner; the game-over screen shows the ending + restart;
  an item-gated unavailable choice is disabled. Build typechecks; SPA serves and talks to the API
  (verified live, CORS ok).

**DoD met:** RTL tests on the card flow. *(Manual "zero perceptible wait" playthrough is a
human check — the architecture removes the mid-run round-trip that would cause waits; no headless
browser is installed to automate the visual pass.)*

## Phase 10 — Balance via simulation ✅

- **Headless auto-player** (`Simulator`) plays full seeded runs under a pluggable **`Policy`**
  (`RandomPolicy`, `GreedySurvivalPolicy` — picks the choice whose visible *hint* sounds safest,
  reading only what a human sees). Records the decision trail per run.
- **`php artisan sim:run --count=N --policy=… --items=…`** prints the aggregate: run-length
  distribution, win/loss rate, endings reached; fails loudly if any run stalls.
- **`FairnessProbe`** — the operational "no unavoidable death" check: for a death caused by a
  *choice*, it re-plays from the seed to the final decision and verifies at least one alternative
  available choice did NOT lock in the death. Deaths caused by the slow daily drain are fair by
  construction (avoidable over the preceding days).
- **Balance tests (5, all green):** never stalls; **hard but not impossible** (greedy win-rate
  > random, greedy can win); run length in a sane band; **no unavoidable choice-deaths**; every
  ending reachable across policies/seeds (no dead content).
- **Tuning (DATA only — config + content, never engine):** gentler daily drains + slower system
  decay so no resource bottoms out before the player has had real decisions; raised win thresholds
  (rescue/research/sacrifice) so they're earned, not stumbled into; safe-but-good choices given
  reassuring hints so following the hints rewards survival *without making it automatic*; capped the
  one card (`c_mold`) that could starve you regardless of choice. Result on 60-run batches:
  **greedy ~28% win (median ~26 days), random ~18% (median ~22)** — informed play wins meaningfully
  more, the game stays hard, short runs happen only to careless play.

**DoD met:** the section-5 balance assertions pass.

## Status: ALL 10 PHASES COMPLETE — 119 backend + 5 frontend tests green.

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
- **Flow "prefetch" (Phase 9):** rather than a separate prefetch request, the choice-resolution POST
  already returns the next card in its response (the API computes it server-side). So the swipe needs
  no second round-trip — the next card is in hand the instant the one request returns. The UI animates
  the swipe optimistically over that single request. This satisfies the §1.5 "prefetch the next card"
  intent with one call instead of two.
- **Dev ports:** other local projects occupy 8000/5173–5175, so this build runs the API on **8010**
  and Vite on **5176** (`frontend/.env` sets `VITE_API_URL`, backend `.env` sets `CORS_ALLOWED_ORIGINS`).
- **Hints help, but not too much (user steer, Phase 10):** hints are never numbers and are distorted
  by the speaker's trait (Coward inflates, Optimist downplays), so they orient without being reliable.
  Balance aligns safe-sounding hints with genuinely-good choices just enough that a hint-following
  player survives more than random (greedy 28% vs 18%) — deliberately NOT so much that the game plays
  itself. Short runs are allowed but only result from careless play (no hard length floor).

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
