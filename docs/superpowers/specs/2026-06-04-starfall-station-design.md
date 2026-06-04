# Starfall Station — Design (resolved decisions)

> The full constitution is the build prompt pasted by the user. This file records only
> the **forks that were open** and how they were resolved, plus the DSL design to review
> before Phase 2. Everything not contradicted here defers to the original prompt.

Date: 2026-06-04

## 1. Resolved environment forks

| Fork | Decision | Reason |
|------|----------|--------|
| PHP version | **PHP 8.2** (target `^8.2` in `composer.json`) | Local env has 8.2.31; Laravel 12 supports 8.2. Avoids blocking on a manual PHP upgrade. No 8.3-only syntax used. |
| Repo layout | **Mono-repo** `starfall-station/` with `/backend` (Laravel 12) + `/frontend` (React 18 + Vite + TS) | Single project, one git, one `PROGRESS.md`. Keeps the "thin renderer ↔ authoritative API" split clean (two dirs, not Vite-in-Laravel). |
| Build checkpoint | **Stop after Phase 1.** Deliver Phase 0 + Phase 1 + full DSL design, then wait for review before Phase 2 (the engine). | The prompt explicitly asks to review the plan + DSL before Phase 2. |

## 2. Stack (pinned, unchanged from prompt)

- Backend: Laravel 12, PHP 8.2, Pest, REST/JSON API.
- Frontend: React 18 + TypeScript + Vite, Tailwind, Vitest + React Testing Library.
- DB: SQLite for dev/test, schema kept MySQL-compatible.

## 3. Repo shape

```
starfall-station/
  backend/          Laravel 12 app (API only)
  frontend/         React + Vite SPA
  docs/             specs
  PROGRESS.md       phase checklist + running log (repo root)
```

## 4. CORS / wiring

Backend exposes `/api/*`. Vite dev server (5173) calls backend (8000). CORS allows the Vite
origin. Frontend keeps API base URL in an env var (`VITE_API_URL`).

## 5. Phase plan (checklist lives in PROGRESS.md)

Phases 0–10 exactly as the prompt defines them. This delivery covers **Phase 0 + Phase 1**,
then stops at the pre-Phase-2 review gate.

- **Phase 0** — scaffold both sides, CORS, Pest + Vitest each with one passing test, one
  `/api/health` endpoint the frontend renders. DoD: `php artisan test` + `npm run test` green;
  `npm run dev` shows the health string.
- **Phase 1** — `Run` model: 5 resources + day counter + per-run seed (seeded RNG). Endpoints
  `POST /api/runs`, `GET /api/runs/{id}`. End-of-day consumption. DoD: feature tests assert
  deterministic consumption for a fixed seed.

## 6. The five resources (Phase 1 baseline; tunable later)

Codes are English; player-facing labels are Italian (rendered in frontend).

| code | IT label | zero = | max danger? |
|------|----------|--------|-------------|
| `oxygen` | Ossigeno | death | no |
| `food` | Cibo | starvation | no |
| `power` | Energia | systems fail | no |
| `morale` | Morale | breakdown | **yes** (recklessness at max) |
| `hull` | Scafo | breach/death | no |

Two-sided danger (`morale`) is introduced as data so Phase 6 can lean on it. Phase 1 only needs
the columns + linear daily consumption; the two-sided *consequences* arrive with the engine.

## 7. Seeded RNG (the reproducibility contract)

Each `Run` stores an integer `seed`. All randomness for that run derives from a deterministic
PRNG seeded by `seed` + a monotonic per-run `rng_cursor` (stored, advanced on each draw) so a
reload mid-run does not desync. Same seed + same choices ⇒ identical run. This is the spine of
the Phase 5 simulation harness; building it now in Phase 1 is deliberate.

## 8. DSL design (FOR REVIEW BEFORE PHASE 2 — not yet implemented)

The prompt's DSL sketch is adopted essentially as-is. Storage decision and a few clarifications
to confirm at the review gate:

**Storage:** Events stored in a `events` table with **JSON columns** for `requires`, `choices`
(choices embed their `outcomes`→`effects`). Rationale: the DSL is a nested tree; relational
shredding (event→choice→outcome→effect tables) buys little and makes the Selector/Applier read
many joins per card. JSON columns keep one row = one whole event (matches Prime Directive #1:
"add a new event = one new seeder row"). SQLite + MySQL both support JSON. A seeder validates
each row against a schema at seed time so malformed content fails loudly.

**Condition tree** (pure, total, evaluated against run state):
```
{ all: [Cond...] } | { any: [Cond...] } | { not: Cond }
{ resource: "food", op: "<", value: 3 }
{ day: { op: ">=", value: 5 } }
{ has_role: "engineer" } | { has_item: "scanner" } | { trait_present: "paranoid" }
{ flag: "vented_the_technician", scope: "run|profile", is: true }
{ relationship: { state: "hatred", scope: "any" } }
{ system: "life_support", field: "efficiency", op: "<", value: 50 }
```

**Effect list** (pure given a seed):
```
{ resource: "oxygen", delta: -2 }
{ character: "random|lowest_loyalty|highest_stress|<name>", stress: 15 }
{ relationship: { a, b, delta } }
{ damage_system: "power_grid", amount: 20 }
{ set_flag: "...", scope: "run|profile", value: true }
{ spawn_event: { key, in_days } }
{ recruit: { role } } | { kill: "lowest_loyalty" }
{ grant_research_points: 5 }
```

**Three engine services** (Phase 2): Selector (always returns a card — guaranteed filler pool),
Condition Evaluator (pure/total), Effect Applier (pure given seed). Filler pool = events whose
`requires` is `{ all: [] }` (always true), low stakes, so the hand is never empty.

**Open DSL questions to confirm at the gate:**
1. `op` set = `<, <=, =, !=, >=, >` only? (Proposed: yes.)
2. Resource clamping: do effects clamp to `[0, max]` per resource, or can they overshoot and a
   separate rule reads "below zero"? (Proposed: clamp to `[0, max]`; death is a separate
   condition check on `= 0`, not a negative value.)
3. `cooldown_days` tracked per-event in run state. (Proposed: yes, a `recent_events` map.)
4. Delayed events (`spawn_event`) stored as a per-run queue `{key, fire_on_day}`. (Proposed: yes.)

## 9. Out of scope now

No i18n scaffolding (Italian written directly). No sound. No Docker. No prod DB.
