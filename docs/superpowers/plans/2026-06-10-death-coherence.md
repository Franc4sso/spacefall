# Death Coherence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three playtest bugs: a win firing with the whole crew dead, deaths that arrive unexplained, and dead crew still speaking in events.

**Architecture:** A `living_crew` condition primitive enables a LOSE ending `crew_lost` that fires the instant the last member dies (config + one condition). A single `Selector` rule excludes any event whose named `speaker` is dead (covers 40+ events, no re-tagging). Hunger gains escalating named warnings (config + one event), and the `death_notice` card is personalized per-death (engine composes its body from the death_log and marks entries announced). The frontend shows per-member hunger. No new tables beyond an `announced` marker carried inside existing death_log entries.

**Tech Stack:** Laravel, PHP, Pest (backend). React + Vite + Vitest (frontend). Sim via `sim:run --memory`.

---

## Background the engineer needs

- **Conditions** (`app/Game/Engine/ConditionEvaluator.php`): leaf predicates are `if (array_key_exists('X',$condition))` blocks; numeric ones use `$this->compare($actual, $op, $value)`. `compare` supports `<,<=,=,==,!=,>=,>`. `EventSchema::CONDITION_KEYS` (line 28) whitelists keys — a key not listed makes the seeder throw.
- **Characters:** `RunState::$characters` = list of `{name, role, traits, alive, stress, hunger, away_until}`. Dead = `alive === false`.
- **Endings** (`config('game.endings')`, evaluated by `EndingService::check` first-match-wins, in order): 6 resource-death endings, `mutiny_end`, then wins (`win_escape`…`lone_survivor`). `EndingService::check` runs after each `resolveChoice` and each `DayProcessor::advance`.
- **Selector** (`app/Game/Engine/Selector.php`): `select()` has 4 tiers — (1) scheduled/forced events due today (filter at L43-48, bypasses `requires`), (2) eligible pool via `isEligible` (L84-87, = `evaluator->evaluate($event->requires)`) + not on cooldown, (3) filler, (4) last-resort. The speaker-alive gate must apply to BOTH the scheduled filter and the eligible/filler filters.
- **death_notice** (`ContentEventSeeder.php:1729`): today a STATIC card — generic body, `speaker:null`, gated on never-set `__never`, scheduled by `EventEngine`/`DayProcessor` when a death is logged. Currently one per day (dedup by key), anonymous.
- **death_log** entries: `{name, day, cause, context}` (added in a prior slice). `cause` ∈ event|starvation|expedition|morale.
- **Hunger** (`config('game.hunger')`): `daily_rise:8, starve_at:100`, `stress_bands` (stress only), `spawn_bands` (one band `at_or_above:30 → spawn food_ration`). `DayProcessor::applyHunger` raises hunger, crosses bands (`$newBand > old` re-triggers a band's spawn), kills at `starve_at`.
- **EventEngine::currentCard(Run): array** returns `['event'=>Event, 'choices'=>[...]]` (or pins one). This is where a death_notice body can be personalized at presentation time.
- **RunController::present** serializes per-character `hunger`/`stress`/`alive`. Frontend `CrewPanel.tsx` renders the crew; `api.ts` types `Character`.
- **Tests:** `php artisan test [--filter X]`; re-seed `php artisan migrate:fresh --seed --quiet`; sim `php artisan sim:run --count=200 --items=<csv> --memory --no-interaction`. Frontend: `cd frontend && npm run test` / `npm run build`.

## File Structure

- Modify: `backend/app/Game/Engine/ConditionEvaluator.php` — `living_crew` predicate.
- Modify: `backend/app/Game/Engine/EventSchema.php` — whitelist `living_crew`.
- Modify: `backend/config/game.php` — `crew_lost` ending; hunger warning bands.
- Modify: `backend/app/Game/Engine/Selector.php` — dead-speaker gate.
- Modify: `backend/app/Game/Engine/EventEngine.php` — personalize death_notice body + per-death scheduling; DayProcessor likewise.
- Modify: `backend/app/Game/DayProcessor.php` — per-death notice scheduling.
- Modify: `backend/database/seeders/ContentEventSeeder.php` — hunger_warning event; death_notice stays (body overridden at runtime).
- Modify: `frontend/src/components/CrewPanel.tsx`, `frontend/src/api.ts` (if needed) — hunger indicator.
- Test: `backend/tests/Unit/LivingCrewConditionTest.php`, `backend/tests/Feature/CrewLostEndingTest.php`, `backend/tests/Feature/DeadSpeakerGateTest.php`, `backend/tests/Feature/DeathVisibilityTest.php`; frontend a small CrewPanel test if a runner exists.

---

## Task 1: `living_crew` condition primitive

**Files:**
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Test: `backend/tests/Unit/LivingCrewConditionTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/LivingCrewConditionTest.php`:

```php
<?php

use App\Game\Engine\ConditionEvaluator;
use App\Game\Engine\RunState;

function crewState(array $characters): RunState
{
    return new RunState(
        day: 10,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: $characters,
    );
}

function member(string $name, bool $alive): array
{
    return ['name' => $name, 'role' => 'x', 'traits' => [], 'alive' => $alive, 'stress' => 0, 'hunger' => 0, 'away_until' => 0];
}

it('counts living crew and compares', function () {
    $e = new ConditionEvaluator();
    $allDead = crewState([member('Anna', false), member('Bex', false), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '==', 'value' => 0]], $allDead))->toBeTrue();

    $oneAlive = crewState([member('Anna', true), member('Bex', false), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '==', 'value' => 0]], $oneAlive))->toBeFalse();
    expect($e->evaluate(['living_crew' => ['op' => '>=', 'value' => 1]], $oneAlive))->toBeTrue();

    $twoAlive = crewState([member('Anna', true), member('Bex', true), member('Cole', false)]);
    expect($e->evaluate(['living_crew' => ['op' => '<', 'value' => 3]], $twoAlive))->toBeTrue();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `cd backend && php artisan test --filter LivingCrewConditionTest`
Expected: FAIL — `living_crew` falls through to the unknown-shape branch (returns false), so the `==0` true case fails.

- [ ] **Step 3: Add the predicate**

In `backend/app/Game/Engine/ConditionEvaluator.php`, add this block immediately AFTER the `day`
predicate block (after `if (array_key_exists('day', ...))`):

```php
        if (array_key_exists('living_crew', $condition)) {
            $spec = $condition['living_crew'];
            $count = 0;
            foreach ($state->characters as $c) {
                if (($c['alive'] ?? true) === true) {
                    $count++;
                }
            }
            return $this->compare($count, $spec['op'] ?? '=', $spec['value'] ?? 0);
        }
```

- [ ] **Step 4: Whitelist the key in EventSchema**

In `backend/app/Game/Engine/EventSchema.php`, add `'living_crew'` to the `CONDITION_KEYS` const array.

- [ ] **Step 5: Run, confirm PASS**

Run: `cd backend && php artisan test --filter LivingCrewConditionTest`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/ConditionEvaluator.php backend/app/Game/Engine/EventSchema.php backend/tests/Unit/LivingCrewConditionTest.php
git commit -m "feat: living_crew condition primitive (counts alive characters)"
```

---

## Task 2: `crew_lost` ending — fires when the last member dies

**Files:**
- Modify: `backend/config/game.php`
- Test: `backend/tests/Feature/CrewLostEndingTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/CrewLostEndingTest.php`:

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

it('ends the run with crew_lost (lose) the moment the whole crew is dead', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    // Healthy resources, well past day 25 — would otherwise be a lone_survivor win.
    $run->day = 30;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    $after = $run->fresh();
    expect($after->ending_key)->toBe('crew_lost');
    expect($after->ending_type)->toBe('lose');
});

it('does not fire crew_lost while at least one member is alive', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 30;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    $chars[0]['alive'] = true; // one alive
    for ($i = 1; $i < count($chars); $i++) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->not->toBe('crew_lost');
});

it('fires crew_lost even before day 25 (no day gate)', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 12;
    $run->resources = ['oxygen' => 80, 'food' => 80, 'power' => 80, 'morale' => 80, 'hull' => 80];
    $chars = $run->characters;
    foreach ($chars as $i => $c) { $chars[$i]['alive'] = false; }
    $run->characters = $chars;
    $run->save();

    app(EndingService::class)->check($run);

    expect($run->fresh()->ending_key)->toBe('crew_lost');
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `cd backend && php artisan test --filter CrewLostEndingTest`
Expected: FAIL — currently `lone_survivor` (or nothing pre-day-25) fires, not `crew_lost`.

- [ ] **Step 3: Add the ending**

In `backend/config/game.php`, in the `endings` array, insert this entry AFTER `mutiny_end` and
BEFORE `win_escape` (so an emptied crew pre-empts every win, while resource-deaths still take
priority if simultaneous):

```php
        // Finale: equipaggio perduto — l'ultimo membro è morto. Sconfitta: hai
        // tenuto in vita la stazione, non le persone. Nessuna soglia-giorno:
        // scatta nel momento in cui muore l'ultimo.
        [
            'key' => 'crew_lost', 'type' => 'lose',
            'name' => 'SOLO',
            'text' => 'Sei rimasto solo. La stazione respira ancora. Tu, dentro, un po\' meno.',
            'when' => ['living_crew' => ['op' => '==', 'value' => 0]],
        ],
```

- [ ] **Step 4: Run, confirm PASS**

Run: `cd backend && php artisan test --filter CrewLostEndingTest`
Expected: PASS (all 3).

- [ ] **Step 5: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures. (If an existing ending test created an all-dead run and expected a win, it
now correctly gets crew_lost — update that test's expectation; report it.)

- [ ] **Step 6: Commit**

```bash
git add backend/config/game.php backend/tests/Feature/CrewLostEndingTest.php
git commit -m "feat: crew_lost lose-ending fires the moment the whole crew dies"
```

---

## Task 3: Dead-speaker gate in the Selector

**Files:**
- Modify: `backend/app/Game/Engine/Selector.php`
- Test: `backend/tests/Feature/DeadSpeakerGateTest.php`

An event with a named `speaker` is ineligible if that named character is not alive. Events with
`speaker: null` are never excluded by this rule. Applies to BOTH the scheduled-forced filter and
the eligible/filler filters.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/DeadSpeakerGateTest.php`:

```php
<?php

use App\Game\Engine\RunState;
use App\Game\Engine\Selector;
use App\Game\SeededRng;
use App\Models\Event;

it('excludes an event whose named speaker is dead, keeps speaker-null events', function () {
    $selector = app(Selector::class);

    // Hand-build a tiny pool: one Anna-spoken event, one narrator (null) event.
    $annaEvent = new Event(['key' => 't_anna', 'title' => 'x', 'body' => 'x', 'speaker' => 'Anna', 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $narratorEvent = new Event(['key' => 't_narr', 'title' => 'x', 'body' => 'x', 'speaker' => null, 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $pool = collect([$annaEvent, $narratorEvent]);

    // Anna is DEAD.
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: [['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => false, 'stress' => 0, 'hunger' => 0, 'away_until' => 0]],
    );

    // Draw many times; the Anna event must NEVER be selected (she's dead), the narrator can.
    $seenAnna = false;
    $seenNarr = false;
    for ($i = 0; $i < 20; $i++) {
        $picked = $selector->select($pool, $state, new SeededRng($i));
        if ($picked->key === 't_anna') $seenAnna = true;
        if ($picked->key === 't_narr') $seenNarr = true;
    }
    expect($seenAnna)->toBeFalse('a dead speaker event must not be selected');
    expect($seenNarr)->toBeTrue('the narrator event should still be selectable');
});

it('keeps an event whose named speaker is alive', function () {
    $selector = app(Selector::class);
    $annaEvent = new Event(['key' => 't_anna', 'title' => 'x', 'body' => 'x', 'speaker' => 'Anna', 'base_weight' => 10, 'cooldown_days' => 0, 'is_filler' => false, 'requires' => null, 'choices' => [['label' => 'ok', 'hint' => null, 'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'x']]]]]);
    $pool = collect([$annaEvent]);
    $state = new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        characters: [['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0]],
    );
    expect($selector->select($pool, $state, new SeededRng(1))->key)->toBe('t_anna');
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `cd backend && php artisan test --filter DeadSpeakerGateTest`
Expected: FAIL — the dead-Anna event is still selectable (no gate yet).

- [ ] **Step 3: Add the speakerAlive gate**

In `backend/app/Game/Engine/Selector.php`:

(a) Add the scheduled filter to also check speakerAlive. Change the due-events filter (the line
`$due = $events->filter(fn (Event $e) => in_array($e->key, $dueKeys, true));`) to:

```php
            $due = $events->filter(fn (Event $e) => in_array($e->key, $dueKeys, true) && $this->speakerAlive($e, $state));
```

(b) Fold speakerAlive into `isEligible`:

```php
    private function isEligible(Event $event, RunState $state): bool
    {
        return $this->speakerAlive($event, $state)
            && $this->evaluator->evaluate($event->requires, $state);
    }
```

(c) Add the helper:

```php
    /**
     * An event with a named speaker may only fire while that speaker is alive.
     * Narrator events (speaker null) are never gated by this rule.
     */
    private function speakerAlive(Event $event, RunState $state): bool
    {
        $speaker = $event->speaker ?? null;
        if ($speaker === null || $speaker === '') {
            return true;
        }
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $speaker) {
                return (bool) ($c['alive'] ?? true);
            }
        }
        // Speaker named but not in the roster at all — let it through (defensive;
        // shouldn't happen for the fixed Anna/Bex/Cole crew).
        return true;
    }
```

- [ ] **Step 4: Run, confirm PASS**

Run: `cd backend && php artisan test --filter DeadSpeakerGateTest`
Expected: PASS.

- [ ] **Step 5: Full suite + Selector-never-stalls**

Run: `cd backend && php artisan test`
Expected: 0 failures. The Selector still always returns an event (filler is `speaker:null`, so it
survives the gate). If the existing "Selector never stalls" test fails because too many events got
gated in some state, STOP and report — but filler being speaker-null should prevent that.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/Selector.php backend/tests/Feature/DeadSpeakerGateTest.php
git commit -m "feat: dead-speaker gate — events with a dead named speaker are ineligible"
```

---

## Task 4: Personalized death_notice (chi + come + quando), one per death

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Modify: `backend/app/Game/Engine/EpilogueComposer.php` (reuse its cause phrases) — OR config
- Modify: `backend/database/seeders/ContentEventSeeder.php` (death_notice body becomes a fallback)
- Test: `backend/tests/Feature/DeathVisibilityTest.php`

The death_notice card surfaces with a body composed from the next UN-ANNOUNCED death_log entry,
and marks it announced so the next death gets its own notice. Per-death, not collapsed.

- [ ] **Step 1: Add a death-cause phrase map to config**

In `backend/config/game.php`, add to the `epilogue` block (or a new `death_notice` block) a
`notice_phrases` map (distinct from the epilogue's `cause_phrases`, which are past-tense epilogue
lines — these are the in-the-moment notice):

```php
    'death_notice_phrases' => [
        'starvation' => 'La fame ha avuto la meglio.',
        'event' => 'Una scelta è costata cara.',
        'expedition' => 'La spedizione non è tornata.',
        'morale' => 'Si è spento dentro, prima che fuori.',
    ],
```

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/DeathVisibilityTest.php`:

```php
<?php

use App\Game\Engine\EventEngine;
use App\Game\RunFactory;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('personalizes the death_notice card with the name, day and cause', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 19;
    $run->death_log = [['name' => 'Anna', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger']];
    $run->current_event_key = 'death_notice';
    $run->save();

    $card = app(EventEngine::class)->currentCard($run->fresh());

    expect($card['event']->body)->toContain('Anna');
    expect($card['event']->body)->toContain('19');
    expect($card['event']->body)->toContain('fame'); // from the starvation phrase
});

it('announces each death separately (marks announced, advances to the next)', function () {
    $run = app(RunFactory::class)->create(1, ['welder']);
    $run->day = 19;
    $run->death_log = [
        ['name' => 'Anna', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger'],
        ['name' => 'Cole', 'day' => 19, 'cause' => 'starvation', 'context' => 'hunger'],
    ];
    $run->current_event_key = 'death_notice';
    $run->save();

    // First notice -> Anna; resolving it marks Anna announced.
    $engine = app(EventEngine::class);
    $card1 = $engine->currentCard($run->fresh());
    expect($card1['event']->body)->toContain('Anna');
    $engine->resolveChoice($run->fresh(), 0);

    // A second death_notice is scheduled for Cole (not yet announced).
    $after = $run->fresh();
    $log = $after->death_log;
    expect(collect($log)->firstWhere('name', 'Anna')['announced'] ?? false)->toBeTrue();
    // Cole still un-announced.
    expect(collect($log)->firstWhere('name', 'Cole')['announced'] ?? false)->toBeFalse();
});
```

- [ ] **Step 3: Personalize death_notice in currentCard + schedule per death**

In `backend/app/Game/Engine/EventEngine.php`:

(a) In `currentCard`, after the event to present is determined and it IS the `death_notice` event,
override its body from the first un-announced death_log entry. Find where `currentCard` returns
`['event'=>$event, 'choices'=>...]`; before returning, if `$event && $event->key === 'death_notice'`,
compose the body:

```php
        if ($event !== null && $event->key === 'death_notice') {
            $log = $run->death_log ?? [];
            $idx = null;
            foreach ($log as $i => $entry) {
                if (! ($entry['announced'] ?? false)) { $idx = $i; break; }
            }
            if ($idx !== null) {
                $entry = $log[$idx];
                $phrase = config('game.death_notice_phrases.' . ($entry['cause'] ?? 'event'), 'Se n\'è andato.');
                $event->body = "{$entry['name']}. Giorno {$entry['day']}. {$phrase}";
            }
        }
```

(b) When a death_notice is RESOLVED, mark the announced entry and schedule another notice if more
remain. `resolveChoice` works on a `RunState` then calls `applyTo($run)`. The death_log is carried
on `RunState` as `$state->deathLog` (wired in a prior slice) and persisted by `applyTo`. So, near
the end of `resolveChoice`, AFTER effects are applied and BEFORE `$state->applyTo($run)`, add this
block guarded on the resolved event being the death_notice:

```php
        if ($event->key === 'death_notice') {
            $marked = false;
            foreach ($state->deathLog as $i => $entry) {
                if (! ($entry['announced'] ?? false)) {
                    $state->deathLog[$i]['announced'] = true;
                    $marked = true;
                    break;
                }
            }
            // If more deaths remain un-announced, queue another notice today.
            $remaining = collect($state->deathLog)->contains(fn ($e) => ! ($e['announced'] ?? false));
            if ($marked && $remaining) {
                $state->scheduledEvents[] = ['key' => 'death_notice', 'fire_on_day' => $state->day];
            }
        }
```

(Place this BEFORE `$state->applyTo($run)` so deathLog + scheduledEvents persist. `applyTo` already
writes `death_log` and `scheduled_events`.)

(c) Update the death_notice SCHEDULING points (EventEngine death detection + DayProcessor) so that
when N deaths happen at once, the announced/queue logic above handles the rest — the existing
"schedule one death_notice when a death occurs" stays (it kicks off the first); the resolve-time
logic chains the rest. No change needed there beyond confirming the first notice still schedules.

- [ ] **Step 4: Make the seeded death_notice body a fallback**

In `ContentEventSeeder.php`, the `death_notice` event keeps its generic body (used only if the
death_log is somehow empty). No change required — the runtime override in (a) supersedes it when a
death exists. (Leave the card as-is.)

- [ ] **Step 5: Run, confirm PASS**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter DeathVisibilityTest`
Expected: PASS (personalized body; per-death announce/chain). If the `RunState` doesn't carry
`deathLog` (it should), or `currentCard`'s structure differs, adjust to the real shape — keep the
assertions.

- [ ] **Step 6: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures. The prior `DeathLogTest` "schedules a death_notice" test should still pass
(the first notice still schedules).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php backend/config/game.php backend/tests/Feature/DeathVisibilityTest.php
git commit -m "feat: personalized death_notice (name + day + cause), one announcement per death"
```

---

## Task 5: Escalating hunger warnings that name the starving member

**Files:**
- Modify: `backend/config/game.php`
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Modify: `backend/app/Game/DayProcessor.php` (if the warning needs the hungriest name)
- Test: `backend/tests/Feature/DeathVisibilityTest.php` (append)

Add higher hunger bands that spawn a `hunger_warning` card (speaker null — survives the dead-speaker
gate) which surfaces that someone is starving. The existing `applyHunger` band logic re-triggers a
band's spawn when crossing up, so multiple bands give escalating warnings.

- [ ] **Step 1: Add warning bands + the hungriest-name hook**

In `backend/config/game.php`, `hunger.spawn_bands` — add two higher bands. They spawn the new
`hunger_warning` event (the meal-decision `food_ration` stays at 30):

```php
        'spawn_bands' => [
            ['at_or_above' => 30, 'spawn' => 'food_ration'],
            ['at_or_above' => 65, 'spawn' => 'hunger_warning'],
            ['at_or_above' => 88, 'spawn' => 'hunger_warning'],
        ],
```

NOTE: confirm `applyHunger`'s band logic re-spawns when crossing each higher band. The current
logic computes `$newBand` as the highest band index met and spawns only when `$newBand > old band`.
With three bands, crossing 30→65→88 advances the band each time and spawns the matching event. If
the same `hunger_warning` key at two bands is collapsed (it's scheduled twice with the same key →
dedup), that's acceptable — the warning still fires at 65 and again after eating-then-restarving.

- [ ] **Step 2: Add the hunger_warning event**

In `ContentEventSeeder.php`, add to `silentEvents()` (single-choice, speaker null — passes the
dead-speaker gate):

```php
            $this->ev([
                'key' => 'hunger_warning', 'title' => 'Pelle e ossa', 'speaker' => null,
                'body' => "Qualcuno nell'equipaggio è ridotto a pelle e ossa. Senza cibo, presto, non si rialzerà. Lo vedi negli occhi di tutti: il tempo stringe.",
                'requires' => ['flag' => '__never', 'is' => true],
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Lo so. Faccio quel che posso.', [['resource' => 'morale', 'delta' => -2]], 'Le parole non riempiono lo stomaco.'),
                ],
            ]),
```

(Generic "qualcuno" keeps it robust and speaker-null; naming the hungriest would require the same
runtime-override trick as death_notice — out of scope for this card, the death_notice already names
the dead. The escalation + visibility is the goal.)

- [ ] **Step 3: Append the test**

Append to `backend/tests/Feature/DeathVisibilityTest.php`:

```php
it('seeds a speaker-null hunger_warning surfaced by high hunger bands', function () {
    $warn = \App\Models\Event::where('key', 'hunger_warning')->first();
    expect($warn)->not->toBeNull();
    expect($warn->speaker)->toBeNull(); // survives the dead-speaker gate
    $bands = collect(config('game.hunger.spawn_bands'))->pluck('spawn');
    expect($bands)->toContain('hunger_warning');
});

it('schedules a hunger warning when a crew member crosses a high hunger band', function () {
    $run = app(RunFactory::class)->create(3, ['welder']);
    $chars = $run->characters;
    $chars[0]['hunger'] = 60;       // below 65; daily_rise 8 pushes to 68 -> crosses the 65 band
    $chars[0]['hunger_band'] = 1;   // already past the food_ration band
    $run->characters = $chars;
    $run->day = 8;
    $run->save();

    app(\App\Game\DayProcessor::class)->advance($run->fresh());

    $keys = collect($run->fresh()->scheduled_events ?? [])->pluck('key');
    expect($keys)->toContain('hunger_warning');
});
```

NOTE: the second test depends on `applyHunger`'s band indexing. If `hunger_band` semantics differ
(e.g. band index counts only spawn_bands), set the starting `hunger_band` so that crossing into the
65 band increments it. Read `applyHunger`, confirm the band index, and set the test's initial
`hunger`/`hunger_band` so the cross happens. Keep the assertion (a hunger_warning gets scheduled).

- [ ] **Step 4: Re-seed + run**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter "DeathVisibilityTest|ContentTest"`
Expected: PASS (hunger_warning seeded + scheduled; schema valid).

- [ ] **Step 5: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add backend/config/game.php backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/DeathVisibilityTest.php
git commit -m "feat: escalating hunger warnings (speaker-null card at high hunger bands)"
```

---

## Task 6: Frontend — per-member hunger indicator

**Files:**
- Modify: `frontend/src/components/CrewPanel.tsx`
- Modify: `frontend/src/api.ts` (only if `Character.hunger` isn't typed)
- Test: frontend (vitest) if the component is testable; else manual note

- [ ] **Step 1: Confirm the data + type**

Read `frontend/src/api.ts` `Character` type — confirm it has `hunger` (and `alive`). If `hunger`
is missing from the type, add `hunger: number;`. Read `CrewPanel.tsx` to see how each member is
rendered (name, stress, away state).

- [ ] **Step 2: Add a hunger indicator to each crew member**

In `CrewPanel.tsx`, for each crew member render a small hunger bar/label alongside the existing
state. Use a color ramp so rising hunger is visible (green→amber→red). Concrete minimal version —
a thin bar whose width = hunger% and color shifts past thresholds:

```tsx
{/* Hunger indicator — rises toward starvation (100). */}
<div style={{ marginTop: 4 }}>
  <div style={{ fontSize: 9, fontFamily: "var(--font-mono)", opacity: 0.6, letterSpacing: "0.1em" }}>
    FAME {member.hunger ?? 0}
  </div>
  <div style={{ height: 3, background: "rgba(255,255,255,0.08)", borderRadius: 2, overflow: "hidden" }}>
    <div style={{
      width: `${Math.min(100, member.hunger ?? 0)}%`,
      height: "100%",
      background: (member.hunger ?? 0) >= 80 ? "var(--color-red)" : (member.hunger ?? 0) >= 50 ? "var(--color-amber, #fbbf24)" : "var(--color-cyan)",
    }} />
  </div>
</div>
```

Place it inside the per-member block (read CrewPanel to find the member loop variable name — it may
be `member`, `c`, or `crew`; adjust). Only render for ALIVE members (dead members shouldn't show a
hunger bar — read how the panel marks dead and guard with the existing alive check).

- [ ] **Step 3: Test (if a component test harness exists) or typecheck**

Run: `cd frontend && npm run test 2>/dev/null || echo "no component tests"`
Run: `cd frontend && npm run build`
Expected: build clean (the hunger field is additive/optional). If there's an existing CrewPanel
test, extend it to assert the FAME label renders for a member with hunger; otherwise rely on build
+ manual.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/CrewPanel.tsx frontend/src/api.ts
git commit -m "feat: per-member hunger indicator in the crew panel"
```

---

## Task 7: Full suite + sim + manual smoke

**Files:** none (verification only).

- [ ] **Step 1: Backend full suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (prior 250 + LivingCrew/CrewLost/DeadSpeaker/DeathVisibility).

- [ ] **Step 2: Frontend tests + build**

Run: `cd frontend && npm run test && npm run build`
Expected: PASS + clean build.

- [ ] **Step 3: Sim — crew_lost appears, no regression**

Run:
```bash
cd backend && php artisan sim:run --count=200 --items=welder,scanner,rifle,drone --memory --no-interaction
cd backend && php artisan sim:run --count=200 --items=seedbank,comms,scanner,medkit --memory --no-interaction
```
Expected: 0 stalls; `crew_lost` now appears in the endings distribution for runs where the crew
dies (previously those were lone_survivor / death_*). Win-rate roughly stable (crew_lost reclassifies
some former lone_survivor "wins" as losses — that's the intended correctness fix, so the win% may
drop a few points; that's expected, not a regression). Record the distribution.

- [ ] **Step 4: Smoke — dead crew no longer speak; deaths are announced**

Run:
```bash
cd backend && php artisan tinker <<'PHP' 2>&1 | grep '^smoke'
$sim = app(App\Game\Sim\Simulator::class);
foreach ([1,7,19] as $s) {
  $r = $sim->play($s, new App\Game\Sim\GreedySurvivalPolicy(), ['rifle','medkit','comms','scanner','drone']);
  $run = App\Models\Run::find($r->runId);
  $deaths = count($run->death_log ?? []);
  // Did any event with a dead speaker get logged in the choice_log AFTER that speaker died?
  $dead = collect($run->characters ?? [])->where('alive', false)->pluck('name')->all();
  echo "smoke seed $s: ending=".$run->ending_key." deaths=$deaths dead=[".implode(',', $dead)."]".PHP_EOL;
}
PHP
```
Expected: prints endings; runs where all crew died show `ending=crew_lost`. (Full "dead don't speak"
verification is the unit DeadSpeakerGateTest; this smoke confirms the ending classification end to
end.)

- [ ] **Step 5: Commit the verification note**

```bash
git commit --allow-empty -m "test: death coherence — crew_lost ending, dead-speaker gate, visible deaths

<N> backend tests + frontend green + build clean.
sim 200 A: <dist incl crew_lost>; sim 200 B: <dist>; 0 stalls.
crew_lost fires when the crew is wiped; dead speakers gated; deaths named in notices."
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** living_crew primitive (Task 1); crew_lost lose-ending firing immediately, no
  day gate, pre-empting wins (Task 2); dead-speaker gate uniform across scheduled + eligible +
  filler (Task 3); personalized per-death notice (Task 4); escalating speaker-null hunger warnings
  (Task 5); frontend hunger indicator (Task 6); sim + smoke (Task 7). All three bugs + all spec
  sections mapped.
- **Placeholder scan:** Tasks 4/5/6 have explicit "confirm the real shape (RunState.deathLog /
  applyHunger band index / CrewPanel member var), adjust the call, keep the assertion" discovery
  steps — not placeholders; the fallback is spelled out. I removed a stray placeholder line in
  Task 4 step 3 (the `__unused` snippet) — the real deathLog logic follows it. All code steps show
  full code.
- **Type/field consistency:** `living_crew` shape `{op,value}` consistent across ConditionEvaluator
  (Task 1), config crew_lost when (Task 2), and tests. `speakerAlive` helper name stable in Task 3.
  death_notice phrase config key `game.death_notice_phrases.<cause>` matches the cause values
  (starvation/event/expedition/morale) used by the death_log writers from the prior slice. The
  `announced` marker is added to death_log entries in-place (no schema change — death_log is JSON).
  `hunger_warning` key consistent between config spawn_bands and the seeded event.
- **Risk flagged:** Task 2 step 5 and Task 7 step 3 both note that crew_lost reclassifies some
  former lone_survivor outcomes as losses — an intended correctness change, win% may dip, not a
  regression. Task 4's stray `__unused` placeholder line should be deleted by the implementer (the
  real `$state->deathLog` block immediately follows and is the actual implementation).
