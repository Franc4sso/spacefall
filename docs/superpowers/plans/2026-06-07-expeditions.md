# Spedizioni (Expeditions) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mandi un membro dell'equipaggio fuori; resta "via" N giorni; torna con un esito (bottino/ferite/morte/scoperta) le cui probabilità rispondono a stato/attrezzatura/durata/pericolosità.

**Architecture:** Introdurre la distinzione **presente vs vivo** (`away_until` per personaggio; chi è via è vivo ma non interagisce con la stazione). La partenza è un campo `expedition` a livello di scelta, risolto dall'`EventEngine` con un `ExpeditionResolver` puro che tira il tier e schedula l'evento di ritorno. Effetti `end_expedition` + selettore `expeditioner` chiudono il ciclo. Contenuto: carte-opportunità + eventi di ritorno. Tutto default-safe (senza `away_until`, nulla cambia).

**Tech Stack:** PHP 8.3 / Laravel 12 / SQLite · Pest (TDD) · React 19 / TypeScript / Vite (`npx tsc --noEmit`).

**Convenzioni:**
- `away_until` (giorno di ritorno) vive nell'array `characters`. "Presente" = `alive && (away_until ?? 0) <= day`.
- Una sola spedizione alla volta: flag `expedition_active`, `away_member` (nome), `away_days` (durata).
- La morte da spedizione è opt-in (scelte "Manda" con hint "molto pericoloso" → il simulatore cauto sceglie "Lascia perdere").

---

## FASE 1 — PRESENTE VS VIVO

### Task 1: Filtro "presente" nel targeting degli effetti

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Modify: `backend/app/Game/RunFactory.php`
- Test: `backend/tests/Unit/EffectApplierTest.php`

Chi è via non viene colpito né nutrito dagli effetti di stazione (`all`/`random`/`hungriest`/selettori). `RunState` ha `day`.

- [ ] **Step 1: Scrivi i test**

In fondo a `backend/tests/Unit/EffectApplierTest.php`:

```php
it('excludes away crew from "all" targeting', function () {
    $s = freshState([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0],
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 8], // away (8 > 5)
        ],
    ]);
    applier()->apply([['character' => 'all', 'stress' => 10]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(10); // Anna present -> hit
    expect($s->characters[1]['stress'])->toBe(0);  // Bex away -> spared
});

it('excludes away crew from selector targeting', function () {
    $s = freshState([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 90, 'away_until' => 8], // hungriest but away
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 40],
        ],
    ]);
    applier()->apply([['character' => 'hungriest', 'hunger' => -20]], $s, new SeededRng(1));
    expect($s->characters[0]['hunger'])->toBe(90); // away -> untouched
    expect($s->characters[1]['hunger'])->toBe(20); // present hungriest
});

it('treats crew as present once their return day arrives', function () {
    $s = freshState([
        'day' => 8,
        'characters' => [
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 8], // back today (8 <= 8)
        ],
    ]);
    applier()->apply([['character' => 'all', 'stress' => 5]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(5);
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php --filter "away"`
Atteso: FAIL.

- [ ] **Step 3: Aggiungi l'helper `isPresent` e usalo nel targeting**

In `backend/app/Game/Engine/EffectApplier.php`, aggiungi un helper privato (vicino a `livingIndices`):

```php
    /** Present = alive and not currently away on an expedition. */
    private function isPresent(array $c, RunState $state): bool
    {
        return ($c['alive'] ?? true) && (int) ($c['away_until'] ?? 0) <= $state->day;
    }
```

In `livingIndices()`, sostituisci la condizione:

```php
    /** @return list<int> indices of present survivors (alive, not away) */
    private function livingIndices(RunState $state): array
    {
        $out = [];
        foreach ($state->characters as $i => $c) {
            if ($this->isPresent($c, $state)) {
                $out[] = $i;
            }
        }
        return $out;
    }
```

In `resolveTarget()`, sostituisci il filtro `$living`:

```php
        $living = [];
        foreach ($state->characters as $i => $c) {
            if ($this->isPresent($c, $state)) {
                $living[$i] = $c;
            }
        }
```

- [ ] **Step 4: Inizializza `away_until` nel roster**

In `backend/app/Game/RunFactory.php`, in `roster()`, aggiungi `'away_until' => 0` a ogni membro (dopo `'hunger' => 0`).

- [ ] **Step 5: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php`
Atteso: tutti PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php backend/app/Game/RunFactory.php backend/tests/Unit/EffectApplierTest.php
git commit -m "feat: present-vs-alive — away crew excluded from station effect targeting"
```

---

### Task 2: ConditionEvaluator — has_role e crew_hunger sui presenti

**Files:**
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Test: `backend/tests/Unit/ConditionEvaluatorTest.php`

- [ ] **Step 1: Scrivi i test**

In fondo a `backend/tests/Unit/ConditionEvaluatorTest.php`:

```php
it('ignores away crew for has_role', function () {
    $s = stateWith([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'alive' => true, 'away_until' => 9],
        ],
    ]);
    expect($this->eval->evaluate(['has_role' => 'engineer'], $s))->toBeFalse();
});

it('ignores away crew for crew_hunger', function () {
    $s = stateWith([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'hunger' => 90, 'away_until' => 9],
            ['name' => 'Bex', 'alive' => true, 'hunger' => 10],
        ],
    ]);
    expect($this->eval->evaluate(['crew_hunger' => ['op' => '>=', 'value' => 50]], $s))->toBeFalse();
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/ConditionEvaluatorTest.php --filter "away"`
Atteso: FAIL.

- [ ] **Step 3: Aggiungi l'helper e usalo**

In `backend/app/Game/Engine/ConditionEvaluator.php`, aggiungi un helper privato (in fondo alla classe, vicino a `compare`):

```php
    private function isPresent(array $c, RunState $state): bool
    {
        return ($c['alive'] ?? true) && (int) ($c['away_until'] ?? 0) <= $state->day;
    }
```

Nel blocco `has_role`, sostituisci `($c['alive'] ?? true)` con `$this->isPresent($c, $state)`:

```php
        if (array_key_exists('has_role', $condition)) {
            foreach ($state->characters as $c) {
                if (($c['role'] ?? null) === $condition['has_role'] && $this->isPresent($c, $state)) {
                    return true;
                }
            }
            return false;
        }
```

Nel blocco `crew_hunger`, sostituisci `($c['alive'] ?? true)` con `$this->isPresent($c, $state)`:

```php
        if (array_key_exists('crew_hunger', $condition)) {
            $spec = $condition['crew_hunger'];
            foreach ($state->characters as $c) {
                if ($this->isPresent($c, $state) && $this->compare((int) ($c['hunger'] ?? 0), $spec['op'] ?? '=', $spec['value'] ?? 0)) {
                    return true;
                }
            }
            return false;
        }
```

- [ ] **Step 4: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/ConditionEvaluatorTest.php`
Atteso: tutti PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/ConditionEvaluator.php backend/tests/Unit/ConditionEvaluatorTest.php
git commit -m "feat: has_role and crew_hunger consider only present crew"
```

---

### Task 3: DayProcessor — la Fame salta chi è in spedizione

**Files:**
- Modify: `backend/app/Game/DayProcessor.php`
- Test: `backend/tests/Feature/HungerLoopTest.php`

- [ ] **Step 1: Scrivi il test**

In fondo a `backend/tests/Feature/HungerLoopTest.php` (la `beforeEach` attiva già la fame):

```php
it('does not make away crew hungrier', function () {
    $run = Run::factory()->create([
        'day' => 3,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 50, 'away_until' => 7, 'alive' => true],
        ],
    ]);

    app(DayProcessor::class)->advance($run);

    expect($run->fresh()->characters[0]['hunger'])->toBe(50); // away -> unchanged
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

Run: `cd backend && php artisan test tests/Feature/HungerLoopTest.php --filter "away crew"`
Atteso: FAIL.

- [ ] **Step 3: Salta i non-presenti in `applyHunger`**

In `backend/app/Game/DayProcessor.php`, nel metodo `applyHunger()`, cambia la guardia di inizio ciclo da `if (! ($c['alive'] ?? true))` a una che salta anche chi è via. `applyHunger` non riceve il giorno corrente dal proprio scope? Riceve `$day` (parametro). Sostituisci:

```php
            if (! ($c['alive'] ?? true)) {
                continue;
            }
```

con:

```php
            // Skip the dead and anyone away on an expedition (they don't eat at
            // the table; their fate is decided by the expedition return).
            if (! ($c['alive'] ?? true) || (int) ($c['away_until'] ?? 0) > $day) {
                continue;
            }
```

- [ ] **Step 4: Esegui il test (deve passare)**

Run: `cd backend && php artisan test tests/Feature/HungerLoopTest.php`
Atteso: tutti PASS.

- [ ] **Step 5: Esegui la suite**

Run: `cd backend && php artisan test`
Atteso: tutti PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/DayProcessor.php backend/tests/Feature/HungerLoopTest.php
git commit -m "feat: hunger pipeline skips away crew"
```

---

## FASE 2 — MECCANICA SPEDIZIONE

### Task 4: Selettore `expeditioner` + effetto `end_expedition`

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Test: `backend/tests/Unit/EffectApplierTest.php`

`expeditioner` = il membro via (dal flag `away_member`), così gli eventi di ritorno lo colpiscono benché escluso dal filtro presente. `end_expedition` lo riporta presente, applica la botta provata scalata su `away_days`, e pulisce i flag.

- [ ] **Step 1: Scrivi i test**

In fondo a `backend/tests/Unit/EffectApplierTest.php`:

```php
it('resolves the expeditioner selector from the away_member flag', function () {
    $s = freshState([
        'day' => 7,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 7],
            ['name' => 'Bex', 'alive' => true, 'stress' => 0, 'hunger' => 0],
        ],
    ]);
    $s->flags['away_member'] = 'Anna';
    applier()->apply([['character' => 'expeditioner', 'stress' => 15]], $s, new SeededRng(1));
    expect($s->characters[0]['stress'])->toBe(15); // Anna (the expeditioner)
    expect($s->characters[1]['stress'])->toBe(0);
});

it('ends an expedition: clears away state and applies a duration-scaled toll', function () {
    $s = freshState([
        'day' => 7,
        'characters' => [
            ['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 7],
        ],
    ]);
    $s->flags['away_member'] = 'Anna';
    $s->flags['away_days'] = 4;
    $s->flags['expedition_active'] = true;

    applier()->apply([['end_expedition' => true]], $s, new SeededRng(1));

    expect($s->characters[0]['away_until'])->toBe(0);     // present again
    expect($s->characters[0]['hunger'])->toBe(16);        // 4 days * 4
    expect($s->characters[0]['stress'])->toBe(8);         // 4 days * 2
    expect($s->flags['away_member'] ?? null)->toBeNull();
    expect($s->flags['expedition_active'] ?? null)->toBeNull();
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php --filter "expeditioner|expedition"`
Atteso: FAIL.

- [ ] **Step 3: Aggiungi il ramo `expeditioner` in `resolveTarget`**

In `backend/app/Game/Engine/EffectApplier.php`, in `resolveTarget()`, PRIMA della costruzione del pool `$living` (in cima al metodo), aggiungi il caso speciale:

```php
        // The expeditioner is away (excluded from the present pool), so resolve
        // it directly from the flag rather than the present-crew list.
        if ($selector === 'expeditioner') {
            $name = $state->flags['away_member'] ?? null;
            if ($name === null) {
                return null;
            }
            foreach ($state->characters as $i => $c) {
                if (($c['name'] ?? null) === $name && ($c['alive'] ?? true)) {
                    return $i;
                }
            }
            return null;
        }
```

- [ ] **Step 4: Aggiungi l'effetto `end_expedition` in `applyOne`**

In `applyOne()`, dopo il blocco `modify_standing` (prima del commento `// Unknown effect`), aggiungi:

```php
        if (array_key_exists('end_expedition', $effect)) {
            $name = $state->flags['away_member'] ?? null;
            $days = (int) ($state->flags['away_days'] ?? 0);
            if ($name !== null) {
                foreach ($state->characters as $i => $c) {
                    if (($c['name'] ?? null) === $name) {
                        $state->characters[$i]['away_until'] = 0;
                        $state->characters[$i]['hunger'] = $this->clamp((int) ($c['hunger'] ?? 0) + $days * 4, 100);
                        $state->characters[$i]['stress'] = $this->clamp((int) ($c['stress'] ?? 0) + $days * 2, 100);
                    }
                }
            }
            unset($state->flags['away_member'], $state->flags['away_days'], $state->flags['expedition_active']);
            return;
        }
```

- [ ] **Step 5: Registra `end_expedition` nello schema**

In `backend/app/Game/Engine/EventSchema.php`, in `EFFECT_KEYS`, aggiungi `'end_expedition'` (dopo `'modify_standing'`).

- [ ] **Step 6: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php`
Atteso: tutti PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php backend/app/Game/Engine/EventSchema.php backend/tests/Unit/EffectApplierTest.php
git commit -m "feat: expeditioner selector and end_expedition effect"
```

---

### Task 5: `ExpeditionResolver` — punteggio di rischio e tier

**Files:**
- Create: `backend/app/Game/Engine/ExpeditionResolver.php`
- Test: `backend/tests/Unit/ExpeditionResolverTest.php`

Servizio puro. `score()` è deterministico (testabile direttamente): più basso = più sicuro. `resolve()` usa il punteggio + RNG per pescare un tier.

- [ ] **Step 1: Scrivi i test**

Crea `backend/tests/Unit/ExpeditionResolverTest.php`:

```php
<?php

use App\Game\Engine\ExpeditionResolver;
use App\Game\Engine\RunState;
use App\Game\SeededRng;

function expState(array $chars, array $items = []): RunState
{
    return new RunState(day: 5, resources: [], characters: $chars, items: $items);
}

it('scores a fresh, fed, equipped expeditioner to a nearby target as low risk', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $low = $r->score('Anna', days: 2, danger: 1, state: expState($chars, ['spacesuit', 'scanner']));
    $high = $r->score('Anna', days: 5, danger: 3, state: expState(
        [['name' => 'Anna', 'alive' => true, 'stress' => 80, 'hunger' => 80, 'traits' => []]]
    ));
    expect($low)->toBeLessThan($high);
});

it('lowers risk with relevant gear', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $bare = $r->score('Anna', 2, 2, expState($chars, []));
    $geared = $r->score('Anna', 2, 2, expState($chars, ['spacesuit', 'scanner', 'drone']));
    expect($geared)->toBeLessThan($bare);
});

it('resolve returns one of the known tiers', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $tier = $r->resolve('Anna', 3, 2, expState($chars), new SeededRng(1));
    expect(['rich', 'modest', 'wounded', 'lost', 'discovery'])->toContain($tier);
});

it('a very safe trip skews away from lost across seeds', function () {
    $r = new ExpeditionResolver();
    $chars = [['name' => 'Anna', 'alive' => true, 'stress' => 0, 'hunger' => 0, 'traits' => []]];
    $lost = 0;
    for ($seed = 0; $seed < 60; $seed++) {
        if ($r->resolve('Anna', 2, 1, expState($chars, ['spacesuit', 'scanner']), new SeededRng($seed)) === 'lost') {
            $lost++;
        }
    }
    expect($lost)->toBeLessThan(6); // < ~10% on a safe trip
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/ExpeditionResolverTest.php`
Atteso: FAIL (classe inesistente).

- [ ] **Step 3: Implementa `ExpeditionResolver`**

Crea `backend/app/Game/Engine/ExpeditionResolver.php`:

```php
<?php

namespace App\Game\Engine;

use App\Game\SeededRng;

/**
 * Decides how an expedition turns out. Pure and deterministic given the RNG.
 *
 * `score()` is a risk number (lower = safer) from the destination danger, the
 * expeditioner's state (stress/hunger), gear carried, the duration, and traits.
 * `resolve()` maps that risk onto weighted outcome tiers and draws one.
 */
final class ExpeditionResolver
{
    private const TIERS = ['rich', 'modest', 'wounded', 'lost', 'discovery'];

    /** Items that make a trip safer. */
    private const GEAR = ['spacesuit', 'scanner', 'drone', 'medkit'];

    /** Lower = safer. */
    public function score(string $who, int $days, int $danger, RunState $state): int
    {
        $char = $this->findByName($who, $state);
        $stress = (int) ($char['stress'] ?? 0);
        $hunger = (int) ($char['hunger'] ?? 0);
        $traits = $char['traits'] ?? [];

        $gear = 0;
        foreach (self::GEAR as $item) {
            if (in_array($item, $state->items, true)) {
                $gear++;
            }
        }

        $traitShift = 0;
        if (in_array('lucky', $traits, true)) {
            $traitShift -= 4;
        }
        if (in_array('reckless', $traits, true)) {
            $traitShift += 4;
        }

        return ($danger * 6)
            + (int) (($stress + $hunger) / 10)
            + max(0, $days - 2) * 2
            - ($gear * 4)
            + $traitShift;
    }

    /** Draw an outcome tier weighted by the risk score. */
    public function resolve(string $who, int $days, int $danger, RunState $state, SeededRng $rng): string
    {
        $risk = $this->score($who, $days, $danger, $state);

        // Base weights at neutral risk, then bend by risk: high risk pushes
        // toward wounded/lost, low risk toward rich/discovery.
        $weights = [
            'rich'      => max(1, 6 - $risk),
            'modest'    => 5,
            'wounded'   => max(1, 2 + (int) ($risk / 2)),
            'lost'      => max(1, 1 + (int) ($risk / 3)),
            'discovery' => max(1, 4 - (int) ($risk / 2)),
        ];

        return $rng->weightedPick($weights);
    }

    /** @return array<string,mixed> */
    private function findByName(string $who, RunState $state): array
    {
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $who) {
                return $c;
            }
        }
        return [];
    }
}
```

- [ ] **Step 4: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/ExpeditionResolverTest.php`
Atteso: tutti PASS. (Se "skews away from lost" fallisce, alza il peso base di `rich`/`discovery` o abbassa quello di `lost` finché una meta sicura rende < ~10% di morti.)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/ExpeditionResolver.php backend/tests/Unit/ExpeditionResolverTest.php
git commit -m "feat: ExpeditionResolver — risk score and outcome tier"
```

---

### Task 6: EventEngine — la partenza (campo `expedition` sulla scelta)

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Test: `backend/tests/Feature/ExpeditionTest.php`

`EventEngine` riceve `ExpeditionResolver` (autowiring). In `resolveChoice`, dopo gli effetti e le reazioni, se la scelta porta un campo `expedition`, segna chi è via, imposta i flag, tira il tier e schedula `exp_return_<tier>`.

- [ ] **Step 1: Scrivi il test**

Crea `backend/tests/Feature/ExpeditionTest.php`:

```php
<?php

use App\Game\Engine\EventEngine;
use App\Models\Event;
use App\Models\Run;

it('dispatches an expedition: marks away, sets flags, schedules a return', function () {
    Event::create([
        'key' => 'exp_test', 'title' => 'Relitto', 'body' => 'Segnali deboli.',
        'speaker' => null, 'base_weight' => 1, 'cooldown_days' => 0, 'is_filler' => false,
        'requires' => null, 'weight_modifiers' => null,
        'choices' => [
            [
                'label' => 'Manda Anna', 'hint' => 'molto pericoloso', 'tags' => [],
                'expedition' => ['who' => 'Anna', 'days' => 3, 'danger' => 2],
                'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'Anna sparisce nel portello.']],
            ],
        ],
    ]);

    $run = Run::factory()->create([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 0, 'alive' => true],
        ],
    ]);
    $run->current_event_key = 'exp_test';
    $run->save();

    app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    $fresh = $run->fresh();
    expect($fresh->characters[0]['away_until'])->toBe(8); // day 5 + 3
    expect($fresh->flags['away_member'])->toBe('Anna');
    expect($fresh->flags['away_days'])->toBe(3);
    expect($fresh->flags['expedition_active'])->toBeTrue();

    $scheduledKeys = collect($fresh->scheduled_events)->pluck('key');
    expect($scheduledKeys->filter(fn ($k) => str_starts_with($k, 'exp_return_')))->not->toBeEmpty();
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

Run: `cd backend && php artisan test tests/Feature/ExpeditionTest.php`
Atteso: FAIL.

- [ ] **Step 3: Inietta `ExpeditionResolver` nel costruttore**

In `backend/app/Game/Engine/EventEngine.php`, aggiungi al costruttore (dopo `ReactionDeriver $reactions`):

```php
        private readonly ExpeditionResolver $expeditions,
```

- [ ] **Step 4: Gestisci la partenza in `resolveChoice`**

In `resolveChoice()`, subito DOPO il blocco delle reazioni (`$reactions = ...; foreach (...) { standing }`) e PRIMA della costruzione di `$entry`, aggiungi:

```php
        // Expedition dispatch: a choice may send a crew member away. Mark them
        // away, stash the return params, roll the outcome tier, and schedule
        // the matching return event (forced — it cannot be lost in the pool).
        if (! empty($choice['expedition'])) {
            $exp = $choice['expedition'];
            $who = (string) ($exp['who'] ?? '');
            $days = max(1, (int) ($exp['days'] ?? 3));
            $danger = (int) ($exp['danger'] ?? 1);

            foreach ($state->characters as $i => $c) {
                if (($c['name'] ?? null) === $who) {
                    $state->characters[$i]['away_until'] = $state->day + $days;
                }
            }
            $state->flags['expedition_active'] = true;
            $state->flags['away_member'] = $who;
            $state->flags['away_days'] = $days;

            $tier = $this->expeditions->resolve($who, $days, $danger, $state, $rng);
            $state->scheduledEvents[] = [
                'key' => 'exp_return_' . $tier,
                'fire_on_day' => $state->day + $days,
            ];
        }
```

- [ ] **Step 5: Esegui il test (deve passare)**

Run: `cd backend && php artisan test tests/Feature/ExpeditionTest.php`
Atteso: PASS.

- [ ] **Step 6: Esegui la suite**

Run: `cd backend && php artisan test`
Atteso: tutti PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php backend/tests/Feature/ExpeditionTest.php
git commit -m "feat: EventEngine dispatches expeditions and schedules the return"
```

---

### Task 7: API — espone lo stato "in spedizione"

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php`
- Test: `backend/tests/Feature/RunPayloadTest.php`

- [ ] **Step 1: Scrivi il test**

In fondo a `backend/tests/Feature/RunPayloadTest.php`:

```php
it('exposes the away state per character', function () {
    $run = Run::factory()->create([
        'day' => 5,
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 9, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'hunger' => 0, 'away_until' => 0, 'alive' => true],
        ],
    ]);

    $byName = collect($this->getJson("/api/runs/{$run->id}")->assertOk()->json('characters'))->keyBy('name');
    expect($byName['Anna']['away'])->toBeTrue();
    expect($byName['Anna']['away_until'])->toBe(9);
    expect($byName['Bex']['away'])->toBeFalse();
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

Run: `cd backend && php artisan test tests/Feature/RunPayloadTest.php --filter "away state"`
Atteso: FAIL.

- [ ] **Step 3: Aggiungi `away`/`away_until` alla mappa personaggi**

In `backend/app/Http/Controllers/RunController.php`, in `present()`, nella `->map(fn ($c) => [...])`, aggiungi (dopo `'hunger'`):

```php
                    'away_until' => (int) ($c['away_until'] ?? 0),
                    'away' => (int) ($c['away_until'] ?? 0) > $run->day,
```

- [ ] **Step 4: Esegui il test (deve passare)**

Run: `cd backend && php artisan test tests/Feature/RunPayloadTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/RunController.php backend/tests/Feature/RunPayloadTest.php
git commit -m "feat: expose per-character away state in run payload"
```

---

## FASE 3 — CONTENUTO

> Dopo ogni task di contenuto: `cd backend && php artisan db:seed --class=ContentEventSeeder` poi `php artisan test`. Le scelte "Manda X" hanno hint "molto pericoloso" (il simulatore cauto sceglie "Lascia perdere" → BalanceTest verde). Gli eventi di ritorno usano `expeditioner` + `end_expedition`.

### Task 8: Carte-opportunità (destinazioni)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

- [ ] **Step 1: Aggiungi `expeditionEvents()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo `hungerCrisisEvents()`, aggiungi:

```php
    // ---- Spedizioni: opportunità (scegli chi mandare) ----------------------
    private function expeditionEvents(): array
    {
        // A send choice for one crew member, gated on that member being present
        // (alive, not already away) via their role. Hint "molto pericoloso" so
        // the cautious simulator prefers to stay home (expedition deaths are
        // opt-in). The dispatch itself is handled by the `expedition` field.
        $send = function (string $label, string $who, string $role, int $days, int $danger, string $log): array {
            return [
                'label' => $label, 'hint' => 'molto pericoloso', 'tags' => [],
                'requires' => ['has_role' => $role],
                'expedition' => ['who' => $who, 'days' => $days, 'danger' => $danger],
                'outcomes' => [['weight' => 1, 'effects' => [], 'log' => $log]],
            ];
        };

        $noOneAway = ['not' => ['flag' => 'expedition_active', 'is' => true]];

        return [
            $this->ev([
                'key' => 'exp_wreck', 'title' => 'Relitto alla deriva', 'speaker' => 'Cole',
                'body' => "Un relitto è agganciato allo scanner, due giorni di distanza. Segnali deboli, forse scorte. Forse altro. Mandi qualcuno a guardare?",
                'requires' => ['all' => [['day' => ['op' => '>=', 'value' => 5]], $noOneAway]],
                'base_weight' => 6, 'cooldown_days' => 8,
                'choices' => [
                    $send('Manda Anna', 'Anna', 'engineer', 2, 2, 'Anna si infila nella tuta e sparisce nel portello.'),
                    $send('Manda Bex', 'Bex', 'doctor', 2, 2, 'Bex prende il kit ed esce. Il portello si chiude.'),
                    $send('Manda Cole', 'Cole', 'pilot', 2, 2, 'Cole parte. «Torno prima di cena», dice, senza crederci.'),
                    $this->one('Lascia perdere', [['resource' => 'morale', 'delta' => -2]], 'Il relitto si allontana nel buio. Forse era meglio così.'),
                ],
            ]),

            $this->ev([
                'key' => 'exp_distress', 'title' => 'Un segnale di soccorso', 'speaker' => 'Cole',
                'body' => "Una voce spezzata nella radio, da un settore profondo. Tre giorni per arrivarci. Potrebbe esserci qualcuno vivo — o solo un'eco.",
                'requires' => ['all' => [['day' => ['op' => '>=', 'value' => 9]], $noOneAway]],
                'base_weight' => 5, 'cooldown_days' => 10,
                'choices' => [
                    $send('Manda Anna', 'Anna', 'engineer', 3, 3, 'Anna segue il segnale. Sparisce oltre la paratia.'),
                    $send('Manda Bex', 'Bex', 'doctor', 3, 3, 'Bex va: se c\'è un ferito, serve lei.'),
                    $send('Manda Cole', 'Cole', 'pilot', 3, 3, 'Cole conosce quelle rotte. Parte.'),
                    $this->one('Lascia perdere', [['resource' => 'morale', 'delta' => -4]], 'Spegni la radio. La voce resta lì, da qualche parte.'),
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `expeditionEvents()` in `events()`**

In `events()`, dopo `$this->hungerCrisisEvents(),` aggiungi:

```php
            $this->expeditionEvents(),
```

- [ ] **Step 3: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: expedition opportunity cards (choose who to send)"
```

---

### Task 9: Eventi di ritorno (un tier per esito)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

Tutti gli eventi di ritorno: `base_weight => 0` (solo schedulati), `cooldown_days => 0`, una scelta che applica l'esito all'`expeditioner` + `end_expedition`. Sono silent-friendly ma usano una scelta singola "Continua".

- [ ] **Step 1: Aggiungi `expeditionReturnEvents()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo `expeditionEvents()`, aggiungi:

```php
    // ---- Spedizioni: ritorni (uno per tier, schedulati alla partenza) -------
    private function expeditionReturnEvents(): array
    {
        return [
            $this->ev([
                'key' => 'exp_return_rich', 'title' => 'Tornano carichi', 'speaker' => null,
                'body' => "Il portello si apre. Chi avevi mandato torna — e non a mani vuote. Scorte, e qualcosa di utile recuperato dal buio.",
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Bentornati', [['resource' => 'food', 'delta' => 30], ['grant_item' => 'scanner'], ['resource' => 'morale', 'delta' => 10], ['end_expedition' => true]], 'Una giornata buona, di quelle rare.'),
                ],
            ]),

            $this->ev([
                'key' => 'exp_return_modest', 'title' => 'Tornano provati', 'speaker' => null,
                'body' => "Rientrano stremati, ma rientrano. Qualche razione recuperata: meglio di niente.",
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Aiutali a rientrare', [['resource' => 'food', 'delta' => 14], ['end_expedition' => true]], 'Si lasciano cadere, esausti. Ma vivi.'),
                ],
            ]),

            $this->ev([
                'key' => 'exp_return_wounded', 'title' => 'Tornano feriti', 'speaker' => null,
                'body' => "Rientrano, ma qualcosa è andato storto là fuori. Pochissimo bottino e una ferita che peserà.",
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Curali come puoi', [['resource' => 'food', 'delta' => 4], ['character' => 'expeditioner', 'stress' => 20], ['resource' => 'morale', 'delta' => -6], ['end_expedition' => true]], 'Respirano a fatica. Per ora basta che respirino.'),
                ],
            ]),

            $this->ev([
                'key' => 'exp_return_lost', 'title' => 'Non tornano', 'speaker' => null,
                'body' => "Il giorno del rientro arriva. Poi il giorno dopo. La radio resta muta. Chi avevi mandato non torna.",
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Chiudi il portello', [['kill' => 'expeditioner'], ['resource' => 'morale', 'delta' => -18], ['modify_trust' => -12], ['set_flag' => 'lost_on_expedition', 'value' => true], ['end_expedition' => true]], 'Aspetti un\'ora di troppo prima di sigillare. Poi lo fai.'),
                ],
            ]),

            $this->ev([
                'key' => 'exp_return_discovery', 'title' => 'Tornano in due', 'speaker' => null,
                'body' => "Il portello si apre e non c'è solo chi avevi mandato: dietro, una figura barcollante. Un altro superstite, vivo per miracolo.",
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Falli entrare', [['recruit' => ['role' => 'survivor']], ['resource' => 'morale', 'delta' => 8], ['resource' => 'food', 'delta' => -4], ['end_expedition' => true]], 'Una bocca in più, due mani in più. Una storia in più.'),
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `expeditionReturnEvents()` in `events()`**

In `events()`, dopo `$this->expeditionEvents(),` aggiungi:

```php
            $this->expeditionReturnEvents(),
```

- [ ] **Step 3: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS. NOTA equità: `exp_return_lost` ha una sola scelta che uccide, MA si raggiunge solo dopo una partenza *scelta* (con "Lascia perdere" disponibile) e il simulatore cauto non parte (hint "molto pericoloso") → il `FairnessProbe` non lo esercita. Se BalanceTest segnalasse `exp_return_lost`, verifica che le scelte "Manda" abbiano davvero hint "molto pericoloso".

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: expedition return events (rich/modest/wounded/lost/discovery)"
```

---

## FASE 4 — FRONTEND

### Task 10: api.ts + CrewPanel mostra "in spedizione"

**Files:**
- Modify: `frontend/src/api.ts`
- Modify: `frontend/src/components/CrewPanel.tsx`
- Modify: `frontend/src/index.css`

- [ ] **Step 1: Aggiungi i campi al tipo Character**

In `frontend/src/api.ts`, nel tipo `Character`, aggiungi (dopo `hunger: number;`):

```typescript
  away: boolean;
  away_until: number;
```

- [ ] **Step 2: Aggiungi le classi CSS**

In fondo a `frontend/src/index.css`:

```css
/* ---- Away on expedition ---- */
.crew-avatar.away { filter: grayscale(0.7) brightness(0.7); opacity: 0.7; }
.away-tag {
  font-size: 9px; letter-spacing: 0.08em; margin-top: 2px;
  font-family: var(--font-mono); color: var(--color-cyan);
}
```

- [ ] **Step 3: Mostra lo stato "in spedizione" nel CrewPanel**

In `frontend/src/components/CrewPanel.tsx`, dentro `.map((c) => { ... })`, dopo il calcolo di `band`, aggiungi:

```tsx
        const away = c.away ?? false;
```

Aggiorna `avatarClass` perché aggiunga `away` quando in spedizione (prevale sul resto):

```tsx
        const avatarClass = !c.alive
          ? "crew-avatar dead"
          : away
            ? `crew-avatar ${roleKey} away`
            : `crew-avatar ${roleKey} ${reaction ? `react-${reaction.tone}` : band.ring} ${hungerClass}`;
```

Poi, sostituisci il ramo del corpo per gestire i tre stati. Trova il blocco `{c.alive ? ( ... ) : ( <div ...>— perso —</div> )}` e cambialo in:

```tsx
              {!c.alive ? (
                <div style={{ fontSize: 10, color: "var(--color-red)", marginTop: 2 }}>— perso —</div>
              ) : away ? (
                <div className="away-tag">● in spedizione · rientro g.{c.away_until}</div>
              ) : (
                <div style={{ marginTop: 4 }}>
                  {/* ...la barra stress + tag fame + reaction line esistenti restano qui... */}
                </div>
              )}
```

Mantieni dentro il ramo `away ? ... : (...)` finale TUTTO il contenuto esistente del vecchio ramo `c.alive ? (...)` (barra stress, hunger-tag, react-line). Sposta quel contenuto nell'ultimo `(...)`.

- [ ] **Step 4: Verifica build**

Run: `cd frontend && npx tsc --noEmit`
Atteso: nessun output (pulito).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/api.ts frontend/src/components/CrewPanel.tsx frontend/src/index.css
git commit -m "feat: show away-on-expedition state in CrewPanel"
```

---

## FASE 5 — VERIFICA

### Task 11: Verifica end-to-end

**Files:** nessuno.

- [ ] **Step 1: Ri-seed completo e suite**

Run: `cd backend && php artisan migrate:fresh --seed && php artisan test`
Atteso: tutti i test PASS (BalanceTest + HungerBalanceTest inclusi).

- [ ] **Step 2: Guida una spedizione via API**

Avvia i server. Avvia una run, gioca fino a una carta `exp_wreck`/`exp_distress`, scegli "Manda <X>". Verifica nel payload:
- il personaggio mandato ha `away: true`, `away_until` = giorno + durata;
- nei giorni seguenti non viene colpito dagli eventi e non ha fame (resta uguale);
- al giorno del rientro compare un evento `exp_return_*` e dopo la sua risoluzione il personaggio è di nuovo presente (o morto, se `lost`), più affamato/provato.

- [ ] **Step 3: Verifica visiva**

Apri `http://localhost:5173`. Manda qualcuno e verifica che nel pannello equipaggio appaia «● in spedizione · rientro g.N» con l'avatar attenuato; che le altre carte non lo coinvolgano; e che al rientro torni (o sia segnato perso).

- [ ] **Step 4: Commit finale**

```bash
git add -A
git commit -m "test: end-to-end verification of expeditions"
```

---

## Note per l'esecutore
- `away_until` vive nell'array `characters`; i flag `expedition_active`/`away_member`/`away_days` in `flags`.
- "Presente" = `alive && away_until <= day`. Usato per targeting/has_role/crew_hunger/Fame. La vita/morte e i finali usano `alive`.
- `expeditioner` (selettore) trova il membro via dal flag `away_member`, bypassando il filtro presente.
- Le scelte "Manda X" non hanno effetti nell'outcome: la partenza è gestita dall'`EventEngine` leggendo il campo `expedition` della scelta. `EventSchema` non valida i campi extra delle scelte, quindi `expedition` non richiede modifiche allo schema (solo `end_expedition` va in `EFFECT_KEYS`).
- Equità: morte da spedizione opt-in (hint "molto pericoloso" → simulatore cauto resta a casa). RandomPolicy esercita comunque i ritorni → contenuto raggiungibile.
