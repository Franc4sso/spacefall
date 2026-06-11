# Island Content Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the island theme to system-parity with space — every narrative system space has (character voice arcs, atmosphere fillers, item arcs, pair/cross relationship arcs, per-phase depth, expeditions), sized to island's 3-survivor cast — as pure seeder/config content, zero engine code.

**Architecture:** All new events live in `backend/database/seeders/IslandEventSeeder.php`, organized in per-stratum methods, registered in `events()`. Each event uses the existing `ev()`/`one()`/`gamble()` helpers (`ev()` defaults `theme=>'island'`, base_weight 12, cooldown 4). Built in 4 strata by impact: (1) survivor voice, (2) island soul = fillers + item arcs, (3) relationships, (4) phase depth + expeditions. The only non-seeder change is extending `FlagReachabilityTest` to scan `themes.island.*`.

**Tech Stack:** Laravel 12, PHP 8.2, Pest. Italian game text, English keys/flags. Content is data — the engine is generic.

---

## Reference: the helpers (already in IslandEventSeeder.php)

```php
// ev() wraps an event array with island defaults:
$this->ev([
    'key' => 'some_key', 'title' => 'Titolo', 'speaker' => 'Nadia', // speaker optional
    'requires' => ['flag' => 'x', 'is' => true],                     // optional gate
    'choices' => [ /* one()/gamble() results */ ],
])
// one(label, effects[], log, hint?, requires?) — a single-outcome choice
// gamble(label, good[], goodLog, bad[], badLog, goodW, badW, hint?) — a weighted 2-outcome choice
```

**Effect vocabulary** (seen in the seeder, all engine-supported):
`['resource'=>'water','delta'=>-5]`, `['set_flag'=>'x','value'=>true]`,
`['spawn_event'=>['key'=>'next','in_days'=>3]]`, `['character'=>'Nadia','stress'=>8]` (or `'all'`, `'highest_stress'`),
`['kill'=>'Nadia']`, `['relationship'=>['a'=>'Nadia','b'=>'Bruno','delta'=>-12]]`,
`['modify_standing'=>['who'=>'Nadia','delta'=>8]]`, `['modify_trust'=>6]`.

**Gate vocabulary:** `['flag'=>'x','is'=>true]`, `['alive'=>'Nadia']`,
`['relationship'=>['a'=>'Nadia','b'=>'Bruno','state'=>'bond']]`, `['day'=>['op'=>'>=','value'=>6]]`,
`['all'=>[...]]`, `['any'=>[...]]`, `['not'=>[...]]`, `['has_item'=>'radio']`.

**Cast:** Nadia (engineer/genius), Bruno (doctor/optimist), Carla (pilot/coward). Resources: water/food/fire/shelter/morale. Systems: water_still/signal_fire/shelter_frame.

---

## File Structure

- **Modify:** `backend/database/seeders/IslandEventSeeder.php` — add per-stratum methods, register them in `events()`. Existing methods to refactor: `survivorArc()` (rename legacy `cole_*`/`survivor_*` flags into nominal arcs in Task 1), `fillerEvents()` (extend in Task 4), `itemEvents()` (extend in Task 5).
- **Modify:** `backend/config/themes/island.php` — add config only when a stratum needs it (relationship-driven flags already work via effects; epilogue witness lines for new arc flags).
- **Modify:** `backend/tests/Feature/FlagReachabilityTest.php` — extend to scan `themes.island.*` (Task 0).
- **Create per stratum:** `backend/tests/Feature/Island<Stratum>Test.php`.

---

## Task 0: Extend the flag-reachability guardian to island

**Files:**
- Modify: `backend/tests/Feature/FlagReachabilityTest.php`
- Test: same file

The guardian currently scans only `themes.space.*` and the space seeders. We make it ALSO assert that every flag set by an island event is read somewhere (island config endings/epilogue or another island card), and vice-versa for referenced flags. This is the safety net every later task relies on.

- [ ] **Step 1: Read the current test to learn its shape**

Run: `sed -n '1,90p' backend/tests/Feature/FlagReachabilityTest.php`
Note how it collects `written` (from `set_flag` effects) and `read` (from `requires`, `config('themes.space.endings')`, `config('themes.space.epilogue.witness_flags')`, outcome lines), and how it asserts.

- [ ] **Step 2: Write the failing test — an island assertion**

Add a new `it(...)` block mirroring the space one but for island. It must:
- migrate:fresh+seed, load all `Event::where('theme','island')->get()`,
- collect `written` from their choices' `set_flag` effects,
- collect `read` from their `requires`, plus `config('themes.island.endings')` `when` flags, `config('themes.island.epilogue.witness_flags')` keys, and `config('themes.island.epilogue.rescue_outcome_lines')` keys,
- assert no island flag is set-but-never-read (allowing a documented ignore-list for intentionally-inert flags, same pattern as the space test if it has one).

```php
it('island: ogni flag scritto da una scelta è letto da una carta, un finale o l\'epilogo', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $written = [];
    foreach (\App\Models\Event::where('theme', 'island')->get() as $ev) {
        foreach ($ev->choices as $choice) {
            foreach ($choice['outcomes'] ?? [] as $out) {
                foreach ($out['effects'] ?? [] as $eff) {
                    if (array_key_exists('set_flag', $eff)) $written[$eff['set_flag']] = true;
                }
            }
        }
    }
    $read = [];
    foreach (\App\Models\Event::where('theme', 'island')->get() as $ev) {
        collectFlags($ev->requires ?? [], $read); // reuse the file's existing helper name
    }
    foreach (config('themes.island.endings') as $ending) {
        collectFlags($ending['when'] ?? [], $read);
    }
    foreach (array_keys(config('themes.island.epilogue.witness_flags', [])) as $f) $read[$f] = true;
    foreach (array_keys(config('themes.island.epilogue.rescue_outcome_lines', [])) as $f) $read[$f] = true;

    $orphans = array_diff(array_keys($written), array_keys($read));
    expect($orphans)->toBe([], 'flag isola scritti ma mai letti: '.implode(', ', $orphans));
});
```
(Adapt `collectFlags`/`$this->seed` to the file's actual helper name and seeding convention — read Step 1.)

- [ ] **Step 3: Run it — expect RED or GREEN-with-known-orphans**

Run: `cd backend && php artisan test --filter="island: ogni flag"`
Expected: It will likely FAIL listing current island orphan flags (the review found `cole_*` and aspirational witness flags). RECORD the orphan list — Task 1 fixes the `cole_*` ones; any aspirational witness-only flags get added to an ignore-list with a comment, OR removed from island config if truly unused.

- [ ] **Step 4: Make it GREEN by reconciling current island flags**

For each orphan: either it's a real arc flag that SHOULD be read (leave for the arc task that owns it) or it's dead. For NOW (Task 0), add a clearly-commented `$ignore = [...]` list for the currently-known inert flags so the guardian passes on the existing 68 events, then later tasks remove entries from `$ignore` as they wire the flags properly. Document each ignored flag with why.

Run: `cd backend && php artisan test --filter="island: ogni flag"`
Expected: PASS.

- [ ] **Step 5: Full suite + commit**

Run: `cd backend && php artisan test`
Expected: PASS (309 + 1).
```bash
git add backend/tests/Feature/FlagReachabilityTest.php
git commit -m "test: flag-reachability guardian extended to island theme"
```

---

## STRATUM 1 — Survivor voice

### Task 1: Nominal survivor voice arcs (Nadia / Bruno / Carla)

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (refactor `survivorArc()` → three nominal arcs; or add `survivorVoiceArcs()` and retire the generic one)
- Modify: `backend/config/themes/island.php` (if witness/epilogue lines reference the renamed flags)
- Test: `backend/tests/Feature/IslandVoiceArcsTest.php`

The existing `survivorArc()` is Bruno-centric but uses legacy `cole_*` flag keys. Replace with THREE 3-beat arcs, one per survivor, each characterizing the survivor through their trait. Use island-native flag keys (English): e.g. `nadia_*`, `bruno_*`, `carla_*`.

Arc shapes (3 beats each, chained via `spawn_event`; first beat gated on `['alive'=>'<Name>']` + a day floor):
- **Nadia (genius):** beat 1 she proposes a brilliant risky fix; beat 2 it strains the others; beat 3 it pays off or backfires (`gamble`). Flags: `nadia_gambit`, terminal `nadia_vindicated`/`nadia_overreached`.
- **Bruno (optimist):** beat 1 he buoys morale; beat 2 he downplays a real danger; beat 3 the denial costs or his hope holds. Flags: `bruno_hope`, terminal `bruno_denial_cost`/`bruno_hope_held`.
- **Carla (coward):** beat 1 she freezes in a crisis; beat 2 the group's patience frays; beat 3 redemption gamble or she breaks. Flags: `carla_froze`, terminal `carla_redeemed`/`carla_broke`.

- [ ] **Step 1: Read the existing survivorArc() for the exact pattern**

Run: `sed -n '156,242p' backend/database/seeders/IslandEventSeeder.php`
Note the 3-beat `spawn_event` chaining, `gamble` usage, `modify_standing`, `kill`, and the legacy `cole_*`/`survivor_*` flags you're replacing.

- [ ] **Step 2: Write the failing test**

```php
it('seeds three nominal survivor voice arcs, each a 3-beat chain', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['nadia','bruno','carla'] as $who) {
        $beats = \App\Models\Event::where('theme','island')
            ->where('key','like',$who.'_arc_%')->orderBy('key')->pluck('key')->all();
        expect(count($beats))->toBe(3, "arco di $who deve avere 3 beat, trovati: ".implode(',',$beats));
    }
});
```
(Use the key convention `nadia_arc_1/2/3`, `bruno_arc_1/2/3`, `carla_arc_1/2/3` — adjust the test if you choose a different but consistent scheme.)

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="three nominal survivor voice arcs"`
Expected: FAIL (arcs not present / wrong keys).

- [ ] **Step 4: Write the three arcs**

In `IslandEventSeeder.php`, replace `survivorArc()` with `survivorVoiceArcs()` returning 9 events (3 arcs × 3 beats), keys `nadia_arc_1..3`, `bruno_arc_1..3`, `carla_arc_1..3`. Each beat-1 gated on `['all'=>[['alive'=>'<Name>'],['day'=>['op'=>'>=','value'=>4]]]]`. Beats 2-3 gated on the prior beat's flag. Use `one()`/`gamble()`, Italian text characterizing each survivor's trait. Set terminal flags listed above. Reuse `highest_stress`/`modify_standing` patterns from the old method. Update `events()`: replace `$this->survivorArc()` with `$this->survivorVoiceArcs()`.

Then in `config/themes/island.php`, update any `witness_flags`/epilogue lines that referenced the old `cole_*`/`survivor_*` keys to the new terminal flags (or add island-flavored epilogue lines for them), so the Task-0 guardian stays green and the epilogue recognizes these arcs.

- [ ] **Step 5: Run the arc test + the guardian — GREEN**

Run: `cd backend && php artisan test --filter="three nominal survivor voice arcs|island: ogni flag"`
Expected: PASS. Remove any now-wired flags from the Task-0 `$ignore` list.

- [ ] **Step 6: No-stall sim + full suite**

Run: `cd backend && php artisan migrate:fresh --seed >/dev/null 2>&1 && php artisan sim:run --count=100 --policy=greedy_survival --theme=island | grep -A3 "Outcomes:"`
Expected: 0 stalls, healthy win/loss spread.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/config/themes/island.php backend/tests/Feature/IslandVoiceArcsTest.php
git commit -m "feat(island): three nominal survivor voice arcs (Nadia/Bruno/Carla)"
```

---

## STRATUM 2 — Island soul

### Task 2: Atmosphere fillers

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (extend `fillerEvents()`)
- Test: `backend/tests/Feature/IslandFillersTest.php`

Add ~14 low-stakes atmosphere events (island currently has ~4 fillers; target ~18). These are `is_filler => true`, small or zero resource effects, high color: jungle nights, the empty sea, sounds, small gestures, a footprint that isn't yours. Keys `fc_island_*` (or extend the existing filler key scheme — read `fillerEvents()` first).

- [ ] **Step 1: Read the current fillerEvents()**

Run: `grep -n "function fillerEvents" backend/database/seeders/IslandEventSeeder.php` then read that method. Note the `is_filler => true`, key scheme, weight.

- [ ] **Step 2: Write the failing test**

```php
it('island has at least 16 filler/atmosphere events', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $count = \App\Models\Event::where('theme','island')->where('is_filler',true)->count();
    expect($count)->toBeGreaterThanOrEqual(16);
});
```

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="at least 16 filler"`
Expected: FAIL (only ~4 fillers).

- [ ] **Step 4: Add ~14 atmosphere fillers**

Extend `fillerEvents()` with ~14 new `ev([... 'is_filler'=>true ...])`. Italian, island-flavored, mostly 1-2 small choices, tiny effects (e.g. `['resource'=>'morale','delta'=>2]`) or pure flavor. No chain flags. Examples to write (vary them): la giungla di notte; il mare piatto e vuoto; un'impronta che non è tua; raccogli conchiglie; il fuoco che scoppietta; un temporale lontano; il relitto che geme; pesci nella secca.

- [ ] **Step 5: Run — GREEN + full suite**

Run: `cd backend && php artisan test --filter="at least 16 filler"`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/tests/Feature/IslandFillersTest.php
git commit -m "feat(island): atmosphere fillers (jungle nights, empty sea, the footprint)"
```

---

### Task 3: Item arcs (radio, seedbank, logbook)

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (add `itemArcs()`)
- Modify: `backend/config/themes/island.php` (epilogue lines for the new terminal flags, if referenced)
- Test: `backend/tests/Feature/IslandItemArcsTest.php`

Three 3-stage arcs (9 events), pattern `arc_seedbank` from space: each stage gated on `['has_item'=>'<item>']` (and the prior stage's flag), choices `set_flag` + `spawn_event` the next stage.
- **`radio`** → `arc_radio_1/2/3`: alternate rescue. Call for help → battery dying → an answer or silence. Terminal `arc_radio_answered`/`arc_radio_silent`.
- **`seedbank`** → `arc_garden_1/2/3`: adapt space's seedbank arc. Plant → the garden thirsts → harvest. Terminal `arc_garden_bloomed`, sets `tended_crops` (if island endings reference it).
- **`logbook`** → `arc_log_1/2/3`: the wreck's diary — seeds the mystery (content only, no mystery system). Find entries → a disturbing detail → a partial truth. Terminal `arc_log_truth` (an aspirational hook for sub-project 3; if no ending reads it, add it to the Task-0 ignore-list WITH a comment "mystery hook, sub-project 3").

- [ ] **Step 1: Read the space seedbank arc as the template**

Run: `sed -n '395,430p' backend/database/seeders/ContentEventSeeder.php`
Note the 3-stage `set_flag` + `spawn_event` + `requires` chaining and the `has_item` gating used elsewhere (grep `has_item` in ContentEventSeeder).

- [ ] **Step 2: Write the failing test**

```php
it('seeds three 3-stage item arcs gated on their item', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['radio','garden','log'] as $arc) {
        $stages = \App\Models\Event::where('theme','island')
            ->where('key','like','arc_'.$arc.'_%')->count();
        expect($stages)->toBe(3, "arc_$arc deve avere 3 stadi");
    }
    // stage 1 of each must gate on has_item
    $r1 = \App\Models\Event::where('theme','island')->where('key','arc_radio_1')->first();
    expect(json_encode($r1->requires))->toContain('has_item');
});
```

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="three 3-stage item arcs"`
Expected: FAIL.

- [ ] **Step 4: Write itemArcs()**

Add `private function itemArcs(): array` returning the 9 events as specified. Register `$this->itemArcs()` in `events()`. Stage-1 `requires` `['all'=>[['has_item'=>'<item>'],['day'=>['op'=>'>=','value'=>5]]]]`; stages 2-3 gated on prior flag. Italian text. Wire terminal flags; update island epilogue config or Task-0 ignore-list as noted for `arc_log_truth`.

- [ ] **Step 5: GREEN + guardian + full suite**

Run: `cd backend && php artisan test --filter="three 3-stage item arcs|island: ogni flag"`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/config/themes/island.php backend/tests/Feature/IslandItemArcsTest.php
git commit -m "feat(island): item arcs — radio (rescue), seedbank (garden), logbook (mystery hook)"
```

---

## STRATUM 3 — Relationships

### Task 4: Pair arcs (3 couples × 2 tones)

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (add `pairArcs()`)
- Test: `backend/tests/Feature/IslandPairArcsTest.php`

6 events mirroring space's `pair_*`. For each couple, a **tension** card (gated on `['alive'=>'A'],['alive'=>'B']`, choices apply `relationship` delta + `modify_standing`) and a **bond** card (gated on `['relationship'=>['a'=>'A','b'=>'B','state'=>'bond']]`). Couples: Nadia-Bruno, Nadia-Carla, Bruno-Carla. Keys `pair_nadia_bruno_clash`/`pair_nadia_bruno_bond`, etc.

- [ ] **Step 1: Read space pair arcs as template**

Run: `sed -n '776,845p' backend/database/seeders/ContentEventSeeder.php`
Note the tension card's `relationship` deltas + `modify_standing`, and the bond card's `['relationship'=>['state'=>'bond']]` gate.

- [ ] **Step 2: Write the failing test**

```php
it('seeds six pair-arc events across the three couples', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $keys = \App\Models\Event::where('theme','island')
        ->where('key','like','pair_%')->pluck('key')->all();
    expect(count($keys))->toBe(6, 'attesi 6 pair-arc, trovati: '.implode(',',$keys));
    // one bond card must gate on a relationship state
    $bond = \App\Models\Event::where('theme','island')->where('key','like','pair_%_bond')->first();
    expect(json_encode($bond->requires))->toContain('bond');
});
```

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="six pair-arc events"`
Expected: FAIL.

- [ ] **Step 4: Write pairArcs()**

Add `private function pairArcs(): array` with 6 events as specified, register in `events()`. Tension cards gate on both `alive`; choices apply `relationship` deltas (favoring one lowers the pair bond, e.g. `-12`, while a "no blame" option raises it `+8`) and `modify_standing`. Bond cards gate on `['relationship'=>['a'=>..,'b'=>..,'state'=>'bond']]`. Italian; reference the survivor voices established in Task 1 (Nadia's risk-taking vs Carla's fear, etc.).

- [ ] **Step 5: GREEN + full suite + sim**

Run: `cd backend && php artisan test --filter="six pair-arc events"`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/tests/Feature/IslandPairArcsTest.php
git commit -m "feat(island): pair arcs — Nadia-Bruno, Nadia-Carla, Bruno-Carla (tension/bond)"
```

---

### Task 5: Cross-reactions (favoring one survivor)

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (add `crossReactions()`)
- Test: `backend/tests/Feature/IslandCrossTest.php`

3 events mirroring space's `cross_*`: when you favor one survivor (tracked via `standing` accumulating), another remarks on it. Keys `cross_bruno_on_nadia`, `cross_carla_on_bruno`, `cross_nadia_on_carla` (one per survivor as the commenter). Gated on a standing-threshold condition (read how space `cross_*` gates — likely a flag set when standing crosses a line, or a direct standing condition).

- [ ] **Step 1: Read space cross events**

Run: `sed -n '1395,1440p' backend/database/seeders/ContentEventSeeder.php`
Note the gate mechanism (what makes `cross_bex_on_anna` fire) and the choice effects (relationship/standing/morale ripples).

- [ ] **Step 2: Write the failing test**

```php
it('seeds three cross-reaction events, one per survivor as commenter', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    $keys = \App\Models\Event::where('theme','island')
        ->where('key','like','cross_%')->pluck('key')->all();
    expect(count($keys))->toBe(3, 'attesi 3 cross, trovati: '.implode(',',$keys));
});
```

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="three cross-reaction events"`
Expected: FAIL.

- [ ] **Step 4: Write crossReactions()**

Add `private function crossReactions(): array` with the 3 events, register in `events()`. Gate each on the same mechanism space uses (replicate it). Italian; the commenter reacts to the favored survivor. Effects ripple relationship/standing/morale.

- [ ] **Step 5: GREEN + full suite**

Run: `cd backend && php artisan test --filter="three cross-reaction events"`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/tests/Feature/IslandCrossTest.php
git commit -m "feat(island): cross-reactions when one survivor is favored"
```

---

## STRATUM 4 — Depth & breathing room

### Task 6: Per-phase depth (Naufragio / Deterioramento / Resa dei conti)

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (extend `phaseEvents()`)
- Test: `backend/tests/Feature/IslandPhaseDepthTest.php`

Island has ~5 phase events; add ~11 (target ~16) spread across the three phases. Phase gating uses `phase_floor`/phase conditions — read how island's existing `phaseEvents()` and space's `iso_/det_/rec_` events gate. Keys `iso_island_*`, `det_island_*`, `rec_island_*` (or extend the existing island phase keys).

- [ ] **Step 1: Read island phaseEvents() + a space phase event**

Run: `grep -n "function phaseEvents" backend/database/seeders/IslandEventSeeder.php` then read it; and `grep -n "'det_\|'iso_\|'rec_" backend/database/seeders/ContentEventSeeder.php | head` then read one of each to see the phase gate.

- [ ] **Step 2: Write the failing test**

```php
it('island has phase-depth events for all three phases', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    foreach (['iso','det','rec'] as $ph) {
        $n = \App\Models\Event::where('theme','island')->where('key','like',$ph.'_%')->count();
        expect($n)->toBeGreaterThanOrEqual(4, "fase $ph deve avere >=4 eventi");
    }
});
```

- [ ] **Step 3: Run — RED**

Run: `cd backend && php artisan test --filter="phase-depth events for all three"`
Expected: FAIL (some phase under 4).

- [ ] **Step 4: Add ~11 phase events**

Extend `phaseEvents()` so each of iso/det/rec has ≥4 island events. Gate each on the appropriate phase condition (replicate the space mechanism). Italian, escalating tone: iso = naufragio/contesto iniziale; det = scorte che calano, attriti; rec = chi parte, chi resta, la verità.

- [ ] **Step 5: GREEN + sim + full suite**

Run: `cd backend && php artisan test --filter="phase-depth events for all three"`
Expected: PASS.
Run: `cd backend && php artisan migrate:fresh --seed >/dev/null 2>&1 && php artisan sim:run --count=200 --policy=greedy_survival --theme=island | grep -A3 "Outcomes:"`
Expected: 0 stalls, healthy spread.
Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/IslandEventSeeder.php backend/tests/Feature/IslandPhaseDepthTest.php
git commit -m "feat(island): per-phase depth events (naufragio/deterioramento/resa dei conti)"
```

---

### Task 7: Expeditions (jungle), tuned for a 3-cast

**Files:**
- Modify: `backend/database/seeders/IslandEventSeeder.php` (add `expeditions()`)
- Modify: `backend/config/themes/island.php` (`relationships.expedition_risk` if not present/needs tuning)
- Test: `backend/tests/Feature/IslandExpeditionsTest.php`

~6 events using the existing `ExpeditionResolver` (already theme-agnostic, reads `config('themes.island.relationships.expedition_risk')`). A departure event sends a survivor into the jungle; return events for outcomes (rich/modest/wounded/lost/discovery). **Tuned for 3 cast:** wound more often than kill; gentle risk. Keys `exp_jungle_depart`, `exp_return_rich/modest/wounded/lost/discovery`.

- [ ] **Step 1: Read the space expedition events + ExpeditionResolver**

Run: `grep -n "'exp_" backend/database/seeders/ContentEventSeeder.php` then read those events; and `sed -n '1,70p' backend/app/Game/Engine/ExpeditionResolver.php` to confirm how departure/return is triggered and what config it reads.

- [ ] **Step 2: Confirm island expedition config**

Run: `php -r "require 'backend/vendor/autoload.php'; \$a=require 'backend/config/themes/island.php'; var_dump(\$a['relationships']['expedition_risk'] ?? 'MISSING');"`
If MISSING, add `relationships.expedition_risk` to `config/themes/island.php` (a gentle value, e.g. lower than space's, since losing 1 of 3 is harsh). Record the value.

- [ ] **Step 3: Write the failing test**

```php
it('seeds a jungle expedition with departure and outcome returns', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);
    expect(\App\Models\Event::where('theme','island')->where('key','exp_jungle_depart')->exists())->toBeTrue();
    $returns = \App\Models\Event::where('theme','island')->where('key','like','exp_return_%')->count();
    expect($returns)->toBeGreaterThanOrEqual(4);
});
```

- [ ] **Step 4: Run — RED**

Run: `cd backend && php artisan test --filter="jungle expedition with departure"`
Expected: FAIL.

- [ ] **Step 5: Write expeditions()**

Add `private function expeditions(): array`, register in `events()`. Mirror space's exp_ structure (departure sends someone away, returns resolve outcomes) but island-flavored (giungla, interno dell'isola, acqua dolce, un altro relitto). Bias outcomes toward `wounded`/`modest` over `lost`/`kill` given the small cast. Italian.

- [ ] **Step 6: GREEN + a fragility check**

Run: `cd backend && php artisan test --filter="jungle expedition with departure"`
Expected: PASS.
Fragility sim — confirm expeditions don't empty runs:
Run: `cd backend && php artisan migrate:fresh --seed >/dev/null 2>&1 && php artisan sim:run --count=300 --policy=greedy_survival --theme=island | grep -A12 "Outcomes:"`
Expected: 0 stalls; win rate still healthy (≈25-35%); deaths not dominated by expedition losses. If runs collapse, soften `expedition_risk` / outcome weights (data only) and re-sim.

- [ ] **Step 7: Full suite + commit**

Run: `cd backend && php artisan test`
Expected: PASS.
```bash
git add backend/database/seeders/IslandEventSeeder.php backend/config/themes/island.php backend/tests/Feature/IslandExpeditionsTest.php
git commit -m "feat(island): jungle expeditions, tuned gentle for the 3-survivor cast"
```

---

## Final verification

- [ ] `cd backend && php artisan test` — all green (309 baseline + new island tests).
- [ ] `cd backend && php artisan test --filter="island: ogni flag"` — guardian green; `$ignore` list contains ONLY documented intentional hooks (e.g. `arc_log_truth` for sub-project 3).
- [ ] Event count check: `php artisan migrate:fresh --seed >/dev/null 2>&1 && php artisan tinker --execute="echo App\\Models\\Event::where('theme','island')->count();"` — island grew from 68 toward ~125-135.
- [ ] Category parity: re-run the prefix breakdown — `pair_`/`cross_`/`arc_`/`exp_` now non-zero; `fc_`/`iso_`/`det_`/`rec_` substantially fuller.
- [ ] `php artisan sim:run --count=2000 --policy=greedy_survival --theme=island` — 0 stalls, healthy distribution, deaths spread across resources. **One sim process at a time** (concurrent sims corrupt the dev SQLite).
- [ ] Space untouched: `php artisan sim:run --count=500 --policy=greedy_survival --theme=space` still healthy; no space test changed.

---

## Notes for the implementing engineer

- **This is creative content.** The plan gives you keys, flags, gates, and structure — YOU write the Italian prose. Make it good: each card is a tiny scene with a real choice and consequence. Match the tone of the existing island events (read a dozen first).
- **Voice before relationships (strata order is deliberate).** Don't write pair arcs (Task 4) referencing survivor traits you haven't established in Task 1. Tasks are ordered so each builds on the last.
- **Every flag you set must be read** — the Task-0 guardian enforces it. If you set a flag, make a card/ending/epilogue read it, or add it to the documented `$ignore` list with a reason. No silent orphans.
- **Italian text, English keys/flags.** Non-negotiable project rule.
- **Content is data.** You should never edit `backend/app/Game/`. If a task seems to need an engine change, STOP — the seam is missing, not the content. The one exception is the test-only change in Task 0.
- **Sim discipline:** one `sim:run` at a time. Concurrent runs corrupt `database/database.sqlite` ("database disk image is malformed"). If it happens: `rm backend/database/database.sqlite && touch ... && php artisan migrate:fresh --seed`.
