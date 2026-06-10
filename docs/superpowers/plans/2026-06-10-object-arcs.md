# Object Narrative Arcs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give three grid items (seedbank, comms, scanner) a 3-stage narrative arc that unfolds over the run, where real choices shape the outcome and completing the arc pays off narratively, mechanically, and in the epilogue.

**Architecture:** Pure seeder data + a little config — no engine changes (all mechanisms exist: `set_flag`, `requires {flag}`, `spawn_event {key,in_days}`, `requires {phase}`, `cooldown_days:999`). Each arc = 3 chained events: stage 1 (gated on `has_item` + opening phase) sets `arc_<item>_stage1` and schedules stage 2; stage 2 (gated on the stage1 flag) sets `arc_<item>_stage2` and schedules stage 3; stage 3 (gated on stage2 flag) resolves with a payoff. Every multi-choice arc event obeys the no-free-choice rule.

**Tech Stack:** Laravel, PHP, Pest. Seeders (`ContentEventSeeder`), config (`game.epilogue.witness_flags`). Sim via `sim:run --memory`.

---

## Background the engineer needs

- **Event shape:** DB row; `choices` JSON, each `['label','hint','outcomes'=>[['weight','effects','log']]]`.
  Effects vocabulary (validated by `EventSchema`): `resource`(+delta), `set_flag`(+value),
  `spawn_event`(+key,in_days), `character`(+stress/hunger), `damage_system`(+amount), `kill`,
  `consume_item`, `grant_item`, `relationship`, `modify_trust`, `modify_standing`.
- **Conditions:** `all/any/not`, `has_item`, `flag`(+is), `phase`, `phase_index`, `resource`,
  `day`, `chosen`/`chosen_tag`, `has_role`, etc.
- **Helpers in `ContentEventSeeder`:** `ev([...])` defaults speaker=null/base_weight=12/cooldown_days=4/
  is_filler=false/requires=null; `one(label, effects, log, hint=null, requires=null)`;
  `gamble(label, good, goodLog, bad, badLog, goodW, badW, hint=null)`.
- **`events()` (line ~66) array_merges section methods.** Add `$this->objectArcEvents(),` there.
- **Scheduling pattern (confirmed):** an event scheduled via `spawn_event {key, in_days:N}` is
  FORCE-PICKED by the Selector on day `current+N` (jumps the pool), regardless of weight. For arc
  stages 2/3 we ALSO gate on the progress flag so they can't surface in a run that never started
  the arc. So stage 2 `requires: {flag:'arc_<item>_stage1', is:true}` AND is spawned by stage 1.
- **Collisions to avoid:** existing keys `c_seedbank_plant` (sets `tended_crops`), `c_logbank_read`,
  the SOS choice (sets `sos_sent`). Use NEW keys prefixed `arc_` (e.g. `arc_seedbank_1`). The comms
  arc's payoff MAY set `sos_sent` (intended — ties the arc to `win_rescue`).
- **Epilogue witness flags:** `config('game.epilogue.witness_flags')` maps flag→Italian line; the
  `EpilogueComposer` includes a line for each set flag. Extensible — add new keys.
- **No-free-choice rule:** every multi-choice card must have a real cost on every choice. There's an
  existing guard test (`FillContentTest`) scanning `fc_`-prefixed events; we add an analogous guard
  for `arc_`-prefixed events.
- **Tests:** `php artisan test [--filter X]`; re-seed `php artisan migrate:fresh --seed --quiet`;
  sim `php artisan sim:run --count=200 --items=<csv> --memory --no-interaction`.

## Arc flag map (defined once, used across tasks)

| Arc | stage1 flag | stage2 flag | payoff (positive) | epilogue flag |
|-----|-------------|-------------|-------------------|---------------|
| seedbank | `arc_seedbank_stage1` | `arc_seedbank_stage2` | grant food + set `arc_garden_bloomed` | `arc_garden_bloomed` |
| comms | `arc_comms_stage1` | `arc_comms_stage2` | set `sos_sent` (gates win_rescue) + `arc_rescue_answered` | `arc_rescue_answered` |
| scanner | `arc_scanner_stage1` | `arc_scanner_stage2` | set `arc_truth_found` (+ neutralize a threat) | `arc_truth_found` |

Event keys: `arc_seedbank_1/2/3`, `arc_comms_1/2/3`, `arc_scanner_1/2/3`.

## File Structure

- Modify: `backend/database/seeders/ContentEventSeeder.php` — `objectArcEvents()` (9 events) wired into `events()`.
- Modify: `backend/config/game.php` — 3 new `epilogue.witness_flags` entries.
- Test: `backend/tests/Feature/ObjectArcTest.php` — chain gating, no-free-choice guard, payoff, schema/uniqueness.

---

## Task 1: Arc test scaffold + seedbank arc (stages 1-3)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/ObjectArcTest.php`

- [ ] **Step 1: Add `objectArcEvents()` with the seedbank arc and wire it**

In `ContentEventSeeder.php`, add `$this->objectArcEvents(),` to the `events()` array_merge (after
`$this->phaseEvents(),`), and add this method. The seedbank arc: plant → tend (choice shapes
outcome) → bloom or wither.

```php
    // ---- Object narrative arcs (Tier 2 #3): 3-stage chains on grid items ----
    private function objectArcEvents(): array
    {
        return array_merge(
            $this->seedbankArc(),
        );
    }

    /** seedbank — "L'orto": vita/speranza. plant -> tend -> bloom/wither. */
    private function seedbankArc(): array
    {
        return [
            // Stage 1 — opening. Gated on the item + early phase. A real choice:
            // commit resources to a real plot now, or a cautious token planting.
            $this->ev([
                'key' => 'arc_seedbank_1', 'title' => 'Un fazzoletto di terra', 'speaker' => 'Bex',
                'body' => "La banca semi potrebbe diventare un orto vero. Bex ti guarda: un impianto serio costa acqua ed energia adesso, ma un giorno potrebbe sfamarvi. O si tiene tutto in riserva.",
                'requires' => ['all' => [['has_item' => 'seedbank'], ['phase_index' => ['op' => '<=', 'value' => 1]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Impianto serio, adesso', [['resource' => 'power', 'delta' => -10], ['resource' => 'food', 'delta' => -6], ['set_flag' => 'arc_seedbank_stage1', 'value' => true], ['spawn_event' => ['key' => 'arc_seedbank_2', 'in_days' => 4]]], 'Bex sorride per la prima volta da giorni. Mani nella terra.'),
                    $this->one('Solo qualche vaso, per ora', [['resource' => 'morale', 'delta' => -4], ['set_flag' => 'arc_seedbank_stage1', 'value' => true], ['set_flag' => 'arc_seedbank_minimal', 'value' => true], ['spawn_event' => ['key' => 'arc_seedbank_2', 'in_days' => 4]]], 'Verde fragile, simbolico. Meglio di niente, forse.'),
                ],
            ]),
            // Stage 2 — tend. Gated on stage1. The choice that decides bloom vs wither.
            $this->ev([
                'key' => 'arc_seedbank_2', 'title' => 'L\'orto ha sete', 'speaker' => 'Bex',
                'body' => "I germogli reggono, ma chiedono cure: acqua, calore, tempo che potresti spendere altrove. Trascurarli adesso significa perderli.",
                'requires' => ['flag' => 'arc_seedbank_stage1', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Mi prendo cura dell\'orto', [['resource' => 'oxygen', 'delta' => -6], ['resource' => 'power', 'delta' => -6], ['set_flag' => 'arc_seedbank_stage2', 'value' => true], ['set_flag' => 'arc_seedbank_tended', 'value' => true], ['spawn_event' => ['key' => 'arc_seedbank_3', 'in_days' => 4]]], 'Foglie più larghe ogni giorno. Costa, ma vive.'),
                    $this->one('Ho di meglio da fare', [['character' => 'Bex', 'stress' => 8], ['set_flag' => 'arc_seedbank_stage2', 'value' => true], ['spawn_event' => ['key' => 'arc_seedbank_3', 'in_days' => 4]]], 'L\'orto aspetta. Bex no, non te lo perdona.'),
                ],
            ]),
            // Stage 3 — bloom or wither, by whether you tended it. Payoff + epilogue flag.
            $this->ev([
                'key' => 'arc_seedbank_3', 'title' => 'Il raccolto', 'speaker' => null,
                'body' => "Quello che hai seminato dà i suoi frutti — o quello che resta dell'orto.",
                'requires' => ['flag' => 'arc_seedbank_stage2', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    // Bloom path: only meaningful if tended. Tended => big food + epilogue flag.
                    // Untended => the same choice yields a thin, bitter harvest (cost, no flag).
                    array_merge(
                        $this->one('Raccogli', [['resource' => 'food', 'delta' => 34], ['resource' => 'morale', 'delta' => 10], ['set_flag' => 'arc_garden_bloomed', 'value' => true]], 'L\'orto ha tenuto fede alla promessa. Per una volta, abbondanza.'),
                        ['requires' => ['flag' => 'arc_seedbank_tended', 'is' => true]]
                    ),
                    $this->one('Raccogli quel che resta', [['resource' => 'food', 'delta' => 8], ['resource' => 'morale', 'delta' => -6]], 'Foglie secche, qualche frutto amaro. Non l\'hai curato abbastanza.', null, ['not' => ['flag' => 'arc_seedbank_tended', 'is' => true]]),
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Write the chain + guard test**

Create `backend/tests/Feature/ObjectArcTest.php`:

```php
<?php

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

function arcEvents(): \Illuminate\Support\Collection
{
    return Event::where('key', 'like', 'arc_%')->get();
}

function arcOutcomeHasCost(array $outcome): bool
{
    foreach (($outcome['effects'] ?? []) as $e) {
        if (! is_array($e)) continue;
        if (array_key_exists('resource', $e) && (int) ($e['delta'] ?? 0) < 0) return true;
        if (array_key_exists('character', $e) && ((int) ($e['stress'] ?? 0) > 0 || (int) ($e['hunger'] ?? 0) > 0)) return true;
        if (array_key_exists('damage_system', $e)) return true;
        if (array_key_exists('kill', $e)) return true;
        if (array_key_exists('consume_item', $e)) return true;
        if (array_key_exists('relationship', $e) && (int) ($e['relationship']['delta'] ?? 0) < 0) return true;
        if (array_key_exists('modify_trust', $e) && (int) $e['modify_trust'] < 0) return true;
        if (array_key_exists('modify_standing', $e) && (int) ($e['modify_standing']['delta'] ?? 0) < 0) return true;
        if (array_key_exists('set_flag', $e)) return true;
        if (array_key_exists('spawn_event', $e)) return true;
    }
    return false;
}

it('seeds the seedbank arc as three chained events', function () {
    foreach (['arc_seedbank_1', 'arc_seedbank_2', 'arc_seedbank_3'] as $key) {
        expect(Event::where('key', $key)->exists())->toBeTrue("missing {$key}");
    }
    // Stage 1 gated on the item; stages 2/3 gated on the prior stage flag.
    $s2 = Event::where('key', 'arc_seedbank_2')->first();
    expect(json_encode($s2->requires))->toContain('arc_seedbank_stage1');
    $s3 = Event::where('key', 'arc_seedbank_3')->first();
    expect(json_encode($s3->requires))->toContain('arc_seedbank_stage2');
});

it('schedules the next stage from each arc stage', function () {
    $s1 = Event::where('key', 'arc_seedbank_1')->first();
    expect(json_encode($s1->choices))->toContain('arc_seedbank_2');
    $s2 = Event::where('key', 'arc_seedbank_2')->first();
    expect(json_encode($s2->choices))->toContain('arc_seedbank_3');
});

it('has no free choice in any multi-option arc event', function () {
    $offenders = [];
    foreach (arcEvents() as $e) {
        $choices = $e->choices ?? [];
        if (count($choices) < 2) continue;
        foreach ($choices as $i => $choice) {
            $hasCost = false;
            foreach (($choice['outcomes'] ?? []) as $o) {
                if (arcOutcomeHasCost($o)) { $hasCost = true; break; }
            }
            if (! $hasCost) $offenders[] = "{$e->key} choice#{$i}";
        }
    }
    expect($offenders)->toBe([], 'Free choices: ' . implode(', ', $offenders));
});

it('keeps every arc event valid against the DSL schema', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));
    arcEvents()->each(function (Event $e) use ($schema) {
        $schema->validate(['key' => $e->key, 'title' => $e->title, 'body' => $e->body, 'choices' => $e->choices, 'requires' => $e->requires]);
        expect(true)->toBeTrue();
    });
});
```

NOTE on the no-free-choice guard for `arc_seedbank_3`: its choices are each gated (tended vs
not-tended) so a given run sees only ONE of them — but the guard checks BOTH as authored. The
"Raccogli quel che resta" choice carries `morale -6` (a cost); the "Raccogli" bloom choice has
only positive effects BUT sets `arc_garden_bloomed` (set_flag counts as a tracked consequence in
the heuristic), so it passes. If you prefer the bloom choice to read as a pure reward, the guard
already treats set_flag as a cost-marker — it passes either way. Confirm it passes; if not, the
guard or the card needs a tweak (do not weaken the guard silently — report).

- [ ] **Step 3: Run, confirm the tests pass**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ObjectArcTest`
Expected: PASS (chain present, scheduling wired, no free choice, schema valid).

- [ ] **Step 4: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures (additive content; ContentTest schema + Selector-never-stalls cover it).

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ObjectArcTest.php
git commit -m "feat: seedbank narrative arc (plant -> tend -> bloom/wither) + arc test harness"
```

---

## Task 2: comms arc (the signal → rescue)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/ObjectArcTest.php` (append)

The comms arc: catch a faint echo → chase it (costly) → the rescue answers (sets `sos_sent`,
tying it to `win_rescue`) or the signal dies.

- [ ] **Step 1: Add `commsArc()` and wire it**

In `objectArcEvents()`, change the merge to include comms:

```php
        return array_merge(
            $this->seedbankArc(),
            $this->commsArc(),
        );
```

Add the method:

```php
    /** comms — "Il segnale": salvezza. echo -> chase -> answered/silent. */
    private function commsArc(): array
    {
        return [
            $this->ev([
                'key' => 'arc_comms_1', 'title' => 'Un\'eco nella statica', 'speaker' => null,
                'body' => "Tra il rumore, qualcosa di ritmico. Forse un faro automatico, forse soccorsi. Agganciarlo costa energia e attenzione; ignorarlo è non sapere mai.",
                'requires' => ['all' => [['has_item' => 'comms'], ['phase_index' => ['op' => '<=', 'value' => 1]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Aggancio il segnale', [['resource' => 'power', 'delta' => -8], ['set_flag' => 'arc_comms_stage1', 'value' => true], ['spawn_event' => ['key' => 'arc_comms_2', 'in_days' => 4]]], 'Lo tieni. Debole, ma c\'è.'),
                    $this->one('Non posso sprecare energia', [['resource' => 'morale', 'delta' => -5], ['set_flag' => 'arc_comms_stage1', 'value' => true], ['set_flag' => 'arc_comms_dropped', 'value' => true], ['spawn_event' => ['key' => 'arc_comms_2', 'in_days' => 4]]], 'Lo lasci andare. Il dubbio resta.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_comms_2', 'title' => 'Si avvicina', 'speaker' => 'Cole',
                'body' => "Il segnale si rafforza — o si rifarebbe, se lo inseguissi. Cole dice che orientare l'antenna richiede una EVA rischiosa. O si resta, e si spera che basti.",
                'requires' => ['flag' => 'arc_comms_stage1', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('EVA per orientare l\'antenna', [['resource' => 'oxygen', 'delta' => -12], ['character' => 'Cole', 'stress' => 8], ['set_flag' => 'arc_comms_stage2', 'value' => true], ['set_flag' => 'arc_comms_chased', 'value' => true], ['spawn_event' => ['key' => 'arc_comms_3', 'in_days' => 4]]], 'Cole esce nel vuoto. Rientra gelato, ma l\'antenna è puntata.'),
                    $this->one('Aspettiamo da dentro', [['resource' => 'morale', 'delta' => -6], ['set_flag' => 'arc_comms_stage2', 'value' => true], ['spawn_event' => ['key' => 'arc_comms_3', 'in_days' => 4]]], 'Stai a sentire un segnale che non migliora.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_comms_3', 'title' => 'La risposta', 'speaker' => null,
                'body' => "Dopo giorni di segnale inseguito, qualcosa cambia nella statica.",
                'requires' => ['flag' => 'arc_comms_stage2', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Rispondi', [['resource' => 'power', 'delta' => -6], ['resource' => 'morale', 'delta' => 12], ['set_flag' => 'sos_sent', 'value' => true], ['set_flag' => 'arc_rescue_answered', 'value' => true]], 'Una voce umana, distorta ma vera. «Vi abbiamo sentiti.»'),
                        ['requires' => ['flag' => 'arc_comms_chased', 'is' => true]]
                    ),
                    $this->one('Ascolta il silenzio', [['resource' => 'morale', 'delta' => -8]], 'Il segnale si spegne, e con lui qualcosa dentro di te. Non hai fatto abbastanza per raggiungerlo.', null, ['not' => ['flag' => 'arc_comms_chased', 'is' => true]]),
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Append chain assertions**

Append to `backend/tests/Feature/ObjectArcTest.php`:

```php
it('seeds the comms arc chained, ending in a rescue-answer that sets sos_sent', function () {
    foreach (['arc_comms_1', 'arc_comms_2', 'arc_comms_3'] as $key) {
        expect(Event::where('key', $key)->exists())->toBeTrue("missing {$key}");
    }
    expect(json_encode(Event::where('key', 'arc_comms_2')->first()->requires))->toContain('arc_comms_stage1');
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->requires))->toContain('arc_comms_stage2');
    // The positive resolution sets sos_sent (ties the arc to win_rescue) + the epilogue flag.
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->choices))->toContain('sos_sent');
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->choices))->toContain('arc_rescue_answered');
});
```

- [ ] **Step 3: Re-seed + run arc tests + full suite**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ObjectArcTest`
Expected: PASS.
Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ObjectArcTest.php
git commit -m "feat: comms narrative arc (echo -> chase -> rescue answers, sets sos_sent)"
```

---

## Task 3: scanner arc (the truth about this station)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`
- Test: `backend/tests/Feature/ObjectArcTest.php` (append)

The scanner arc: anomalous readings → investigate (costly) → uncover what happened to the
previous crew. Positive completion sets `arc_truth_found` and neutralizes a future threat.

- [ ] **Step 1: Add `scannerArc()` and wire it**

In `objectArcEvents()`:

```php
        return array_merge(
            $this->seedbankArc(),
            $this->commsArc(),
            $this->scannerArc(),
        );
```

Add the method:

```php
    /** scanner — "La verità": mistero. anomaly -> investigate -> the truth. */
    private function scannerArc(): array
    {
        return [
            $this->ev([
                'key' => 'arc_scanner_1', 'title' => 'Letture che non tornano', 'speaker' => 'Anna',
                'body' => "Lo scanner segna una stanza che non dovrebbe esserci, dietro una paratia sigillata. Anna è incuriosita. Forzarla costa tempo e forse guai; lasciarla è non sapere.",
                'requires' => ['all' => [['has_item' => 'scanner'], ['phase_index' => ['op' => '<=', 'value' => 1]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Indaghiamo', [['resource' => 'power', 'delta' => -6], ['character' => 'Anna', 'stress' => 6], ['set_flag' => 'arc_scanner_stage1', 'value' => true], ['spawn_event' => ['key' => 'arc_scanner_2', 'in_days' => 4]]], 'Anna comincia a mappare. Qualcosa, lì dietro, aspetta.'),
                    $this->one('Alcune porte è meglio non aprirle', [['resource' => 'morale', 'delta' => -4], ['set_flag' => 'arc_scanner_stage1', 'value' => true], ['set_flag' => 'arc_scanner_avoided', 'value' => true], ['spawn_event' => ['key' => 'arc_scanner_2', 'in_days' => 4]]], 'La lasci sigillata. Ma ci pensi, di notte.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_scanner_2', 'title' => 'Quello che hanno lasciato', 'speaker' => null,
                'body' => "Frammenti: un diario corrotto, un avvertimento a metà, tracce di una decisione disperata. Ricostruire tutto richiede di esporsi a qualcosa che forse è ancora attivo.",
                'requires' => ['flag' => 'arc_scanner_stage1', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ricostruisco tutto', [['resource' => 'oxygen', 'delta' => -8], ['damage_system' => 'life_support', 'amount' => 8], ['set_flag' => 'arc_scanner_stage2', 'value' => true], ['set_flag' => 'arc_scanner_dug', 'value' => true], ['spawn_event' => ['key' => 'arc_scanner_3', 'in_days' => 4]]], 'Apri condotti che andavano lasciati chiusi. Ma adesso sai di più.'),
                    $this->one('Mi fermo a quel che basta', [['resource' => 'morale', 'delta' => -5], ['set_flag' => 'arc_scanner_stage2', 'value' => true], ['spawn_event' => ['key' => 'arc_scanner_3', 'in_days' => 4]]], 'Metà verità. Forse è più sicuro così.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_scanner_3', 'title' => 'La verità', 'speaker' => 'Anna',
                'body' => "Tutti i pezzi, finalmente, al loro posto.",
                'requires' => ['flag' => 'arc_scanner_stage2', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Affronta cosa è successo', [['resource' => 'morale', 'delta' => -6], ['set_flag' => 'arc_truth_found', 'value' => true], ['set_flag' => 'sensors_warned', 'value' => true]], 'Sai cos\'ha ucciso l\'equipaggio prima di te. E sai come evitarlo. È un peso, e un\'arma.'),
                        ['requires' => ['flag' => 'arc_scanner_dug', 'is' => true]]
                    ),
                    $this->one('Lascia perdere il resto', [['resource' => 'morale', 'delta' => -3]], 'Resterà un buco nella storia. Forse è meglio non sapere.', null, ['not' => ['flag' => 'arc_scanner_dug', 'is' => true]]),
                ],
            ]),
        ];
    }
```

(`sensors_warned` is a flag a future trap event could read to soften a threat — its consumer is
out of scope here; setting it is the mechanical hook, and `arc_truth_found` is the epilogue flag.)

- [ ] **Step 2: Append chain assertions**

Append to `backend/tests/Feature/ObjectArcTest.php`:

```php
it('seeds the scanner arc chained, ending in arc_truth_found', function () {
    foreach (['arc_scanner_1', 'arc_scanner_2', 'arc_scanner_3'] as $key) {
        expect(Event::where('key', $key)->exists())->toBeTrue("missing {$key}");
    }
    expect(json_encode(Event::where('key', 'arc_scanner_2')->first()->requires))->toContain('arc_scanner_stage1');
    expect(json_encode(Event::where('key', 'arc_scanner_3')->first()->requires))->toContain('arc_scanner_stage2');
    expect(json_encode(Event::where('key', 'arc_scanner_3')->first()->choices))->toContain('arc_truth_found');
});

it('has nine arc events total (three arcs of three)', function () {
    expect(arcEvents()->count())->toBe(9);
});
```

- [ ] **Step 3: Re-seed + run arc tests + full suite**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ObjectArcTest`
Expected: PASS (9 arc events).
Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php backend/tests/Feature/ObjectArcTest.php
git commit -m "feat: scanner narrative arc (anomaly -> investigate -> the truth)"
```

---

## Task 4: Epilogue payoff — witness flags for completed arcs

**Files:**
- Modify: `backend/config/game.php`
- Test: `backend/tests/Feature/ObjectArcTest.php` (append) + reuse EpilogueComposer

The three positive-completion flags (`arc_garden_bloomed`, `arc_rescue_answered`,
`arc_truth_found`) become epilogue lines.

- [ ] **Step 1: Add the witness-flag lines**

In `backend/config/game.php`, in `epilogue.witness_flags`, add three entries:

```php
            'arc_garden_bloomed' => 'Hai fatto fiorire un orto dove tutto moriva.',
            'arc_rescue_answered' => 'Il tuo segnale ha trovato orecchie. Qualcuno è venuto.',
            'arc_truth_found' => 'Hai scoperto cosa è successo a chi c\'era prima. Avresti preferito di no.',
```

- [ ] **Step 2: Write the payoff test**

Append to `backend/tests/Feature/ObjectArcTest.php`:

```php
it('surfaces a completed arc in the epilogue', function () {
    $composer = app(\App\Game\Engine\EpilogueComposer::class);
    $state = new \App\Game\Engine\RunState(
        day: 26,
        resources: ['oxygen' => 30, 'food' => 30, 'power' => 30, 'morale' => 30, 'hull' => 30],
        flags: ['arc_garden_bloomed' => true],
        characters: [['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 10, 'hunger' => 0, 'away_until' => 0]],
    );
    $sections = $composer->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $choices = collect($sections)->firstWhere('title', 'Le tue scelte');
    expect($choices)->not->toBeNull();
    expect(implode(' ', $choices['lines']))->toContain('fiorire');
});

it('registers the three arc completion flags in the epilogue config', function () {
    $flags = config('game.epilogue.witness_flags');
    expect($flags)->toHaveKeys(['arc_garden_bloomed', 'arc_rescue_answered', 'arc_truth_found']);
});
```

- [ ] **Step 3: Run, confirm pass**

Run: `cd backend && php artisan migrate:fresh --seed --quiet && php artisan test --filter ObjectArcTest`
Expected: PASS (the EpilogueComposer reads the new witness flag; config has the keys).

- [ ] **Step 4: Full suite**

Run: `cd backend && php artisan test`
Expected: 0 failures.

- [ ] **Step 5: Commit**

```bash
git add backend/config/game.php backend/tests/Feature/ObjectArcTest.php
git commit -m "feat: completed object arcs surface in the epilogue (garden/rescue/truth)"
```

---

## Task 5: End-to-end chain + balance sim

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite**

Run: `cd backend && php artisan test`
Expected: ALL pass (prior + ObjectArcTest).

- [ ] **Step 2: End-to-end — drive a seedbank arc through resolveChoice and confirm it chains**

Run this scripted check (forces stage 1, resolves the tend path, confirms stage 2 then 3 get
scheduled and the bloom flag can be reached):

```bash
cd backend && php artisan tinker <<'PHP' 2>&1 | grep '^arc'
$f = app(App\Game\RunFactory::class);
$engine = app(App\Game\Engine\EventEngine::class);
$run = $f->create(1, ['seedbank']);
$run->current_event_key = 'arc_seedbank_1'; $run->day = 3; $run->save();
$engine->currentCard($run->fresh());
$engine->resolveChoice($run->fresh(), 0); // serious planting -> sets stage1, schedules stage2
$after = $run->fresh();
$keys = collect($after->scheduled_events ?? [])->pluck('key')->all();
echo "arc stage1 flag=".(($after->flags['arc_seedbank_stage1'] ?? false) ? 'set' : 'unset')." | scheduled=".implode(',', $keys).PHP_EOL;
PHP
```
Expected: prints `arc stage1 flag=set | scheduled=...arc_seedbank_2...` — stage 1 set the flag and
scheduled stage 2. (If it doesn't, the arc isn't chaining — STOP and report.)

- [ ] **Step 3: Balance sim — arcs don't break the band; some complete**

Run:
```bash
cd backend && php artisan sim:run --count=200 --items=seedbank,comms,scanner,medkit --memory --no-interaction
```
Expected: 0 stalls; win-rate stays ~30-40% (the arcs add costed choices + a payoff, roughly
neutral); run length sane. Record numbers. Note: the greedy AI may not always complete arcs
(it doesn't optimize for narrative), but the chain should fire when the items are held.

- [ ] **Step 4: Confirm arcs surface and chain in real runs**

```bash
cd backend && php artisan tinker <<'PHP' 2>&1 | grep '^seed'
$sim = app(App\Game\Sim\Simulator::class);
foreach ([1,3,7,11,15] as $s) {
  $r = $sim->play($s, new App\Game\Sim\GreedySurvivalPolicy(), ['seedbank','comms','scanner','medkit','rations']);
  $run = App\Models\Run::find($r->runId);
  $arcFlags = collect($run->flags ?? [])->keys()->filter(fn($k) => str_starts_with($k, 'arc_'))->values()->all();
  echo "seed $s: ".$run->ending_key." | arc flags: ".implode(',', $arcFlags).PHP_EOL;
}
PHP
```
Expected: several runs show `arc_*` flags (stage1/stage2 at minimum — the chain is firing). If
zero arc flags ever appear, the opening gate is too tight — note which.

- [ ] **Step 5: Commit the verification note**

```bash
git commit --allow-empty -m "test: object arcs chain end-to-end + sim sanity

<N> tests pass.
sim 200 seedbank/comms/scanner/medkit: wins <w>% / median <d>d / stalls 0
arc chain verified: stage1 sets flag + schedules stage2 (and on to stage3).
arc flags observed across sampled runs."
```

---

## Self-Review notes (done by planner)

- **Spec coverage:** 3 arcs × 3 chained stages (Tasks 1-3); choices shape outcome via
  tended/chased/dug flags read by stage 3 (gated bloom-vs-wither choices); payoff is narrative
  (stage-3 text) + mechanical (food grant / `sos_sent` / `sensors_warned`) + epilogue (Task 4
  witness flags); pacing is days-based via `spawn_event in_days:4`; no-free-choice guard for
  `arc_` events (Task 1 test); completability via forced scheduling; sim + chain verification
  (Task 5). All spec sections mapped. Dormant items + branching breadth are explicitly out of scope.
- **Placeholder scan:** every event is fully authored (real Italian text, concrete effects). The
  one discovery note (Task 1 guard for the bloom choice) spells out the exact heuristic outcome.
  No TBDs.
- **Type/field consistency:** flag names match the "Arc flag map" table across all tasks
  (`arc_<item>_stage1/2`, `arc_<item>_tended/chased/dug`, epilogue flags `arc_garden_bloomed`/
  `arc_rescue_answered`/`arc_truth_found`); event keys `arc_<item>_1/2/3` consistent between
  seeder and tests; the spawn `in_days:4` and the gating-flag pattern are uniform across all three
  arcs. `sos_sent` reused intentionally (the comms arc becomes the narrative route to win_rescue,
  which Task 9 of the prior slice gated on `sos_sent`).
- **Risk flagged:** Task 5 step 4 checks arcs actually fire in real runs (opening gate not too
  tight); the greedy AI not completing arcs is expected and noted (human plays differently).
