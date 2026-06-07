# Equipaggio Vivo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendere Anna, Bex e Cole presenze vive — fili narrativi ricorrenti con memoria parlata, standing per personaggio, reazioni visibili, bivii in valute diverse — correggendo i bug dei nomi.

**Architecture:** Backend-first. Due nuovi primitivi DSL (`modify_standing` effect, `standing` condition) e un servizio puro `ReactionDeriver`. L'`EventEngine` raccoglie reazioni (esplicite o derivate), applica gli standing impliciti, allega le reazioni alla risoluzione e un `reaction_summary` al `choice_log`. Il `RunController` espone standing e reazioni. Poi contenuto narrativo nel seeder (fili + intreccio + dilemmi). Infine UI: anello di standing + parola d'umore sull'avatar, reazioni momentanee, pannello Diario.

**Tech Stack:** PHP 8.3 / Laravel 12 / SQLite · Pest (TDD backend) · React 19 / TypeScript / Tailwind 4 / Vite (verifica `npx tsc --noEmit` + e2e finale).

**Convenzione chiave:** lo standing di un personaggio vive in `run.flags["standing_" . strtolower(nome)]`, intero in `[-100, 100]`, default `0`. Tutti i percorsi (effetto, condizione, reazioni, API) usano questo formato.

---

## FASE 1 — PRIMITIVI DEL MOTORE

### Task 1: Effetto `modify_standing` + condizione `standing`

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Test: `backend/tests/Unit/EffectApplierTest.php`
- Test: `backend/tests/Unit/ConditionEvaluatorTest.php`

- [ ] **Step 1: Scrivi i test dell'effetto**

In fondo a `backend/tests/Unit/EffectApplierTest.php` aggiungi:

```php
it('modifies a character standing flag', function () {
    $s = freshState();
    $s->flags['standing_anna'] = 10;
    applier()->apply([['modify_standing' => ['who' => 'Anna', 'delta' => 15]]], $s, new SeededRng(1));
    expect($s->flags['standing_anna'])->toBe(25);
});

it('defaults standing to zero and clamps to [-100, 100]', function () {
    $s = freshState();
    applier()->apply([['modify_standing' => ['who' => 'Bex', 'delta' => -130]]], $s, new SeededRng(1));
    expect($s->flags['standing_bex'])->toBe(-100);

    applier()->apply([['modify_standing' => ['who' => 'Bex', 'delta' => 250]]], $s, new SeededRng(1));
    expect($s->flags['standing_bex'])->toBe(100);
});
```

- [ ] **Step 2: Scrivi il test della condizione**

In fondo a `backend/tests/Unit/ConditionEvaluatorTest.php` aggiungi:

```php
it('evaluates a standing condition against the run flag', function () {
    $s = stateWith(['flags' => ['standing_cole' => 45]]);
    expect($this->eval->evaluate(['standing' => ['who' => 'Cole', 'op' => '>=', 'value' => 40]], $s))->toBeTrue();
    expect($this->eval->evaluate(['standing' => ['who' => 'Cole', 'op' => '<', 'value' => 40]], $s))->toBeFalse();
});

it('treats a missing standing as zero', function () {
    $s = stateWith();
    expect($this->eval->evaluate(['standing' => ['who' => 'Anna', 'op' => '=', 'value' => 0]], $s))->toBeTrue();
});
```

- [ ] **Step 3: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php tests/Unit/ConditionEvaluatorTest.php --filter standing`
Atteso: FAIL (effetto/condizione non ancora implementati).

- [ ] **Step 4: Implementa l'effetto in EffectApplier**

In `backend/app/Game/Engine/EffectApplier.php`, dentro `applyOne()`, subito dopo il blocco `modify_trust` (dopo la riga `return;` che lo chiude), aggiungi:

```php
        if (array_key_exists('modify_standing', $effect)) {
            $spec = $effect['modify_standing'];
            $key = 'standing_' . strtolower((string) ($spec['who'] ?? ''));
            $current = (int) ($state->flags[$key] ?? 0);
            $state->flags[$key] = max(-100, min(100, $current + (int) ($spec['delta'] ?? 0)));
            return;
        }
```

- [ ] **Step 5: Implementa la condizione in ConditionEvaluator**

In `backend/app/Game/Engine/ConditionEvaluator.php`, dentro `evaluate()`, subito dopo il blocco `not_chosen` (prima del commento `// Unknown shape: fail closed`), aggiungi:

```php
        if (array_key_exists('standing', $condition)) {
            $spec = $condition['standing'];
            $key = 'standing_' . strtolower((string) ($spec['who'] ?? ''));
            $have = (int) ($state->flags[$key] ?? 0);
            return $this->compare($have, $spec['op'] ?? '=', $spec['value'] ?? 0);
        }
```

- [ ] **Step 6: Registra le chiavi nello schema**

In `backend/app/Game/Engine/EventSchema.php`:

In `EFFECT_KEYS` aggiungi `'modify_standing'`:

```php
    private const EFFECT_KEYS = [
        'resource', 'set_flag', 'spawn_event', 'character', 'relationship',
        'damage_system', 'recruit', 'kill', 'grant_research_points',
        'consume_item', 'grant_item', 'modify_trust', 'modify_standing',
    ];
```

In `CONDITION_KEYS` aggiungi `'standing'`:

```php
    private const CONDITION_KEYS = [
        'all', 'any', 'not', 'resource', 'day', 'flag', 'has_item',
        'has_role', 'trait_present', 'relationship', 'system',
        'chosen', 'chosen_tag', 'not_chosen', 'standing',
    ];
```

- [ ] **Step 7: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/EffectApplierTest.php tests/Unit/ConditionEvaluatorTest.php --filter standing`
Atteso: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php backend/app/Game/Engine/ConditionEvaluator.php backend/app/Game/Engine/EventSchema.php backend/tests/Unit/EffectApplierTest.php backend/tests/Unit/ConditionEvaluatorTest.php
git commit -m "feat: add modify_standing effect and standing condition"
```

---

### Task 2: `ReactionDeriver` — reazioni esplicite o derivate

**Files:**
- Create: `backend/app/Game/Engine/ReactionDeriver.php`
- Test: `backend/tests/Unit/ReactionDeriverTest.php`

Una reazione è `['who' => string, 'tone' => 'anger'|'approve'|'complicated', 'line' => string]`. La derivazione segue questa priorità: reazioni esplicite sull'outcome (filtrate ai vivi) → altrimenti derivate da tag/effetti.

- [ ] **Step 1: Scrivi i test**

Crea `backend/tests/Unit/ReactionDeriverTest.php`:

```php
<?php

use App\Game\Engine\ReactionDeriver;
use App\Game\Engine\RunState;

function reactState(array $characters): RunState
{
    return new RunState(day: 5, resources: [], characters: $characters);
}

function living(): array
{
    return [
        ['name' => 'Anna', 'alive' => true],
        ['name' => 'Bex', 'alive' => true],
        ['name' => 'Cole', 'alive' => true],
    ];
}

it('returns explicit outcome reactions, filtered to living characters', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => []];
    $outcome = ['reactions' => [
        ['who' => 'Bex', 'tone' => 'approve', 'line' => 'Bene.'],
        ['who' => 'Cole', 'tone' => 'anger', 'line' => 'Idiota.'],
    ]];
    $state = reactState([
        ['name' => 'Bex', 'alive' => true],
        ['name' => 'Cole', 'alive' => false],
    ]);

    $out = $deriver->derive($choice, $outcome, $state);
    expect($out)->toHaveCount(1);
    expect($out[0]['who'])->toBe('Bex');
});

it('derives doctor anger from a cold-choice tag', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => ['sacrifice_crew']];
    $out = $deriver->derive($choice, ['effects' => []], reactState(living()));
    expect($out)->toHaveCount(1);
    expect($out[0]['who'])->toBe('Bex');
    expect($out[0]['tone'])->toBe('anger');
});

it('derives doctor approval from a generous tag', function () {
    $deriver = new ReactionDeriver();
    $choice = ['tags' => ['generous']];
    $out = $deriver->derive($choice, ['effects' => []], reactState(living()));
    expect($out[0]['who'])->toBe('Bex');
    expect($out[0]['tone'])->toBe('approve');
});

it('derives engineer anger when a system is damaged', function () {
    $deriver = new ReactionDeriver();
    $out = $deriver->derive(['tags' => []], ['effects' => [['damage_system' => 'power_grid', 'amount' => 20]]], reactState(living()));
    expect(collect($out)->pluck('who'))->toContain('Anna');
});

it('makes the whole living crew angry on a kill', function () {
    $deriver = new ReactionDeriver();
    $out = $deriver->derive(['tags' => []], ['effects' => [['kill' => 'random']]], reactState(living()));
    expect($out)->toHaveCount(3);
    expect(collect($out)->every(fn ($r) => $r['tone'] === 'anger'))->toBeTrue();
});

it('maps tone to a standing delta', function () {
    $deriver = new ReactionDeriver();
    expect($deriver->standingDelta('anger'))->toBe(-10);
    expect($deriver->standingDelta('approve'))->toBe(8);
    expect($deriver->standingDelta('complicated'))->toBe(0);
});

it('summarizes the first reaction in third person', function () {
    $deriver = new ReactionDeriver();
    $summary = $deriver->summary([['who' => 'Bex', 'tone' => 'anger', 'line' => 'No.']]);
    expect($summary)->toBe('Bex non era d\'accordo.');
    expect($deriver->summary([]))->toBeNull();
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

Run: `cd backend && php artisan test tests/Unit/ReactionDeriverTest.php`
Atteso: FAIL (classe inesistente).

- [ ] **Step 3: Implementa ReactionDeriver**

Crea `backend/app/Game/Engine/ReactionDeriver.php`:

```php
<?php

namespace App\Game\Engine;

/**
 * Turns a resolved choice/outcome into crew reactions — the "spoken memory"
 * that makes the crew feel alive. Pure and total: same inputs, same reactions.
 *
 * Priority: an outcome may declare explicit `reactions` (authored for strong
 * beats); otherwise reactions are derived from the choice's tags and the
 * outcome's effects. Every reaction names a character, a tone, and a line.
 *
 * Reactions also move standing: anger costs the reactor's trust in you,
 * approval earns it. The engine reads standingDelta() to apply that.
 */
final class ReactionDeriver
{
    private const TONE_STANDING = ['anger' => -10, 'approve' => 8, 'complicated' => 0];

    /**
     * @param  array<string,mixed>  $choice   the chosen option (carries `tags`)
     * @param  array<string,mixed>  $outcome  the resolved outcome (effects / explicit reactions)
     * @return list<array{who:string,tone:string,line:string}>
     */
    public function derive(array $choice, array $outcome, RunState $state): array
    {
        if (! empty($outcome['reactions']) && is_array($outcome['reactions'])) {
            return array_values(array_filter(
                $outcome['reactions'],
                fn ($r) => is_array($r) && $this->isAlive($r['who'] ?? '', $state),
            ));
        }

        $tags = $choice['tags'] ?? [];
        $effects = $outcome['effects'] ?? [];

        // A death silences everything else: the whole crew reacts to it.
        if ($this->hasEffect($effects, 'kill')) {
            $out = [];
            foreach ($this->livingNames($state) as $name) {
                $out[] = ['who' => $name, 'tone' => 'anger', 'line' => 'Non lo dimenticherò.'];
            }
            return $out;
        }

        $out = [];
        $cold = array_intersect((array) $tags, ['sacrifice_crew', 'il_freddo']) !== [];
        $kind = array_intersect((array) $tags, ['generous', 'honest']) !== [];

        if ($cold && $this->isAlive('Bex', $state)) {
            $out[] = ['who' => 'Bex', 'tone' => 'anger', 'line' => 'Non dovevi farlo.'];
        }
        if ($kind && $this->isAlive('Bex', $state)) {
            $out[] = ['who' => 'Bex', 'tone' => 'approve', 'line' => 'Hai fatto la cosa giusta.'];
        }
        if ($this->hasEffect($effects, 'damage_system') && $this->isAlive('Anna', $state)) {
            $out[] = ['who' => 'Anna', 'tone' => 'anger', 'line' => 'Quei sistemi mi servivano.'];
        }

        return $out;
    }

    public function standingDelta(string $tone): int
    {
        return self::TONE_STANDING[$tone] ?? 0;
    }

    /**
     * A short third-person line for the Diary, from the first reaction.
     *
     * @param  list<array{who:string,tone:string,line:string}>  $reactions
     */
    public function summary(array $reactions): ?string
    {
        if ($reactions === []) {
            return null;
        }
        $first = $reactions[0];
        $who = $first['who'] ?? '';
        return match ($first['tone'] ?? '') {
            'anger' => "{$who} non era d'accordo.",
            'approve' => "{$who} ha approvato.",
            default => "{$who} ha avuto da ridire.",
        };
    }

    private function isAlive(string $name, RunState $state): bool
    {
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $name) {
                return (bool) ($c['alive'] ?? true);
            }
        }
        return false;
    }

    /** @return list<string> */
    private function livingNames(RunState $state): array
    {
        $out = [];
        foreach ($state->characters as $c) {
            if ($c['alive'] ?? true) {
                $out[] = $c['name'] ?? '?';
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $effects */
    private function hasEffect(array $effects, string $kind): bool
    {
        foreach ($effects as $e) {
            if (is_array($e) && array_key_exists($kind, $e)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 4: Esegui i test (devono passare)**

Run: `cd backend && php artisan test tests/Unit/ReactionDeriverTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/ReactionDeriver.php backend/tests/Unit/ReactionDeriverTest.php
git commit -m "feat: add ReactionDeriver for explicit and derived crew reactions"
```

---

### Task 3: EventEngine — applica reazioni, standing impliciti, reaction_summary

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Test: `backend/tests/Feature/ReactionFlowTest.php`

L'`EventEngine` riceve `ReactionDeriver` nel costruttore (autowiring Laravel: stesso namespace, costruttore senza argomenti). In `resolveChoice()`, dopo aver applicato gli effetti dell'outcome e prima di costruire l'entry del log: deriva le reazioni, applica gli standing impliciti su `$state->flags`, mette `reaction_summary` nell'entry del log, e include `reactions` nel valore restituito.

- [ ] **Step 1: Scrivi il test di flusso**

Crea `backend/tests/Feature/ReactionFlowTest.php`:

```php
<?php

use App\Game\Engine\EventEngine;
use App\Models\Event;
use App\Models\Run;

it('attaches reactions and lowers standing on a cold choice', function () {
    Event::create([
        'key' => 'react_test',
        'title' => 'Prova',
        'body' => 'Una decisione fredda.',
        'speaker' => null,
        'base_weight' => 1,
        'cooldown_days' => 0,
        'is_filler' => false,
        'requires' => null,
        'weight_modifiers' => null,
        'choices' => [
            ['label' => 'Sacrifica', 'tags' => ['sacrifice_crew'],
             'outcomes' => [['weight' => 1, 'effects' => [], 'log' => 'Fatto.']]],
        ],
    ]);

    $run = Run::factory()->create([
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'alive' => true],
        ],
    ]);
    $run->current_event_key = 'react_test';
    $run->save();

    $result = app(EventEngine::class)->resolveChoice($run->fresh(), 0);

    expect($result['reactions'])->toHaveCount(1);
    expect($result['reactions'][0]['who'])->toBe('Bex');
    expect($result['reactions'][0]['tone'])->toBe('anger');

    $fresh = $run->fresh();
    expect((int) $fresh->flags['standing_bex'])->toBe(-10);

    $lastLog = collect($fresh->choice_log)->last();
    expect($lastLog['reaction_summary'])->toBe('Bex non era d\'accordo.');
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

Run: `cd backend && php artisan test tests/Feature/ReactionFlowTest.php`
Atteso: FAIL (`reactions` non nel risultato).

- [ ] **Step 3: Inietta ReactionDeriver nel costruttore**

In `backend/app/Game/Engine/EventEngine.php`, aggiungi il parametro al costruttore (dopo `TrustEngine $trust`):

```php
    public function __construct(
        private readonly Selector $selector,
        private readonly ConditionEvaluator $evaluator,
        private readonly EffectApplier $applier,
        private readonly HintService $hints,
        private readonly OutcomeWeigher $weigher,
        private readonly EndingService $endings,
        private readonly ProfileSync $profileSync,
        private readonly EpithetEngine $epithet,
        private readonly TrustEngine $trust,
        private readonly ReactionDeriver $reactions,
    ) {
    }
```

- [ ] **Step 4: Deriva e applica le reazioni in resolveChoice**

In `resolveChoice()`, subito dopo la riga `$this->applier->apply($outcome['effects'] ?? [], $state, $rng);`, aggiungi:

```php
        // Crew reactions (spoken memory). Explicit on the outcome, else derived.
        $reactions = $this->reactions->derive($choice, $outcome, $state);
        foreach ($reactions as $r) {
            $delta = $this->reactions->standingDelta($r['tone'] ?? '');
            if ($delta !== 0) {
                $key = 'standing_' . strtolower((string) ($r['who'] ?? ''));
                $current = (int) ($state->flags[$key] ?? 0);
                $state->flags[$key] = max(-100, min(100, $current + $delta));
            }
        }
```

- [ ] **Step 5: Aggiungi reaction_summary all'entry del log**

Nella costruzione di `$entry`, aggiungi la riga `reaction_summary`:

```php
        $entry = [
            'day'              => $state->day,
            'event_key'        => $event->key,
            'choice_index'     => $choiceIndex,
            'choice_label'     => $choice['label'] ?? '',
            'tags'             => $choice['tags'] ?? [],
            'reaction_summary' => $this->reactions->summary($reactions),
        ];
```

- [ ] **Step 6: Restituisci reactions nel risultato**

Nel `return` finale di `resolveChoice()`, aggiungi la chiave `reactions`:

```php
        return [
            'log' => $outcome['log'] ?? '',
            'effects' => $outcome['effects'] ?? [],
            'ending' => $ending,
            'reactions' => $reactions,
        ];
```

- [ ] **Step 7: Esegui il test (deve passare)**

Run: `cd backend && php artisan test tests/Feature/ReactionFlowTest.php`
Atteso: PASS.

- [ ] **Step 8: Esegui l'intera suite**

Run: `cd backend && php artisan test`
Atteso: tutti PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php backend/tests/Feature/ReactionFlowTest.php
git commit -m "feat: EventEngine collects reactions, applies standing, logs reaction_summary"
```

---

## FASE 2 — ESPOSIZIONE API

### Task 4: RunController — espone standing per personaggio

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php`
- Test: `backend/tests/Feature/RunPayloadTest.php`

Le `reactions` viaggiano già nella `resolution` (Task 3). Qui aggiungiamo lo `standing` di ciascun personaggio al payload, perché la UI possa renderlo come anello qualitativo.

- [ ] **Step 1: Scrivi il test**

Crea `backend/tests/Feature/RunPayloadTest.php`:

```php
<?php

use App\Models\Run;

it('exposes per-character standing in the run payload', function () {
    $run = Run::factory()->create([
        'flags' => ['crew_trust' => 60, 'standing_anna' => 30, 'standing_bex' => -20],
        'characters' => [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Bex', 'role' => 'doctor', 'traits' => [], 'stress' => 0, 'alive' => true],
            ['name' => 'Cole', 'role' => 'pilot', 'traits' => [], 'stress' => 0, 'alive' => true],
        ],
    ]);

    $payload = $this->getJson("/api/runs/{$run->id}")->assertOk()->json();

    $byName = collect($payload['characters'])->keyBy('name');
    expect($byName['Anna']['standing'])->toBe(30);
    expect($byName['Bex']['standing'])->toBe(-20);
    expect($byName['Cole']['standing'])->toBe(0); // default
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

Run: `cd backend && php artisan test tests/Feature/RunPayloadTest.php`
Atteso: FAIL (`standing` assente).

- [ ] **Step 3: Aggiungi standing alla mappa characters**

In `backend/app/Http/Controllers/RunController.php`, dentro `present()`, nella `->map(...)` dei personaggi, aggiungi il campo `standing`:

```php
            'characters' => collect($run->characters ?? [])
                ->map(fn ($c) => [
                    'name' => $c['name'] ?? '?',
                    'role' => $c['role'] ?? null,
                    'traits' => $c['traits'] ?? [],
                    'stress' => $c['stress'] ?? 0,
                    'alive' => $c['alive'] ?? true,
                    'standing' => (int) ($run->flags['standing_' . strtolower((string) ($c['name'] ?? ''))] ?? 0),
                ])->all(),
```

- [ ] **Step 4: Esegui il test (deve passare)**

Run: `cd backend && php artisan test tests/Feature/RunPayloadTest.php`
Atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/RunController.php backend/tests/Feature/RunPayloadTest.php
git commit -m "feat: expose per-character standing in run payload"
```

---

## FASE 3 — CONTENUTO NARRATIVO

> Nota: tutti i nuovi eventi vengono validati dal seeder. Dopo ogni task di contenuto esegui `cd backend && php artisan db:seed --class=ContentEventSeeder` e poi `php artisan test` (incluso `BalanceTest`, che impone equità: nessuna morte da scelta inevitabile).

### Task 5: Fix bug nomi + oggetto inesistente

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

I personaggi del roster sono SOLO Anna, Bex, Cole. Gli eventi aggiunti in precedenza usano "Marco"/"Ayaka" (inesistenti) e `eva_suit` (la chiave reale è `spacesuit`).

- [ ] **Step 1: Correggi `doctor_exhausted`**

In `dominoEvents()`, sostituisci l'intero evento `doctor_exhausted` con (nome del medico = Bex, target di stress = Bex):

```php
            $this->ev([
                'key' => 'doctor_exhausted', 'title' => 'Il medico è a pezzi',
                'body' => "Bex non dorme da tre giorni. Ti chiede un turno di riposo. Puoi permettertelo?",
                'requires' => ['has_role' => 'doctor'],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Concedile il riposo', [['character' => 'Bex', 'stress' => -25]], 'Bex si riposa. Ci vorrà un giorno.'),
                    array_merge(
                        $this->one('Non possiamo fermarci ora', [['character' => 'Bex', 'stress' => 20], ['spawn_event' => ['key' => 'patient_lost', 'in_days' => 4]]], 'Bex annuisce e torna al lavoro, silenziosa.'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),
```

- [ ] **Step 2: Correggi `patient_lost`**

In `dominoEvents()`, sostituisci l'intero evento `patient_lost` con (riferimento al medico senza nome inventato):

```php
            $this->ev([
                'key' => 'patient_lost', 'title' => 'Troppo tardi',
                'body' => "Il paziente che Bex stava seguendo non ce l'ha fatta. Bex ti guarda. Non dice niente. Non deve.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Prendi la responsabilità', [['resource' => 'morale', 'delta' => -8], ['modify_trust' => 10]], "L'equipaggio apprezza la tua onestà. Il peso rimane."),
                    $this->one('Era inevitabile', [['resource' => 'morale', 'delta' => -20], ['modify_trust' => -15]], 'Bex si allontana. Qualcosa si è rotto.'),
                ],
            ]),
```

- [ ] **Step 3: Correggi `silent_window`**

In `silentEvents()`, sostituisci l'intero evento `silent_window` con (Anna al posto di Ayaka):

```php
            $this->ev([
                'key' => 'silent_window', 'title' => 'Una finestra nello spazio',
                'body' => "Anna è ferma davanti al pannello di osservazione da venti minuti. Non si gira quando entri. Le stelle non rispondono, ma almeno non mentono.",
                'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 8,
                'choices' => [],
            ]),
```

- [ ] **Step 4: Correggi `trap_hull_critical` (eva_suit → spacesuit)**

In `trapEvents()`, nell'evento `trap_hull_critical`, sostituisci la prima scelta con:

```php
                    array_merge(
                        $this->one('Vai tu — tuta EVA nell\'inventario', [['resource' => 'hull', 'delta' => 30], ['character' => 'random', 'stress' => 15]], 'Esci. Fa freddo. La riparazione regge.', null, ['has_item' => 'spacesuit']),
                        ['tags' => ['cautious'], 'requires_item' => 'spacesuit']
                    ),
```

- [ ] **Step 5: Ri-seed e verifica**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder`
Atteso: `INFO Seeding database.` senza errori.

- [ ] **Step 6: Verifica che nessun nome fuori roster resti**

Run: `cd backend && php artisan test`
Atteso: tutti PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "fix: replace non-roster names (Marco/Ayaka) and eva_suit with spacesuit"
```

---

### Task 6: Filo di Anna — 4 volti

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

Aggiungi un metodo `annaThread()` e includilo in `events()`. Ogni volto usa `weight_modifiers` per diventare più probabile sotto pressione, `cooldown_days => 999` e un flag `anna_thread_done` per non ripetersi. Il volto "Si spegne" imposta `anna_withdrawn`.

- [ ] **Step 1: Aggiungi il metodo `annaThread()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito prima del metodo `dominoEvents()`, aggiungi il metodo completo (tutti e 4 i volti). I `tags` si applicano alla CHOICE, non all'outcome; il flag `anna_thread_done` si imposta tra gli effetti di ogni outcome (variabile `$done`), così il filo non si ripete:

```php
    // ---- Filo di Anna (ingegnere): competenza che non chiede permesso -------
    private function annaThread(): array
    {
        $done = ['set_flag' => 'anna_thread_done', 'value' => true];

        return [
            // 1. Lo fa comunque — ribaltamento: ha già iniziato.
            $this->ev([
                'key' => 'anna_does_it_anyway', 'title' => 'Anna non ha aspettato',
                'body' => "Trovi Anna a metà di una riparazione che non le hai autorizzato. «Stava cedendo. Non c'era tempo per chiederti il permesso.» Ormai è fatta.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['system' => 'power_grid', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                        ['system' => 'hull_integrity', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                    ]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['system' => 'power_grid', 'field' => 'efficiency', 'op' => '<', 'value' => 35], 'factor' => 3.0],
                ],
                'choices' => [
                    [
                        'label' => 'Coprila — è la migliore che abbiamo',
                        'hint' => 'incerto',
                        'tags' => ['cautious'],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'power', 'delta' => 12], ['modify_standing' => ['who' => 'Anna', 'delta' => 15]], $done], 'log' => 'Funziona. Ti guarda con gratitudine.'],
                            ['weight' => 4, 'effects' => [['damage_system' => 'power_grid', 'amount' => 10], ['character' => 'Anna', 'stress' => 10], $done], 'log' => 'Stavolta no. Ma ci ha provato per tutti.'],
                        ],
                    ],
                    [
                        'label' => 'Mettila a rapporto davanti a tutti',
                        'hint' => null,
                        'tags' => ['lone_decision'],
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -25]], ['set_flag' => 'anna_overruled', 'value' => true], ['resource' => 'morale', 'delta' => -4], $done], 'log' => 'Anna incassa in silenzio. Qualcosa si raffredda.'],
                        ],
                    ],
                ],
            ]),

            // 2. Si spegne — stress altissimo + scavalcata/sfruttata. Imposta anna_withdrawn.
            $this->ev([
                'key' => 'anna_withdraws', 'title' => 'Anna si è fermata',
                'body' => "Anna è seduta a terra accanto a una paratia aperta, le mani ferme. «Faccio tutto io. Sbaglio io. Pago io. Ho finito.» Non è una minaccia. È stanchezza vera.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['flag' => 'anna_overruled', 'is' => true],
                        ['standing' => ['who' => 'Anna', 'op' => '<=', 'value' => -20]],
                    ]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala respirare. Te la cavi senza di lei.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'anna_withdrawn', 'value' => true], ['character' => 'Anna', 'stress' => -20], $done], 'log' => 'Anna si ritira nel suo silenzio. La prossima crisi tecnica è tua.']],
                    ],
                    [
                        'label' => 'Siediti accanto a lei. Ascolta.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => 30]], ['character' => 'Anna', 'stress' => -10], ['resource' => 'morale', 'delta' => -3], $done], 'log' => 'Non risolvi niente. Ma lei resta. A volte basta.']],
                    ],
                ],
            ]),

            // 3. La scommessa — scafo/energia critici + oggetto tecnico. Consuma l'oggetto.
            $this->ev([
                'key' => 'anna_gambit', 'title' => 'La scommessa di Anna',
                'body' => "Anna posa l'attrezzo sul tavolo come una carta da gioco. «Una possibilità. La sfrutto tutta o niente. Se va, siamo a posto per giorni. Se non va, l'ho bruciata.» Decidi tu.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['has_item' => 'welder'], ['has_item' => 'toolkit'],
                        ['has_item' => 'fabricator'], ['has_item' => 'manual'],
                    ]],
                    ['any' => [
                        ['resource' => 'power', 'op' => '<', 'value' => 40],
                        ['resource' => 'hull', 'op' => '<', 'value' => 40],
                    ]],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala scommettere',
                        'hint' => 'rischioso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'power', 'delta' => 25], ['resource' => 'hull', 'delta' => 20], ['modify_standing' => ['who' => 'Anna', 'delta' => 20]], $done], 'log' => 'Il colpo riesce. Anna sorride per la prima volta da giorni.'],
                            ['weight' => 4, 'effects' => [['consume_item' => 'welder'], ['consume_item' => 'toolkit'], ['character' => 'Anna', 'stress' => 15], $done], 'log' => "L'attrezzo si fonde tra le sue mani. Niente. Ci aveva creduto."],
                        ],
                    ],
                    [
                        'label' => 'Troppo rischio. Si tiene l\'attrezzo.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -8]], ['resource' => 'morale', 'delta' => -2], $done], 'log' => 'Anna rimette via tutto. «Come vuoi.»']],
                    ],
                ],
            ]),

            // 4. Il salvataggio silenzioso — standing alto + situazione non disperata.
            $this->ev([
                'key' => 'anna_quiet_save', 'title' => 'Quello che Anna ha fatto',
                'body' => "Scopri solo dopo cosa ha fatto Anna: ha reinstradato l'energia da sola, di notte, per tenere caldo il settore dove dormiva chi era più sfinito. «Non dovevi saperlo. L'avrei fatto comunque.»",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Anna', 'op' => '>=', 'value' => 35]],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Ringraziala. Davvero.',
                        'hint' => null,
                        'tags' => ['generous'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 10], ['character' => 'all', 'stress' => -6], ['modify_standing' => ['who' => 'Anna', 'delta' => 10]], $done], 'log' => "L'equipaggio si stringe un po' di più. Funziona, per oggi."]],
                    ],
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `annaThread()` in `events()`**

In `events()`, aggiungi `$this->annaThread()` all'`array_merge` (dopo `$this->moralEvents()`):

```php
            $this->moralEvents(),
            $this->annaThread(),
```

- [ ] **Step 3: Fai consumare `anna_withdrawn` da un evento esistente**

Il volto "Si spegne" imposta `anna_withdrawn`, ma quel flag deve avere un effetto: quando Anna si è ritirata, il suo evento d'iniziativa tecnica non deve più comparire. In `characterEvents()`, nell'evento `c_anna_idea`, sostituisci la riga `requires`:

```php
                'requires' => ['has_role' => 'engineer'],
```

con:

```php
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_withdrawn', 'is' => true]],
                ]],
```

- [ ] **Step 4: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS (incluso `BalanceTest`).

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: Anna character thread (4 faces) with standing and weight modifiers"
```

---

### Task 7: Filo di Bex — 4 volti

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

- [ ] **Step 1: Aggiungi il metodo `bexThread()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo il metodo `annaThread()`, aggiungi:

```php
    // ---- Filo di Bex (medico): la coscienza che conta il prezzo ------------
    private function bexThread(): array
    {
        $done = ['set_flag' => 'bex_thread_done', 'value' => true];

        return [
            // 1. La verità scomoda — hai fatto scelte fredde o nascosto qualcosa.
            $this->ev([
                'key' => 'bex_confronts', 'title' => 'Bex non ci sta',
                'body' => "Bex ti ferma davanti a tutti. «So cosa hai scelto. Lo sappiamo tutti. Volevo solo che lo dicessi ad alta voce, almeno una volta.» Il corridoio è silenzioso.",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['any' => [
                        ['chosen_tag' => 'sacrifice_crew'],
                        ['chosen_tag' => 'il_freddo'],
                        ['flag' => 'log_falsified', 'is' => true],
                    ]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Prenditi la responsabilità, davanti a tutti',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['honest'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 8], ['modify_trust' => 12], ['modify_standing' => ['who' => 'Bex', 'delta' => 25]], ['set_flag' => 'bex_confronted', 'value' => true], $done], 'log' => "Lo dici. Bex annuisce, lentamente. L'aria cambia."]],
                    ],
                    [
                        'label' => 'Sono scelte da comandante. Punto.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -12], ['modify_trust' => -15], ['modify_standing' => ['who' => 'Bex', 'delta' => -20]], ['set_flag' => 'bex_confronted', 'value' => true], $done], 'log' => 'Bex ti fissa un secondo di troppo, poi se ne va.']],
                    ],
                ],
            ]),

            // 2. Il crollo — stress altissimo + una morte. Imposta bex_broken.
            $this->ev([
                'key' => 'bex_breaks', 'title' => 'Bex ha le mani che tremano',
                'body' => "Bex fissa lo strumentario senza vederlo. «Continuo a rivedere chi non sono riuscita a salvare. Non posso operare così. Non oggi.» Non sta esagerando.",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['flag' => 'bex_saw_death', 'is' => true],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['resource' => 'morale', 'op' => '<', 'value' => 30], 'factor' => 2.5],
                ],
                'choices' => [
                    [
                        'label' => 'Sollevala dai turni. Ne ha bisogno.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'bex_broken', 'value' => true], ['character' => 'Bex', 'stress' => -25], $done], 'log' => 'Bex si ritira. Finché non torna, la medicina è un lusso che non hai.']],
                    ],
                    [
                        'label' => 'Abbiamo bisogno di te. Resisti.',
                        'hint' => 'rischioso',
                        'tags' => ['sacrifice_crew'],
                        'outcomes' => [
                            ['weight' => 5, 'effects' => [['character' => 'Bex', 'stress' => 15], ['modify_standing' => ['who' => 'Bex', 'delta' => -10]], $done], 'log' => 'Bex stringe i denti e continua. Ti costerà.'],
                            ['weight' => 5, 'effects' => [['character' => 'Bex', 'stress' => 25], ['resource' => 'morale', 'delta' => -8], $done], 'log' => 'Regge per un\'ora, poi cede del tutto. Era troppo.'],
                        ],
                    ],
                ],
            ]),

            // 3. La diagnosi — standing alto + medkit/scanner. Annulla uno spawn negativo.
            $this->ev([
                'key' => 'bex_catch', 'title' => 'Bex ha notato qualcosa',
                'body' => "Bex ti prende da parte. «Uno di noi sta covando qualcosa. Sintomi minimi, ma li riconosco. Se intervengo ora, con quello che abbiamo, lo fermo prima che diventi un problema per tutti.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Bex', 'op' => '>=', 'value' => 30]],
                    ['any' => [['has_item' => 'medkit'], ['has_item' => 'scanner']]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Fidati di lei. Intervieni ora.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'all', 'stress' => -5], ['set_flag' => 'illness_caught', 'value' => true], ['modify_standing' => ['who' => 'Bex', 'delta' => 10]], $done], 'log' => 'Bex agisce in silenzio. Un disastro che non vedrai mai succedere.']],
                    ],
                    [
                        'label' => 'Non abbiamo risorse da sprecare su un sospetto',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['spawn_event' => ['key' => 'c_sick_survivor', 'in_days' => 3]], ['modify_standing' => ['who' => 'Bex', 'delta' => -12]], $done], 'log' => '«Spero di sbagliarmi», dice Bex. Non si sbaglia quasi mai.']],
                    ],
                ],
            ]),

            // 4. Il suo sacrificio — tardi + disperato + standing alto. Può morire.
            $this->ev([
                'key' => 'bex_sacrifice', 'title' => 'Bex non esita',
                'body' => "C'è da entrare nel settore contaminato per tirare fuori chi è rimasto bloccato. Bex si sta già infilando la maschera. «Sono il medico. È letteralmente il mio lavoro. Non discutere.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Bex', 'op' => '>=', 'value' => 40]],
                    ['day' => ['op' => '>=', 'value' => 14]],
                    ['resource' => 'oxygen', 'op' => '<', 'value' => 45],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala andare. È la sua scelta.',
                        'hint' => 'molto pericoloso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'oxygen', 'delta' => 15], ['resource' => 'morale', 'delta' => 8], ['modify_standing' => ['who' => 'Bex', 'delta' => 15]], $done], 'log' => 'Bex torna, sfinita ma viva, trascinando chi era bloccato.'],
                            ['weight' => 4, 'effects' => [['kill' => 'Bex'], ['resource' => 'morale', 'delta' => -15], ['set_flag' => 'bex_saw_death', 'value' => true], $done], 'log' => 'Bex non torna. Ha salvato qualcuno. Non se stessa.'],
                        ],
                    ],
                    [
                        'label' => 'No. Vai tu al posto suo.',
                        'hint' => 'rischioso',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 10], ['character' => 'all', 'stress' => 8], ['modify_standing' => ['who' => 'Bex', 'delta' => 20]], $done], 'log' => 'Esci tu. Bex ti aspetta al portello, e non te lo dimentica.']],
                    ],
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `bexThread()` in `events()`**

In `events()`, dopo `$this->annaThread(),` aggiungi:

```php
            $this->bexThread(),
```

- [ ] **Step 3: Fai consumare `bex_broken` da un evento esistente**

Il volto "Il crollo" imposta `bex_broken`; quando Bex è a pezzi, il suo evento medico d'iniziativa non deve comparire. In `characterEvents()`, nell'evento `c_doctor_call`, sostituisci la riga `requires`:

```php
                'requires' => ['has_role' => 'doctor'],
```

con:

```php
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_broken', 'is' => true]],
                ]],
```

- [ ] **Step 4: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: Bex character thread (4 faces) with standing gates"
```

---

### Task 8: Filo di Cole — 4 volti

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

- [ ] **Step 1: Aggiungi il metodo `coleThread()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo il metodo `bexThread()`, aggiungi:

```php
    // ---- Filo di Cole (pilota): il sopravvissuto con un occhio sull'uscita --
    private function coleThread(): array
    {
        $done = ['set_flag' => 'cole_thread_done', 'value' => true];

        return [
            // 1. La via d'uscita — metà partita. Indaga (apre rotta) o ignora (lo risenti).
            $this->ev([
                'key' => 'cole_finds_exit', 'title' => 'Cole ha trovato qualcosa',
                'body' => "Cole ti mostra uno schema di volo. «C'è una finestra. Una rotta. Non sarà comoda, ma è una via fuori da questa scatola di latta. Vale la pena guardarci dentro?»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['day' => ['op' => '>=', 'value' => 8]],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Indaghiamo questa rotta',
                        'hint' => 'incerto',
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'cole_found_exit', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => 15]], ['resource' => 'power', 'delta' => -6]], 'log' => 'Cole si illumina. Per la prima volta sembra avere uno scopo.']],
                    ],
                    [
                        'label' => 'Concentriamoci sulla stazione, non sulla fuga',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'cole_resentful', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => -12]]], 'log' => 'Cole ripiega lo schema, lentamente. «Certo. Come vuoi tu.»']],
                    ],
                ],
            ]),

            // 2. La fuga — stress alto + risentito + oggetto di sopravvivenza.
            $this->ev([
                'key' => 'cole_defection', 'title' => 'Il posto di Cole è vuoto',
                'body' => "Cole non è alla sua postazione. Lo trovi al modulo di fuga, uno zaino già pronto. «Non aspetterò di morire qui mentre tu giochi a fare l'eroe. Mi prendo la mia possibilità.»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['flag' => 'cole_resentful', 'is' => true],
                    ['any' => [['has_item' => 'spacesuit'], ['has_item' => 'reactor_cell'], ['has_item' => 'rations']]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['standing' => ['who' => 'Cole', 'op' => '<=', 'value' => -25]], 'factor' => 2.0],
                ],
                'choices' => [
                    [
                        'label' => 'Fermalo. Abbiamo bisogno di tutti.',
                        'hint' => 'rischioso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 5, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 20]], ['resource' => 'morale', 'delta' => -5], $done], 'log' => 'Lo convinci a restare. A fatica. Resta una crepa.'],
                            ['weight' => 5, 'effects' => [['character' => 'all', 'stress' => 12], ['modify_trust' => -15], $done], 'log' => 'Degenera in rissa. Tutti hanno visto. Niente sarà come prima.'],
                        ],
                    ],
                    [
                        'label' => 'Lascialo andare.',
                        'hint' => null,
                        'tags' => ['sacrifice_crew'],
                        'outcomes' => [['weight' => 1, 'effects' => [['kill' => 'Cole'], ['consume_item' => 'spacesuit'], ['resource' => 'morale', 'delta' => -10], ['set_flag' => 'cole_left', 'value' => true], $done], 'log' => 'Il modulo si stacca nel buio. Non saprai mai se ce l\'ha fatta.']],
                    ],
                ],
            ]),

            // 3. Il momento di coraggio — standing alto + finale disperato.
            $this->ev([
                'key' => 'cole_heroics', 'title' => 'Cole prende i comandi',
                'body' => "La stazione sta perdendo assetto. Cole è già al sedile di pilotaggio. «So che ho paura di tutto. Ma questo — questo lo so fare. Reggetevi a qualcosa.» Le sue mani, per una volta, non tremano.",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Cole', 'op' => '>=', 'value' => 40]],
                    ['day' => ['op' => '>=', 'value' => 14]],
                    ['resource' => 'hull', 'op' => '<', 'value' => 45],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Affidati a lui',
                        'hint' => 'incerto',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 25], ['resource' => 'morale', 'delta' => 12], ['set_flag' => 'cole_heroics', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => 15]], $done], 'log' => 'La manovra è folle e perfetta. Cole ride, incredulo di se stesso.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => -5], ['character' => 'Cole', 'stress' => 20], $done], 'log' => 'Quasi. Reggete tutti, a malapena. Cole è scosso ma vivo.'],
                        ],
                    ],
                ],
            ]),

            // 4. Il prezzo della paura — la sua paura ha causato una morte.
            $this->ev([
                'key' => 'cole_guilt', 'title' => 'Il peso di Cole',
                'body' => "Cole non si perdona. «Mi sono bloccato. Se mi fossi mosso un secondo prima, forse... » Non finisce la frase. Aspetta che tu dica qualcosa.",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['flag' => 'cole_caused_death', 'is' => true],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Non è stata colpa tua',
                        'hint' => null,
                        'tags' => ['generous'],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'Cole', 'stress' => -20], ['modify_standing' => ['who' => 'Cole', 'delta' => 20]], $done], 'log' => 'Cole annuisce, gli occhi lucidi. Forse ci crederà, un giorno.']],
                    ],
                    [
                        'label' => 'Devi conviverci. Come tutti noi.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'Cole', 'stress' => 10], ['resource' => 'morale', 'delta' => -3], $done], 'log' => 'Cole incassa. È una verità dura, e lo sa.']],
                    ],
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `coleThread()` in `events()`**

In `events()`, dopo `$this->bexThread(),` aggiungi:

```php
            $this->coleThread(),
```

- [ ] **Step 3: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: Cole character thread (4 faces) with standing gates"
```

---

### Task 9: Eventi di intreccio (i personaggi si commentano)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

Tre eventi in cui un personaggio reagisce a cosa è successo con un altro, gated su flag-testimone/standing impostati dai fili.

- [ ] **Step 1: Aggiungi il metodo `crosstalkEvents()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo il metodo `coleThread()`, aggiungi:

```php
    // ---- Intreccio: i personaggi reagiscono l'uno all'altro -----------------
    private function crosstalkEvents(): array
    {
        return [
            // Bex commenta Anna che ha agito da sola.
            $this->ev([
                'key' => 'cross_bex_on_anna', 'title' => 'Bex parla di Anna',
                'body' => "Bex ti raggiunge a bassa voce. «Anna fa di testa sua. Stavolta è andata bene. Ma un giorno una delle sue 'soluzioni' la ucciderà, e nessuno l'avrà fermata.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'], ['has_role' => 'engineer'],
                    ['flag' => 'anna_overruled', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Parlerò con Anna',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Bex', 'delta' => 8]], ['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => 10]]], 'log' => 'Bex sembra sollevata che qualcuno la ascolti.']],
                    ],
                    [
                        'label' => 'Anna sa quello che fa',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Bex', 'delta' => -8]], ['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -10]]], 'log' => '«Certo. Lo sa sempre», dice Bex, e se ne va.']],
                    ],
                ],
            ]),

            // Cole reagisce se Bex ti ha contestato pubblicamente.
            $this->ev([
                'key' => 'cross_cole_on_bex', 'title' => 'Cole ci scherza su',
                'body' => "Cole abbassa la voce, mezzo sorriso nervoso. «Bex ti ha messo con le spalle al muro, eh? Almeno qualcuno qui dice le cose come stanno. Anche se non serve a niente.»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'], ['has_role' => 'doctor'],
                    ['flag' => 'bex_confronted', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Anche tu hai qualcosa da dirmi?',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 5]], ['character' => 'Cole', 'stress' => -5]], 'log' => '«No, no. Io guido e basta», alza le mani Cole.']],
                    ],
                    [
                        'label' => 'Torna alla tua postazione',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => -8]], ['set_flag' => 'cole_resentful', 'value' => true]], 'log' => 'Cole si chiude. Hai chiuso una porta che era socchiusa.']],
                    ],
                ],
            ]),

            // Anna giudica come gestisci Cole.
            $this->ev([
                'key' => 'cross_anna_on_cole', 'title' => 'Anna dice la sua su Cole',
                'body' => "Anna ti ferma vicino ai motori. «Cole è spaventato, non stupido. Lo stai trattando come un peso morto. Continua così e quando ti servirà davvero, non ci sarà.»",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'], ['has_role' => 'pilot'],
                    ['flag' => 'cole_resentful', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Hai ragione. Cambierò.',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 12]], ['modify_standing' => ['who' => 'Anna', 'delta' => 6]]], 'log' => 'Anna annuisce. «Bene. Allora forse ce la caviamo.»']],
                    ],
                    [
                        'label' => 'Ognuno porti il suo peso',
                        'hint' => null, 'tags' => ['il_freddo'],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -10]], ['resource' => 'morale', 'delta' => -4]], 'log' => '«Come vuoi. Ma te l\'ho detto», dice Anna.']],
                    ],
                ],
            ]),
        ];
    }
```

- [ ] **Step 2: Includi `crosstalkEvents()` in `events()`**

In `events()`, dopo `$this->coleThread(),` aggiungi:

```php
            $this->crosstalkEvents(),
```

- [ ] **Step 3: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: cross-character commentary events (intreccio)"
```

---

### Task 10: Bivii in valute diverse (dilemmi ardui)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

Quattro dilemmi senza risposta giusta. Ogni opzione costa in valute diverse; nessuna morte istantanea inevitabile (rispetta `BalanceTest`). Le reazioni emergono dai tag/effetti (Task 2) o da `reactions` esplicite.

- [ ] **Step 1: Aggiungi il metodo `dilemmaEvents()`**

In `backend/database/seeders/ContentEventSeeder.php`, subito dopo il metodo `crosstalkEvents()`, aggiungi:

```php
    // ---- Bivii ardui: due opzioni legittime, costi in valute diverse --------
    private function dilemmaEvents(): array
    {
        return [
            // L'ultima cella d'ossigeno — Anna intrappolata vs ferito curato da Bex.
            $this->ev([
                'key' => 'dilemma_oxygen_cell', 'title' => "L'ultima cella d'ossigeno",
                'body' => "Due settori perdono aria. Anna si è sigillata in uno per ripararlo. Bex tiene in vita un ferito nell'altro. Puoi pressurizzarne uno solo. L'altro lo perdi.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['resource' => 'oxygen', 'op' => '<', 'value' => 60],
                    ['day' => ['op' => '>=', 'value' => 6]],
                ]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Salva il settore di Anna',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 10], ['modify_standing' => ['who' => 'Anna', 'delta' => 15]], ['modify_standing' => ['who' => 'Bex', 'delta' => -25]], ['resource' => 'morale', 'delta' => -12]], 'log' => 'Anna è salva. Il ferito no. Bex non ti guarda più.',
                            'reactions' => [['who' => 'Bex', 'tone' => 'anger', 'line' => 'Era vivo. Potevo salvarlo.']]]],
                    ],
                    [
                        'label' => 'Salva il ferito di Bex',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 5], ['modify_standing' => ['who' => 'Bex', 'delta' => 15]], ['modify_standing' => ['who' => 'Anna', 'delta' => -20]], ['character' => 'Anna', 'stress' => 30], ['damage_system' => 'life_support', 'amount' => 15]], 'log' => 'Tiri fuori Anna all\'ultimo, sotto shock. Il settore è perso.',
                            'reactions' => [['who' => 'Anna', 'tone' => 'complicated', 'line' => 'Hai scelto. Lo capisco. Credo.']]]],
                    ],
                ],
            ]),

            // Il razionamento — equo vs efficiente.
            $this->ev([
                'key' => 'dilemma_rationing', 'title' => 'Come tagli le razioni',
                'body' => "Il cibo non basta per tutti alla razione piena. Tagli uguale per tutti, e tutti si indeboliscono. O togli ai più fragili per tenere in forza chi lavora. Non c'è una scelta pulita.",
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 35],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Taglio uguale per tutti',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => 3], ['modify_trust' => 5]], 'log' => 'Nessuno è contento. Ma nessuno è stato abbandonato.']],
                    ],
                    [
                        'label' => 'Tolgo ai più deboli',
                        'hint' => null, 'tags' => ['sacrifice_crew'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'food', 'delta' => 8], ['character' => 'highest_stress', 'stress' => 20], ['modify_trust' => -15]], 'log' => 'La stazione continua a funzionare. Qualcuno ti guarda diverso.']],
                    ],
                ],
            ]),

            // La trasmissione — SOS (rischio) vs dati di ricerca (significato).
            $this->ev([
                'key' => 'dilemma_transmission', 'title' => 'Un solo messaggio',
                'body' => "L'antenna ha energia per una trasmissione sola, poi tace. Un SOS — forse qualcuno viene, forse riveli la tua posizione a ciò che è là fuori. O i dati di ricerca, che danno un senso a tutto questo, ma non porteranno nessun soccorso.",
                'requires' => ['all' => [
                    ['has_item' => 'comms'],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lancia l\'SOS',
                        'hint' => 'incerto', 'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'morale', 'delta' => 15], ['set_flag' => 'sos_sent', 'value' => true]], 'log' => 'Il segnale parte nel buio. Ora si aspetta.'],
                            ['weight' => 4, 'effects' => [['resource' => 'morale', 'delta' => 8], ['spawn_event' => ['key' => 'c_real_threat', 'in_days' => 2]]], 'log' => 'Il segnale parte. Forse non eri l\'unico ad ascoltare.'],
                        ],
                    ],
                    [
                        'label' => 'Trasmetti i dati di ricerca',
                        'hint' => null, 'tags' => ['lone_decision'],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'research_complete', 'value' => true], ['resource' => 'morale', 'delta' => -8], ['character' => 'all', 'stress' => 6]], 'log' => 'I dati volano via. Qualcuno, un giorno, saprà. Voi resterete soli.']],
                    ],
                ],
            ]),

            // Chi tiene il pannello — vai tu (rischi il comando) o mandi un altro.
            $this->ev([
                'key' => 'dilemma_panel', 'title' => 'Chi tiene il pannello',
                'body' => "Una breccia. Qualcuno deve tenere un pannello dall'esterno mentre lo scafo vibra. Vai tu — e se non torni, chi guida? O mandi qualcuno dell'equipaggio, e tutti ti vedono scegliere chi rischia al posto tuo.",
                'requires' => ['all' => [
                    ['resource' => 'hull', 'op' => '<', 'value' => 40],
                    ['day' => ['op' => '>=', 'value' => 8]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Vai tu',
                        'hint' => 'rischioso', 'tags' => ['cautious'],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 25], ['modify_trust' => 20], ['character' => 'all', 'stress' => -5]], 'log' => 'Torni dentro intirizzito. L\'equipaggio ti guarda diversamente — meglio.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => 20], ['resource' => 'oxygen', 'delta' => -12], ['character' => 'all', 'stress' => 8]], 'log' => 'Tieni il pannello, ma quasi non rientri. È costato caro.'],
                        ],
                    ],
                    [
                        'label' => 'Mandi qualcuno',
                        'hint' => null, 'tags' => ['sacrifice_crew'],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 22], ['character' => 'random', 'stress' => 20], ['modify_trust' => -10]], 'log' => 'Regge. Chi è uscito rientra tremando, e non ti ringrazia.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => 18], ['kill' => 'random'], ['set_flag' => 'cole_caused_death', 'value' => true], ['set_flag' => 'bex_saw_death', 'value' => true]], 'log' => 'Lo scafo regge. La persona che hai mandato no.'],
                        ],
                    ],
                ],
            ]),
        ];
    }
```

> NOTA equità (`BalanceTest`): in `dilemma_panel` l'esito letale è solo un ramo *pesato* di "mandi qualcuno", e l'altra opzione ("vai tu") è sempre sopravvivibile — quindi esiste sempre una scelta che evita la morte. Questo soddisfa il probe di equità.

- [ ] **Step 2: Includi `dilemmaEvents()` in `events()`**

In `events()`, dopo `$this->crosstalkEvents(),` aggiungi:

```php
            $this->dilemmaEvents(),
```

- [ ] **Step 3: Ri-seed e test**

Run: `cd backend && php artisan db:seed --class=ContentEventSeeder && php artisan test`
Atteso: seeding senza errori, tutti i test PASS (incluso `BalanceTest`). Se `BalanceTest` segnala una morte inevitabile su un dilemma, rendi sempre presente almeno una scelta senza ramo letale.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: hard dilemma events with different-currency costs"
```

---

## FASE 4 — FRONTEND

### Task 11: api.ts — tipi per reazioni e standing

**Files:**
- Modify: `frontend/src/api.ts`

- [ ] **Step 1: Aggiungi il tipo Reaction e i campi**

In `frontend/src/api.ts`:

Aggiungi il tipo `Reaction` dopo `ResourceMeta`:

```typescript
export type Reaction = {
  who: string;
  tone: "anger" | "approve" | "complicated";
  line: string;
};
```

In `ChoiceLogEntry`, aggiungi `reaction_summary`:

```typescript
export type ChoiceLogEntry = {
  day: number;
  event_key: string;
  choice_index: number;
  choice_label: string;
  tags: string[];
  reaction_summary: string | null;
};
```

In `Character`, aggiungi `standing`:

```typescript
export type Character = {
  name: string;
  role: string | null;
  traits: string[];
  stress: number;
  alive: boolean;
  standing: number;
};
```

In `Resolution`, aggiungi `reactions` al sotto-oggetto `resolution`:

```typescript
export type Resolution = {
  resolution: { log: string; effects: unknown[]; ending: Ending; reactions: Reaction[] };
  state: RunState;
};
```

- [ ] **Step 2: Verifica build**

Run: `cd frontend && npx tsc --noEmit`
Atteso: nessun errore.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/api.ts
git commit -m "feat: api types for reactions, character standing, reaction_summary"
```

---

### Task 12: CrewPanel — anello di standing + parola d'umore + reazioni momentanee

**Files:**
- Modify: `frontend/src/components/CrewPanel.tsx`
- Modify: `frontend/src/components/GameScreen.tsx`
- Modify: `frontend/src/index.css`

Il `CrewPanel` riceve un nuovo prop `reactions: Reaction[]` (le reazioni dell'ultima risoluzione) e mostra, per ~3 secondi, una pulsazione colorata + la riga accanto all'avatar reagente. Lo standing diventa un anello colorato attorno all'avatar + una parola d'umore.

- [ ] **Step 1: Aggiungi le classi CSS**

In fondo a `frontend/src/index.css`, aggiungi:

```css
/* ---- Standing ring + mood ---- */
.standing-hostile  { box-shadow: 0 0 0 2px var(--color-red); }
.standing-cold     { box-shadow: 0 0 0 2px var(--color-text-muted); }
.standing-neutral  { box-shadow: none; }
.standing-trust    { box-shadow: 0 0 0 2px var(--color-cyan); }
.standing-bond     { box-shadow: 0 0 0 2px var(--color-gold); }

@keyframes react-pulse-anger    { 0%,100% { box-shadow: 0 0 0 2px transparent; } 50% { box-shadow: 0 0 0 3px var(--color-red); } }
@keyframes react-pulse-approve  { 0%,100% { box-shadow: 0 0 0 2px transparent; } 50% { box-shadow: 0 0 0 3px var(--color-cyan); } }
@keyframes react-pulse-complicated { 0%,100% { box-shadow: 0 0 0 2px transparent; } 50% { box-shadow: 0 0 0 3px var(--color-gold); } }
.react-anger       { animation: react-pulse-anger 0.6s ease-in-out 3; }
.react-approve     { animation: react-pulse-approve 0.6s ease-in-out 3; }
.react-complicated { animation: react-pulse-complicated 0.6s ease-in-out 3; }

.react-line {
  font-size: 11px; font-style: italic; line-height: 1.3;
  margin-top: 3px; animation: fade-in-up 300ms ease;
}
.react-line.anger       { color: var(--color-red); }
.react-line.approve     { color: var(--color-cyan); }
.react-line.complicated { color: var(--color-gold); }
```

- [ ] **Step 2: Riscrivi CrewPanel.tsx**

Sostituisci interamente `frontend/src/components/CrewPanel.tsx`:

```tsx
import type { Character, Reaction } from "../api";

const ROLE_LABELS: Record<string, string> = {
  engineer: "Ingegnere", doctor: "Medico", pilot: "Pilota", survivor: "Superstite",
};

const EPITHET_LABELS: Record<string, string> = {
  il_generoso: "il Generoso",
  il_freddo: "il Freddo",
  l_imprudente: "l'Imprudente",
  il_prudente: "il Prudente",
  il_solitario: "il Solitario",
};

// Standing → qualitative band (never a number on screen).
function standingBand(s: number): { ring: string; word: string } {
  if (s <= -40) return { ring: "standing-hostile", word: "ostile" };
  if (s <= -15) return { ring: "standing-cold", word: "freddo" };
  if (s < 15) return { ring: "standing-neutral", word: "neutro" };
  if (s < 40) return { ring: "standing-trust", word: "fiducia" };
  return { ring: "standing-bond", word: "legame" };
}

type Props = {
  characters: Character[];
  epithet?: string | null;
  reactions?: Reaction[];
};

export function CrewPanel({ characters, epithet, reactions = [] }: Props) {
  const reactionByName = new Map(reactions.map((r) => [r.who, r]));

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      <div style={{ fontSize: 10, letterSpacing: "0.14em", color: "var(--color-text-muted)", fontFamily: "var(--font-mono)" }}>
        EQUIPAGGIO
      </div>

      {characters.map((c) => {
        const roleKey = c.role ?? "survivor";
        const initials = c.name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();
        const stressPct = c.stress;
        const stressColor = stressPct >= 85 ? "var(--color-red)" : stressPct >= 60 ? "var(--color-orange)" : "var(--color-cyan-dim)";
        const band = standingBand(c.standing ?? 0);
        const reaction = c.alive ? reactionByName.get(c.name) : undefined;
        const avatarClass = c.alive
          ? `crew-avatar ${roleKey} ${reaction ? `react-${reaction.tone}` : band.ring}`
          : "crew-avatar dead";

        return (
          <div key={c.name} data-testid={`crew-${c.name}`}
               style={{ display: "flex", gap: 10, alignItems: "flex-start", opacity: c.alive ? 1 : 0.4 }}>
            <div className={avatarClass}>{initials}</div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline" }}>
                <span style={{ fontSize: 13, fontWeight: 600, textDecoration: c.alive ? "none" : "line-through", color: "var(--color-text)" }}>
                  {c.name}
                </span>
                <span style={{ fontSize: 10, color: "var(--color-text-muted)" }}>
                  {c.alive ? band.word : (ROLE_LABELS[roleKey] ?? roleKey)}
                </span>
              </div>
              {c.alive ? (
                <div style={{ marginTop: 4 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}>
                    <span style={{ fontSize: 10, color: "var(--color-text-dim)" }}>stress</span>
                    <span style={{ fontSize: 10, fontFamily: "var(--font-mono)", color: stressColor }}>{stressPct}</span>
                  </div>
                  <div className="bar-track" style={{ height: 4 }}>
                    <div style={{
                      height: "100%", borderRadius: 4, width: `${stressPct}%`,
                      background: stressColor, transition: "width 500ms ease",
                    }} />
                  </div>
                  {reaction && (
                    <div className={`react-line ${reaction.tone}`}>«{reaction.line}»</div>
                  )}
                </div>
              ) : (
                <div style={{ fontSize: 10, color: "var(--color-red)", marginTop: 2 }}>— perso —</div>
              )}
            </div>
          </div>
        );
      })}

      {epithet && (
        <div style={{
          marginTop: 4, padding: "6px 10px",
          background: "rgba(255,209,102,0.06)",
          border: "1px solid var(--color-gold-dim)",
          borderRadius: 8,
          fontSize: 11, color: "var(--color-gold)", fontStyle: "italic",
        }}>
          Comandante {EPITHET_LABELS[epithet] ?? epithet}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Passa le reazioni dal GameScreen**

In `frontend/src/components/GameScreen.tsx`:

Aggiorna il tipo `Props` per accettare `reactions`:

```tsx
type Props = {
  run: RunState;
  busy: boolean;
  lastLog: string | null;
  reactions: Reaction[];
  onChoose: (index: number) => void;
  onAdvance: () => void;
};
```

Aggiorna l'import dei tipi in cima al file:

```tsx
import type { RunState, Reaction } from "../api";
```

Aggiorna la firma della funzione e il passaggio del prop al `CrewPanel`:

```tsx
export function GameScreen({ run, busy, lastLog, reactions, onChoose, onAdvance }: Props) {
```

E nel JSX, sostituisci `<CrewPanel characters={run.characters} epithet={run.epithet} />` con:

```tsx
          <CrewPanel characters={run.characters} epithet={run.epithet} reactions={reactions} />
```

- [ ] **Step 4: Conserva e passa le reazioni in App.tsx**

In `frontend/src/App.tsx`:

Aggiungi `Reaction` all'import dei tipi in cima al file (insieme a quanto già importato da `./api`, es. `import type { Reaction } from "./api";` se non c'è già un import di tipo). Poi aggiungi uno stato per le reazioni e popolalo in `onChoose`. Sostituisci il blocco `const onChoose = ...` con:

```tsx
  const [reactions, setReactions] = useState<Reaction[]>([]);

  const onChoose = useCallback(
    async (index: number) => {
      const result = await choose(index);
      setLastLog(result?.log ?? null);
      setReactions(result?.reactions ?? []);
    },
    [choose],
  );
```

E passa `reactions` a `GameScreen`:

```tsx
        <GameScreen
          run={run}
          busy={busy}
          lastLog={lastLog}
          reactions={reactions}
          onChoose={onChoose}
          onAdvance={advance}
        />
```

> Nota: questo richiede che `choose` ritorni `{ log, reactions }` invece della sola stringa di log. Lo aggiorniamo nello Step 5.

- [ ] **Step 5: useRun.choose ritorna log + reactions**

In `frontend/src/useRun.ts`, aggiungi `Reaction` all'import esistente da `./api` (`import { advanceRun, resolveChoice, startRun, type RunState, type Reaction } from "./api";`), poi aggiorna `choose` perché ritorni log e reazioni:

```tsx
  const choose = useCallback(
    async (choiceIndex: number): Promise<{ log: string | null; reactions: Reaction[] } | null> => {
      if (!run || busy) return null;
      setBusy(true);
      try {
        const res = await resolveChoice(run.id, choiceIndex);
        setRun(res.state);
        return { log: res.resolution.log ?? null, reactions: res.resolution.reactions ?? [] };
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

- [ ] **Step 6: Verifica build**

Run: `cd frontend && npx tsc --noEmit`
Atteso: nessun errore.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/CrewPanel.tsx frontend/src/components/GameScreen.tsx frontend/src/App.tsx frontend/src/useRun.ts frontend/src/index.css
git commit -m "feat: crew standing ring, mood word, and momentary reaction lines"
```

---

### Task 13: Diario — pannello apribile dall'header

**Files:**
- Create: `frontend/src/components/Diario.tsx`
- Modify: `frontend/src/components/GameScreen.tsx`

Un pannello apribile da un'icona nell'header che mostra le ultime scelte (dal `choice_log`) con la loro ricaduta (`reaction_summary`).

- [ ] **Step 1: Crea Diario.tsx**

Crea `frontend/src/components/Diario.tsx`:

```tsx
import type { ChoiceLogEntry } from "../api";

type Props = {
  log: ChoiceLogEntry[];
  open: boolean;
  onClose: () => void;
};

export function Diario({ log, open, onClose }: Props) {
  if (!open) return null;

  const entries = [...log].reverse();

  return (
    <div
      onClick={onClose}
      style={{
        position: "absolute", inset: 0, zIndex: 40,
        background: "rgba(3,6,14,0.7)", backdropFilter: "blur(2px)",
        display: "flex", justifyContent: "center", alignItems: "flex-start",
        paddingTop: 60,
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: "min(520px, 92vw)", maxHeight: "70vh", overflowY: "auto",
          background: "var(--color-surface-card)", border: "1px solid var(--color-border)",
          borderRadius: 14, padding: "18px 20px",
          boxShadow: "0 16px 48px rgba(0,0,0,0.6)",
        }}
      >
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 14 }}>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, letterSpacing: "0.18em", color: "var(--color-cyan)" }}>
            DIARIO DI BORDO
          </span>
          <button onClick={onClose} style={{
            background: "transparent", border: "none", color: "var(--color-text-dim)",
            fontSize: 18, cursor: "pointer", lineHeight: 1,
          }}>×</button>
        </div>

        {entries.length === 0 ? (
          <div style={{ color: "var(--color-text-muted)", fontSize: 13, fontStyle: "italic" }}>
            Ancora nessuna decisione registrata.
          </div>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            {entries.map((e, i) => (
              <div key={`${e.day}-${e.event_key}-${i}`} style={{ borderLeft: "2px solid var(--color-border-hi)", paddingLeft: 12 }}>
                <div style={{ fontSize: 10, fontFamily: "var(--font-mono)", color: "var(--color-text-muted)" }}>
                  GIORNO {e.day}
                </div>
                <div style={{ fontSize: 13, color: "var(--color-text)", marginTop: 2 }}>
                  {e.choice_label}
                </div>
                {e.reaction_summary && (
                  <div style={{ fontSize: 12, fontStyle: "italic", color: "var(--color-orange)", marginTop: 3 }}>
                    {e.reaction_summary}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Aggiungi il toggle nell'header del GameScreen**

In `frontend/src/components/GameScreen.tsx`:

Aggiungi gli import:

```tsx
import { useEffect, useState } from "react";
import { Diario } from "./Diario";
```

(Se `useState`/`useEffect` sono già importati, non duplicarli — assicurati solo che `useState` sia presente.)

Dentro il componente, aggiungi lo stato del diario in cima (accanto a `flash`):

```tsx
  const [diaryOpen, setDiaryOpen] = useState(false);
```

Avvolgi il contenuto radice in modo che il `Diario` possa sovrapporsi: il `div` radice ha già `position`? In caso contrario aggiungi `position: "relative"` allo stile del `div` radice (quello con `display: "grid"`). Poi, nell'`header`, tra lo `<span>` STARFALL STATION e lo `<span>` GIORNO, inserisci il bottone diario a destra del titolo — sostituisci il blocco `<header>` con:

```tsx
      <header style={{
        display: "flex", alignItems: "center", justifyContent: "space-between",
        padding: "0 20px",
        borderBottom: "1px solid var(--color-border)",
        background: "var(--color-surface)",
        flexShrink: 0,
      }}>
        <span style={{
          fontFamily: "var(--font-mono)", fontSize: 11,
          color: "var(--color-text-muted)", letterSpacing: "0.18em",
        }}>
          STARFALL STATION
        </span>
        <div style={{ display: "flex", alignItems: "center", gap: 16 }}>
          <button
            data-testid="diary-toggle"
            onClick={() => setDiaryOpen((v) => !v)}
            style={{
              background: "transparent", border: "1px solid var(--color-border-hi)",
              borderRadius: 8, color: "var(--color-text-dim)", cursor: "pointer",
              fontSize: 11, fontFamily: "var(--font-mono)", padding: "3px 10px",
              letterSpacing: "0.1em",
            }}
          >
            DIARIO
          </button>
          <span data-testid="day" style={{
            fontFamily: "var(--font-mono)", fontSize: 13,
            color: "var(--color-cyan)", fontWeight: 700,
          }}>
            GIORNO {run.day}
          </span>
        </div>
      </header>
```

Assicurati che il `div` radice del componente abbia `position: "relative"` nello stile inline (aggiungilo se assente). Poi, subito prima della chiusura del `div` radice (dopo il `<footer>`), aggiungi:

```tsx
      <Diario log={run.choice_log} open={diaryOpen} onClose={() => setDiaryOpen(false)} />
```

- [ ] **Step 3: Verifica build**

Run: `cd frontend && npx tsc --noEmit`
Atteso: nessun errore.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/Diario.tsx frontend/src/components/GameScreen.tsx
git commit -m "feat: Diario panel surfacing choices and their fallout"
```

---

## FASE 5 — VERIFICA

### Task 14: Test end-to-end

**Files:** nessuno (verifica manuale + API).

- [ ] **Step 1: Ri-seed completo e suite backend**

Run: `cd backend && php artisan migrate:fresh --seed && php artisan test`
Atteso: tutti i test PASS.

- [ ] **Step 2: Avvia i server**

Backend: `cd backend && php artisan serve --port=8000` (in background)
Frontend: `cd frontend && npm run dev` (in background)

- [ ] **Step 3: Verifica via API che lo standing si muova**

Avvia una run, scegli un'opzione con tag `sacrifice_crew` (es. un dilemma o `dilemma_rationing` opzione 1) e verifica nel payload successivo:
- `resolution.reactions` contiene una reazione di Bex (`tone: anger`);
- `GET /api/runs/{id}` → il personaggio Bex ha `standing` negativo;
- l'ultima entry di `choice_log` ha `reaction_summary` valorizzato.

- [ ] **Step 4: Verifica visiva nel browser**

Apri `http://localhost:5173` e verifica:
- dopo una scelta fredda, l'avatar di Bex **pulsa rosso** e mostra la riga «Non dovevi farlo.»;
- l'anello attorno agli avatar cambia colore col tempo (freddo → fiducia → legame);
- la parola d'umore accanto al nome cambia;
- il bottone **DIARIO** apre il pannello con le scelte e le ricadute.

- [ ] **Step 5: Verifica varietà (replayability)**

Gioca 2-3 run con loadout diversi (una con `welder`/`toolkit`, una con `medkit`/`scanner`, una con `spacesuit`/`rations`) e verifica che i volti dei personaggi che emergono siano diversi.

- [ ] **Step 6: Commit finale**

```bash
git add -A
git commit -m "test: end-to-end verification of living-crew system"
```

---

## Note per l'esecutore

- I metodi-filo (`annaThread`/`bexThread`/`coleThread`/`crosstalkEvents`/`dilemmaEvents`) vanno tutti aggiunti dentro la classe `ContentEventSeeder` e inclusi nell'`array_merge` di `events()`.
- Lo standing usa SEMPRE la chiave `flags["standing_" . strtolower(nome)]`. Non deviare dal formato.
- I flag-testimone (`bex_saw_death`, `cole_caused_death`, `anna_overruled`, ecc.) vengono impostati dagli effetti `set_flag` dentro gli outcome; i volti li leggono con la condizione `flag`. `cole_caused_death` è impostato nel dilemma del pannello (Task 10) e da eventuali `kill` causati da Cole.
- `BalanceTest` impone equità: ogni evento in cui una scelta può uccidere deve avere almeno un'altra scelta sopravvivibile. I dilemmi sono costruiti così; se aggiungi varianti, mantieni questa proprietà.
- Frontend senza harness di test: la verifica è `npx tsc --noEmit` + il giro e2e del Task 14.
