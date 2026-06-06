# Spacefall Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign visivo cosmico + sistema di memoria scelte + meccaniche di profondità narrativa (domino, trap, trust, epiteto, finali stratificati).

**Architecture:** Backend-first: aggiungere `choice_log` al modello Run, estendere ConditionEvaluator/EffectApplier con le nuove primitive, poi due nuovi engine (EpithetEngine, TrustEngine). Frontend: redesign CSS completo + componenti aggiornati per consumare i nuovi dati API.

**Tech Stack:** PHP 8.3 / Laravel 12 / SQLite · React 19 / TypeScript / Tailwind 4 / Vite · Pest per i test PHP · Vitest per i test frontend

---

## FASE 1 — BACKEND INFRASTRUTTURA

### Task 1: Migration — choice_log su runs

**Files:**
- Create: `backend/database/migrations/2026_06_06_200000_add_choice_log_to_runs.php`
- Modify: `backend/app/Models/Run.php`

- [ ] **Step 1: Crea la migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('choice_log')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('choice_log');
        });
    }
};
```

- [ ] **Step 2: Aggiungi `choice_log` al modello Run**

In `backend/app/Models/Run.php` aggiungi `'choice_log'` al cast e al fillable:

```php
// In $fillable, aggiungere:
'choice_log',

// In $casts, aggiungere:
'choice_log' => 'array',

// In $attributes, aggiungere:
'choice_log' => '[]',
```

- [ ] **Step 3: Esegui la migration**

```bash
cd backend && php artisan migrate
```

Output atteso: `add_choice_log_to_runs .... DONE`

- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_06_06_200000_add_choice_log_to_runs.php backend/app/Models/Run.php
git commit -m "feat: add choice_log column to runs"
```

---

### Task 2: RunState — aggiungere choiceLog

**Files:**
- Modify: `backend/app/Game/Engine/RunState.php`

- [ ] **Step 1: Scrivi il test**

Crea `backend/tests/Unit/RunStateChoiceLogTest.php`:

```php
<?php
use App\Game\Engine\RunState;
use App\Models\Run;

it('loads choice_log from run model', function () {
    $run = new Run([
        'day' => 1,
        'resources' => ['oxygen' => 100],
        'choice_log' => [['day' => 1, 'event_key' => 'foo', 'choice_index' => 0, 'tags' => []]],
    ]);
    $state = RunState::fromRun($run);
    expect($state->choiceLog)->toHaveCount(1);
    expect($state->choiceLog[0]['event_key'])->toBe('foo');
});

it('writes choice_log back to run', function () {
    $run = new Run(['day' => 1, 'resources' => [], 'choice_log' => []]);
    $state = RunState::fromRun($run);
    $state->choiceLog[] = ['day' => 2, 'event_key' => 'bar', 'choice_index' => 1, 'tags' => ['risk']];
    $state->applyTo($run);
    expect($run->choice_log)->toHaveCount(1);
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

```bash
cd backend && php artisan test tests/Unit/RunStateChoiceLogTest.php
```

Output atteso: FAIL — `choiceLog` non esiste su `RunState`

- [ ] **Step 3: Aggiorna RunState**

```php
public function __construct(
    public int $day,
    public array $resources,
    public array $flags = [],
    public array $recentEvents = [],
    public array $scheduledEvents = [],
    public array $profileFlags = [],
    public array $characters = [],
    public array $items = [],
    public array $systems = [],
    public array $relationships = [],
    public array $choiceLog = [],   // ← aggiunto
) {}

public static function fromRun(Run $run): self
{
    $profileFlags = $run->profile?->flags ?? [];

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
        choiceLog: $run->choice_log ?? [],   // ← aggiunto
    );
}

public function applyTo(Run $run): void
{
    $run->day = $this->day;
    $run->resources = $this->resources;
    $run->flags = $this->flags;
    $run->recent_events = $this->recentEvents;
    $run->scheduled_events = $this->scheduledEvents;
    $run->characters = $this->characters;
    $run->relationships = $this->relationships;
    $run->systems = $this->systems;
    $run->choice_log = $this->choiceLog;   // ← aggiunto
}
```

- [ ] **Step 4: Esegui il test (deve passare)**

```bash
cd backend && php artisan test tests/Unit/RunStateChoiceLogTest.php
```

Output atteso: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/RunState.php backend/tests/Unit/RunStateChoiceLogTest.php
git commit -m "feat: add choiceLog to RunState"
```

---

### Task 3: EventEngine — registra scelte nel choice_log

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`

- [ ] **Step 1: Scrivi il test**

Aggiungi in `backend/tests/Feature/ChoiceApiTest.php` (già esiste — aggiungi in fondo):

```php
it('appends to choice_log after resolving a choice', function () {
    $run = \App\Models\Run::factory()->withEvents()->create();
    // Resolve il primo choice (index 0)
    $this->postJson("/api/runs/{$run->id}/choices", ['choice' => 0]);
    $run->refresh();
    expect($run->choice_log)->not->toBeEmpty();
    $entry = $run->choice_log[0];
    expect($entry)->toHaveKeys(['day', 'event_key', 'choice_index', 'tags']);
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

```bash
cd backend && php artisan test tests/Feature/ChoiceApiTest.php --filter "appends to choice_log"
```

Output atteso: FAIL

- [ ] **Step 3: Aggiorna EventEngine.resolveChoice**

In `resolveChoice`, subito prima di `$state->applyTo($run)`, aggiungi:

```php
// Registra la scelta nel log narrativo.
$entry = [
    'day'          => $state->day,
    'event_key'    => $event->key,
    'choice_index' => $choiceIndex,
    'choice_label' => $choice['label'] ?? '',
    'tags'         => $choice['tags'] ?? [],
];
$state->choiceLog = array_slice(
    array_merge($state->choiceLog, [$entry]),
    -30  // tieni al massimo 30 voci: sufficiente per domino a lungo raggio
);
```

- [ ] **Step 4: Esegui il test (deve passare)**

```bash
cd backend && php artisan test tests/Feature/ChoiceApiTest.php
```

Output atteso: PASS (tutti i test del file)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php
git commit -m "feat: record choice in choice_log on each resolution"
```

---

### Task 4: ConditionEvaluator — condizioni su choice history

**Files:**
- Modify: `backend/app/Game/Engine/ConditionEvaluator.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Modify: `backend/tests/Unit/ConditionEvaluatorTest.php`

Le nuove condizioni:
- `{ chosen: "event_key:choice_index" }` — vero se il giocatore ha fatto quella scelta
- `{ chosen_tag: "tag" }` — vero se qualsiasi scelta nel log ha quel tag
- `{ not_chosen: "event_key:choice_index" }` — negazione

- [ ] **Step 1: Scrivi i test**

Aggiungi in `backend/tests/Unit/ConditionEvaluatorTest.php`:

```php
it('evaluates chosen condition', function () {
    $state = new \App\Game\Engine\RunState(
        day: 5,
        resources: [],
        choiceLog: [
            ['day' => 2, 'event_key' => 'hull_warning', 'choice_index' => 1, 'tags' => ['ignored_warning']],
        ]
    );
    $ev = new \App\Game\Engine\ConditionEvaluator;

    expect($ev->evaluate(['chosen' => 'hull_warning:1'], $state))->toBeTrue();
    expect($ev->evaluate(['chosen' => 'hull_warning:0'], $state))->toBeFalse();
    expect($ev->evaluate(['chosen_tag' => 'ignored_warning'], $state))->toBeTrue();
    expect($ev->evaluate(['chosen_tag' => 'nonexistent'], $state))->toBeFalse();
    expect($ev->evaluate(['not_chosen' => 'hull_warning:0'], $state))->toBeTrue();
});
```

- [ ] **Step 2: Esegui il test (deve fallire)**

```bash
cd backend && php artisan test tests/Unit/ConditionEvaluatorTest.php --filter "evaluates chosen"
```

- [ ] **Step 3: Aggiungi i rami nel ConditionEvaluator**

Dopo il blocco `system`:

```php
if (array_key_exists('chosen', $condition)) {
    [$eventKey, $indexStr] = array_pad(explode(':', $condition['chosen'], 2), 2, '0');
    $index = (int) $indexStr;
    foreach ($state->choiceLog as $entry) {
        if (($entry['event_key'] ?? null) === $eventKey && ($entry['choice_index'] ?? -1) === $index) {
            return true;
        }
    }
    return false;
}

if (array_key_exists('chosen_tag', $condition)) {
    $tag = $condition['chosen_tag'];
    foreach ($state->choiceLog as $entry) {
        if (in_array($tag, $entry['tags'] ?? [], true)) {
            return true;
        }
    }
    return false;
}

if (array_key_exists('not_chosen', $condition)) {
    [$eventKey, $indexStr] = array_pad(explode(':', $condition['not_chosen'], 2), 2, '0');
    $index = (int) $indexStr;
    foreach ($state->choiceLog as $entry) {
        if (($entry['event_key'] ?? null) === $eventKey && ($entry['choice_index'] ?? -1) === $index) {
            return false;
        }
    }
    return true;
}
```

- [ ] **Step 4: Aggiorna EventSchema — aggiungi le nuove condition keys**

In `EventSchema.php`, nella costante `CONDITION_KEYS`:

```php
private const CONDITION_KEYS = [
    'all', 'any', 'not', 'resource', 'day', 'flag', 'has_item',
    'has_role', 'trait_present', 'relationship', 'system',
    'chosen', 'chosen_tag', 'not_chosen',   // ← aggiunti
];
```

- [ ] **Step 5: Esegui tutti i test unit**

```bash
cd backend && php artisan test tests/Unit/
```

Output atteso: tutti PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/ConditionEvaluator.php backend/app/Game/Engine/EventSchema.php backend/tests/Unit/ConditionEvaluatorTest.php
git commit -m "feat: add chosen/chosen_tag/not_chosen conditions to ConditionEvaluator"
```

---

### Task 5: EffectApplier — consume_item, grant_item, modify_trust

**Files:**
- Modify: `backend/app/Game/Engine/EffectApplier.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`
- Modify: `backend/tests/Unit/EffectApplierTest.php`

- [ ] **Step 1: Scrivi i test**

Aggiungi in `backend/tests/Unit/EffectApplierTest.php`:

```php
it('consumes an item from state', function () {
    $state = makeState(items: ['med_kit', 'torch']);
    applier()->apply([['consume_item' => 'med_kit']], $state, rng());
    expect($state->items)->toBe(['torch']);
});

it('grants an item to state', function () {
    $state = makeState(items: []);
    applier()->apply([['grant_item' => 'rope']], $state, rng());
    expect($state->items)->toContain('rope');
});

it('modifies crew_trust flag', function () {
    $state = makeState();
    $state->flags['crew_trust'] = 50;
    applier()->apply([['modify_trust' => -15]], $state, rng());
    expect($state->flags['crew_trust'])->toBe(35);
});

it('clamps crew_trust between 0 and 100', function () {
    $state = makeState();
    $state->flags['crew_trust'] = 5;
    applier()->apply([['modify_trust' => -20]], $state, rng());
    expect($state->flags['crew_trust'])->toBe(0);
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

```bash
cd backend && php artisan test tests/Unit/EffectApplierTest.php --filter "consumes|grants|modify"
```

- [ ] **Step 3: Aggiungi i nuovi effetti in EffectApplier**

Dopo il blocco `grant_research_points`:

```php
if (array_key_exists('consume_item', $effect)) {
    $key = $effect['consume_item'];
    $state->items = array_values(array_filter($state->items, fn ($k) => $k !== $key));
    return;
}

if (array_key_exists('grant_item', $effect)) {
    if (! in_array($effect['grant_item'], $state->items, true)) {
        $state->items[] = $effect['grant_item'];
    }
    return;
}

if (array_key_exists('modify_trust', $effect)) {
    $current = (int) ($state->flags['crew_trust'] ?? 60);
    $state->flags['crew_trust'] = max(0, min(100, $current + (int) $effect['modify_trust']));
    return;
}
```

- [ ] **Step 4: Aggiorna EventSchema EFFECT_KEYS**

```php
private const EFFECT_KEYS = [
    'resource', 'set_flag', 'spawn_event', 'character', 'relationship',
    'damage_system', 'recruit', 'kill', 'grant_research_points',
    'consume_item', 'grant_item', 'modify_trust',   // ← aggiunti
];
```

- [ ] **Step 5: Aggiungi `crew_trust` iniziale in RunFactory**

In `backend/app/Game/RunFactory.php`, dove vengono impostati i flags iniziali del run, aggiungi:

```php
'flags' => ['crew_trust' => 60],
```

(Cerca il punto dove viene creato il Run e i flags vengono inizializzati.)

- [ ] **Step 6: Esegui tutti i test unit**

```bash
cd backend && php artisan test tests/Unit/
```

Output atteso: tutti PASS

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/EffectApplier.php backend/app/Game/Engine/EventSchema.php backend/app/Game/RunFactory.php backend/tests/Unit/EffectApplierTest.php
git commit -m "feat: add consume_item, grant_item, modify_trust effects"
```

---

### Task 6: EpithetEngine — calcola epiteto dal choice_log

**Files:**
- Create: `backend/app/Game/Engine/EpithetEngine.php`
- Create: `backend/tests/Unit/EpithetEngineTest.php`

L'epiteto è calcolato dal pattern di tag nel choice_log. I tag più frequenti determinano il titolo.

- [ ] **Step 1: Scrivi i test**

```php
<?php
use App\Game\Engine\EpithetEngine;
use App\Game\Engine\RunState;

it('returns null when no clear pattern', function () {
    $state = new RunState(day: 5, resources: [], choiceLog: []);
    expect((new EpithetEngine)->calculate($state))->toBeNull();
});

it('returns il_generoso when 4+ generous tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['generous']], ['tags' => ['generous']],
        ['tags' => ['generous']], ['tags' => ['generous']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe('il_generoso');
});

it('returns il_freddo when 4+ sacrifice_crew tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['sacrifice_crew']], ['tags' => ['sacrifice_crew']],
        ['tags' => ['sacrifice_crew']], ['tags' => ['sacrifice_crew']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe('il_freddo');
});

it('returns l_imprudente when 4+ ignored_warning tags', function () {
    $state = new RunState(day: 10, resources: [], choiceLog: [
        ['tags' => ['ignored_warning']], ['tags' => ['ignored_warning']],
        ['tags' => ['ignored_warning']], ['tags' => ['ignored_warning']],
    ]);
    expect((new EpithetEngine)->calculate($state))->toBe("l_imprudente");
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

```bash
cd backend && php artisan test tests/Unit/EpithetEngineTest.php
```

- [ ] **Step 3: Implementa EpithetEngine**

```php
<?php

namespace App\Game\Engine;

final class EpithetEngine
{
    private const PATTERNS = [
        'il_generoso'   => ['generous'],
        'il_freddo'     => ['sacrifice_crew'],
        "l_imprudente"  => ['ignored_warning'],
        'il_prudente'   => ['cautious'],
        'il_solitario'  => ['lone_decision'],
    ];

    private const THRESHOLD = 4;

    public function calculate(RunState $state): ?string
    {
        $counts = [];
        foreach ($state->choiceLog as $entry) {
            foreach ($entry['tags'] ?? [] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        foreach (self::PATTERNS as $epithet => $tags) {
            $total = 0;
            foreach ($tags as $tag) {
                $total += $counts[$tag] ?? 0;
            }
            if ($total >= self::THRESHOLD) {
                return $epithet;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Esegui i test (devono passare)**

```bash
cd backend && php artisan test tests/Unit/EpithetEngineTest.php
```

- [ ] **Step 5: Commit**

```bash
git add backend/app/Game/Engine/EpithetEngine.php backend/tests/Unit/EpithetEngineTest.php
git commit -m "feat: add EpithetEngine - calculates commander epithet from choice patterns"
```

---

### Task 7: TrustEngine — ammutinamento quando crew_trust < 20

**Files:**
- Create: `backend/app/Game/Engine/TrustEngine.php`
- Create: `backend/tests/Unit/TrustEngineTest.php`
- Modify: `backend/app/Game/Engine/EventEngine.php`

- [ ] **Step 1: Scrivi i test**

```php
<?php
use App\Game\Engine\TrustEngine;
use App\Game\Engine\RunState;

it('returns false when trust is above threshold', function () {
    $state = new RunState(day: 5, resources: [], flags: ['crew_trust' => 50]);
    expect((new TrustEngine)->shouldMutiny($state))->toBeFalse();
});

it('returns true when trust is below 20 and crew alive', function () {
    $state = new RunState(
        day: 5, resources: [],
        flags: ['crew_trust' => 15],
        characters: [['name' => 'Ayaka', 'alive' => true, 'stress' => 40]],
    );
    expect((new TrustEngine)->shouldMutiny($state))->toBeTrue();
});

it('returns false when trust is low but no living crew', function () {
    $state = new RunState(
        day: 5, resources: [],
        flags: ['crew_trust' => 10],
        characters: [['name' => 'Ayaka', 'alive' => false, 'stress' => 90]],
    );
    expect((new TrustEngine)->shouldMutiny($state))->toBeFalse();
});
```

- [ ] **Step 2: Esegui i test (devono fallire)**

```bash
cd backend && php artisan test tests/Unit/TrustEngineTest.php
```

- [ ] **Step 3: Implementa TrustEngine**

```php
<?php

namespace App\Game\Engine;

final class TrustEngine
{
    private const MUTINY_THRESHOLD = 20;

    public function shouldMutiny(RunState $state): bool
    {
        $trust = (int) ($state->flags['crew_trust'] ?? 60);
        if ($trust >= self::MUTINY_THRESHOLD) {
            return false;
        }

        // Ammutinamento richiede almeno un membro dell'equipaggio vivo.
        foreach ($state->characters as $c) {
            if ($c['alive'] ?? true) {
                return true;
            }
        }
        return false;
    }

    public function mutinyEventKey(): string
    {
        return 'mutiny_trigger';
    }
}
```

- [ ] **Step 4: Integra TrustEngine in EventEngine**

In `EventEngine`, aggiungere `TrustEngine` come dipendenza e chiamarlo in `currentCard()`:

```php
// Costruttore — aggiungere:
private readonly TrustEngine $trust,

// In currentCard(), subito dopo aver verificato lo status active,
// prima di selezionare una nuova carta:
if (! $run->current_event_key && $this->trust->shouldMutiny($state)) {
    $mutinyEvent = Event::where('key', $this->trust->mutinyEventKey())->first();
    if ($mutinyEvent) {
        $run->current_event_key = $mutinyEvent->key;
        $run->flags = array_merge($run->flags ?? [], ['crew_trust' => 20]); // reset per evitare loop
        $run->save();
        return ['event' => $mutinyEvent, 'choices' => $this->visibleChoices($mutinyEvent, $state)];
    }
}
```

- [ ] **Step 5: Aggiorna il provider per iniettare TrustEngine**

In `backend/app/Providers/AppServiceProvider.php`, nella registrazione di `EventEngine`, aggiungi `TrustEngine` all'istanziazione (o usa il container automatico di Laravel se tutti i parametri sono risolti).

- [ ] **Step 6: Esegui i test**

```bash
cd backend && php artisan test tests/Unit/TrustEngineTest.php
cd backend && php artisan test
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Game/Engine/TrustEngine.php backend/tests/Unit/TrustEngineTest.php backend/app/Game/Engine/EventEngine.php backend/app/Providers/AppServiceProvider.php
git commit -m "feat: add TrustEngine - mutiny trigger when crew_trust < 20"
```

---

### Task 8: EventEngine — hint corrotti + silent card + epiteto

**Files:**
- Modify: `backend/app/Game/Engine/EventEngine.php`
- Modify: `backend/app/Game/Engine/EventSchema.php`

**Hint corrotti:** quando morale < 25 o speaker.stress > 80, il 25% delle volte l'hint di una scelta viene sostituito con uno fuorviante.

**Silent card:** eventi con `is_silent: true` non hanno scelte — il frontend li avanza automaticamente dopo 4 secondi.

**Epiteto:** calcolato dopo ogni scelta e salvato nei profileFlags.

- [ ] **Step 1: Aggiorna EventSchema — riconoscere `is_silent` e `tags` nelle scelte**

In `EventSchema`:
```php
// Nessuna modifica necessaria alla validazione: is_silent è opzionale e
// tags su choices è un array di stringhe libere. Il seeder li usa già.
// Aggiungere solo un commento nel CONDITION_KEYS per chiarezza.
```

- [ ] **Step 2: Aggiorna visibleChoices per hint corrotti**

```php
private function visibleChoices(Event $event, RunState $state): array
{
    $speaker = $this->resolveSpeaker($event, $state);
    $corrupted = $this->shouldCorruptHints($state, $speaker);

    $out = [];
    foreach ($event->choices as $index => $choice) {
        $available = $this->evaluator->evaluate($choice['requires'] ?? null, $state);
        $hint = $this->hints->hintFor($choice, $speaker);

        // Con morale basso o speaker stressato, gli hint possono mentire.
        if ($corrupted && $hint !== null && count($event->choices) > 1) {
            $otherChoices = array_filter($event->choices, fn ($c, $i) => $i !== $index, ARRAY_FILTER_USE_BOTH);
            if ($otherChoices !== []) {
                $randomChoice = $otherChoices[array_rand($otherChoices)];
                $hint = $this->hints->hintFor($randomChoice, $speaker) ?? $hint;
            }
        }

        $out[] = [
            'index'     => $index,
            'label'     => $choice['label'] ?? '',
            'hint'      => $hint,
            'available' => $available,
            'requires_item' => $choice['requires_item'] ?? null,
        ];
    }
    return $out;
}

private function shouldCorruptHints(RunState $state, ?array $speaker): bool
{
    $moraleLow = ($state->resources['morale'] ?? 100) < 25;
    $speakerStressed = $speaker !== null && ($speaker['stress'] ?? 0) > 80;
    if (! $moraleLow && ! $speakerStressed) {
        return false;
    }
    // Deterministico sulla RNG del run — non usiamo rand() per preservare
    // la riproducibilità. Usiamo una semplice firma giorno+evento.
    return (($state->day * 7 + strlen($speaker['name'] ?? '')) % 4) === 0;
}
```

- [ ] **Step 3: Aggiorna resolveChoice — calcola e salva epiteto**

In `resolveChoice`, dopo `$this->profileSync->flush($run, $state)`:

```php
// Calcola e persiste l'epiteto nel profilo.
$epithet = $this->epithet->calculate($state);
if ($epithet !== null && $run->profile) {
    $run->profile->flags = array_merge($run->profile->flags ?? [], ['epithet' => $epithet]);
    $run->profile->save();
}
```

Aggiungi `EpithetEngine $epithet` al costruttore di `EventEngine`.

- [ ] **Step 4: Aggiorna currentCard per silent events**

In `currentCard()`, dopo aver determinato l'evento:

```php
// Le carte silenziose non hanno scelte — il frontend le avanza da solo.
if ($event && ($event->is_silent ?? false)) {
    return [
        'event'    => $event,
        'choices'  => [],
        'silent'   => true,
    ];
}
```

- [ ] **Step 5: Esegui la suite completa**

```bash
cd backend && php artisan test
```

Output atteso: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Game/Engine/EventEngine.php
git commit -m "feat: corrupted hints, silent cards, epithet sync in EventEngine"
```

---

### Task 9: RunController — esponi choice_log, crew_trust, epiteto

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php`

- [ ] **Step 1: Aggiorna il metodo `present()`**

Aggiungi questi campi al payload:

```php
// Dopo 'systems':
'choice_log' => array_slice($run->choice_log ?? [], -15),
'crew_trust' => (int) ($run->flags['crew_trust'] ?? 60),
'epithet'    => $run->profile?->flags['epithet'] ?? null,
```

- [ ] **Step 2: Aggiorna anche `endingPayload` per includere epiteto**

```php
return [
    'key'     => $ending['key'],
    'type'    => $ending['type'],
    'name'    => $ending['name'],
    'text'    => $ending['text'],
    'epithet' => $run->profile?->flags['epithet'] ?? null,
];
```

- [ ] **Step 3: Esegui i test feature**

```bash
cd backend && php artisan test tests/Feature/
```

Output atteso: PASS

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/RunController.php
git commit -m "feat: expose choice_log, crew_trust, epithet in API response"
```

---

### Task 10: Finali stratificati — game.php endings

**Files:**
- Modify: `backend/config/game.php`

I finali già usano `ConditionEvaluator`. Aggiungere finali che combinano epiteto (via profile flag) + sopravvissuti + sistemi.

- [ ] **Step 1: Trova la sezione `endings` in game.php e aggiungi i nuovi finali**

```php
// Dopo i finali esistenti, aggiungere (prima del finale catch-all se esiste):

// Finale: ammutinamento (crew_trust azzerato prima del game-over normale)
['key' => 'mutiny_end', 'type' => 'lose', 'name' => 'AMMUTINAMENTO',
 'text' => "L'equipaggio ha preso il controllo. Tu sei rimasto a guardare dai corridoi vuoti.",
 'when' => ['flag' => 'mutiny_occurred', 'is' => true]],

// Finale: sopravvissuto solitario
['key' => 'lone_survivor', 'type' => 'win', 'name' => 'ULTIMO IN PIEDI',
 'text' => "Hai salvato la stazione. Non hai salvato nessuno.",
 'when' => ['all' => [
     ['resource' => 'oxygen', 'op' => '>', 'value' => 0],
     ['day' => ['op' => '>', 'value' => 25]],
 ]]],

// Finale: vittoria con equipaggio intero
['key' => 'crew_intact', 'type' => 'win', 'name' => 'NESSUNO RIMASTO INDIETRO',
 'text' => "Ogni membro dell'equipaggio è vivo. Ogni sistema funziona. Avete vinto insieme.",
 'when' => ['all' => [
     ['resource' => 'oxygen', 'op' => '>', 'value' => 0],
     ['day' => ['op' => '>', 'value' => 25]],
     ['has_role' => 'engineer'],
     ['has_role' => 'doctor'],
 ]]],

// Finale: tradimento (il_freddo + sopravvissuto)
['key' => 'cold_victory', 'type' => 'win', 'name' => 'FREDDA SOPRAVVIVENZA',
 'text' => "Hai fatto le scelte difficili. Le facce di chi non ce l'ha fatta ti seguiranno.",
 'when' => ['all' => [
     ['resource' => 'oxygen', 'op' => '>', 'value' => 0],
     ['flag' => 'epithet', 'scope' => 'profile', 'is' => 'il_freddo'],
 ]]],
```

- [ ] **Step 2: Testa i finali via artisan tinker**

```bash
cd backend && php artisan tinker --execute="
\$run = \App\Models\Run::latest()->first();
echo json_encode(app(\App\Game\Engine\EndingService::class)->check(\$run));
"
```

Output atteso: `null` (run attiva, nessun finale ancora)

- [ ] **Step 3: Commit**

```bash
git add backend/config/game.php
git commit -m "feat: add layered endings (mutiny, lone survivor, crew intact, cold victory)"
```

---

## FASE 2 — FRONTEND REDESIGN

### Task 11: api.ts — nuovi tipi

**Files:**
- Modify: `frontend/src/api.ts`

- [ ] **Step 1: Aggiorna i tipi**

```typescript
export type ChoiceLogEntry = {
  day: number;
  event_key: string;
  choice_index: number;
  choice_label: string;
  tags: string[];
};

// Aggiorna Choice:
export type Choice = {
  index: number;
  label: string;
  hint: string | null;
  available: boolean;
  requires_item: string | null;   // ← nuovo
};

// Aggiorna RunState:
export type RunState = {
  id: number;
  day: number;
  status: "active" | "ended";
  seed: number;
  resources: Record<string, number>;
  resource_meta: Record<string, ResourceMeta>;
  characters: Character[];
  items: Item[];
  systems: Record<string, { efficiency: number }>;
  ending: Ending;
  card: Card | null;
  choice_log: ChoiceLogEntry[];   // ← nuovo
  crew_trust: number;              // ← nuovo
  epithet: string | null;          // ← nuovo
};

// Aggiorna Ending:
export type Ending = {
  key: string;
  type: "win" | "lose";
  name: string;
  text: string;
  epithet: string | null;          // ← nuovo
} | null;
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/api.ts
git commit -m "feat: update API types for choice_log, crew_trust, epithet, requires_item"
```

---

### Task 12: index.css — palette cosmica completa

**Files:**
- Modify: `frontend/src/index.css`

- [ ] **Step 1: Sostituisci completamente index.css**

```css
@import "tailwindcss";

/* ============================================================
   STARFALL STATION — Notte Cosmica
   Dark navy · accenti ciano + arancio · tipografia leggibile
   ============================================================ */

@theme {
  --color-bg:           #060b18;
  --color-surface:      #0d1b38;
  --color-surface-hi:   #122040;
  --color-surface-card: #0f1e3a;
  --color-border:       #1e3a5f;
  --color-border-hi:    #2d5a8e;

  --color-cyan:         #00d4ff;
  --color-cyan-dim:     #0088aa;
  --color-cyan-glow:    rgba(0,212,255,0.18);

  --color-orange:       #ff8c42;
  --color-orange-dim:   #a05520;

  --color-red:          #ff4757;
  --color-red-dim:      #8b1a24;
  --color-red-glow:     rgba(255,71,87,0.22);

  --color-gold:         #ffd166;
  --color-gold-dim:     #997a30;

  --color-text:         #e8f4fd;
  --color-text-dim:     #5a7a9a;
  --color-text-muted:   #344d6a;

  --font-ui:   Inter, "Segoe UI", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", "Fira Code", ui-monospace, monospace;
}

:root { color-scheme: dark; }

html, body, #root {
  height: 100%;
  overflow: hidden;
}

body {
  margin: 0;
  background: var(--color-bg);
  color: var(--color-text);
  font-family: var(--font-ui);
  font-size: 15px;
  line-height: 1.5;
}

/* ---- Layout shell ---- */
.game-shell {
  display: grid;
  grid-template-rows: 48px 1fr 52px;
  height: 100%;
  gap: 0;
}

/* ---- Card ---- */
.card-shell {
  background: var(--color-surface-card);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  will-change: transform;
  touch-action: none;
  box-shadow: 0 8px 32px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.04);
  transition: box-shadow 200ms ease;
}

.card-enter {
  animation: card-in 260ms cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes card-in {
  from { transform: translateY(20px) scale(0.97); opacity: 0; }
  to   { transform: none; opacity: 1; }
}

/* Zona art della carta: gradiente procedurale per tipo */
.card-art {
  height: 80px;
  border-radius: 14px 14px 0 0;
  position: relative;
  overflow: hidden;
}
.card-art-crisis       { background: linear-gradient(135deg, #1a0510 0%, #3d0c1a 60%, #8b1a24 100%); }
.card-art-exploration  { background: linear-gradient(135deg, #040d20 0%, #0a1f40 60%, #0d3060 100%); }
.card-art-character    { background: linear-gradient(135deg, #0a1020 0%, #1a2540 60%, #2d3d5a 100%); }
.card-art-system       { background: linear-gradient(135deg, #0d1a0d 0%, #1a3020 60%, #2d5030 100%); }
.card-art-silent       { background: linear-gradient(135deg, #0a0a1a 0%, #150d25 60%, #1f1040 100%); }
.card-art-moral        { background: linear-gradient(135deg, #1a1005 0%, #352010 60%, #5a3518 100%); }

.card-art-stars {
  position: absolute;
  inset: 0;
  background-image:
    radial-gradient(1px 1px at 20% 30%, rgba(255,255,255,0.6) 0%, transparent 100%),
    radial-gradient(1px 1px at 80% 20%, rgba(255,255,255,0.5) 0%, transparent 100%),
    radial-gradient(1px 1px at 50% 70%, rgba(255,255,255,0.4) 0%, transparent 100%),
    radial-gradient(1px 1px at 30% 80%, rgba(255,255,255,0.3) 0%, transparent 100%),
    radial-gradient(2px 2px at 70% 50%, rgba(0,212,255,0.4) 0%, transparent 100%);
}

/* Swipe tell */
.tell-left  { box-shadow: -20px 0 40px -8px var(--color-red-glow), 0 8px 32px rgba(0,0,0,0.5); }
.tell-right { box-shadow:  20px 0 40px -8px var(--color-cyan-glow), 0 8px 32px rgba(0,0,0,0.5); }

/* ---- Scelte ---- */
.choice-btn {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 12px 16px;
  text-align: left;
  font-family: var(--font-ui);
  font-size: 14px;
  color: var(--color-text);
  cursor: pointer;
  transition: background 180ms ease, border-color 180ms ease, transform 100ms ease;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.choice-btn:hover:not(:disabled) {
  background: var(--color-surface-hi);
  border-color: var(--color-border-hi);
  transform: translateY(-1px);
}
.choice-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}
.choice-btn.item-gated {
  border-color: var(--color-gold-dim);
}
.choice-btn.item-gated:hover:not(:disabled) {
  border-color: var(--color-gold);
}

/* ---- Barre risorse ---- */
.bar-track {
  background: rgba(255,255,255,0.06);
  border-radius: 4px;
  overflow: hidden;
}
.bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 500ms cubic-bezier(0.22,1,0.36,1);
  background: linear-gradient(90deg, var(--color-cyan-dim), var(--color-cyan));
  box-shadow: 0 0 8px var(--color-cyan-glow);
}
.bar-fill.warning {
  background: linear-gradient(90deg, var(--color-orange-dim), var(--color-orange));
  box-shadow: 0 0 8px rgba(255,140,66,0.4);
}
.bar-fill.critical {
  background: linear-gradient(90deg, var(--color-red-dim), var(--color-red));
  box-shadow: 0 0 12px var(--color-red-glow);
  animation: pulse-red 0.9s ease-in-out infinite;
}
@keyframes pulse-red {
  0%,100% { opacity: 1; }
  50%      { opacity: 0.5; }
}

/* ---- Sistemi ---- */
.system-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  display: inline-block;
}
.system-dot.ok       { background: var(--color-cyan); box-shadow: 0 0 6px var(--color-cyan); }
.system-dot.warning  { background: var(--color-orange); }
.system-dot.critical { background: var(--color-red); animation: pulse-red 1s infinite; }

/* ---- Inventory ---- */
.item-pill {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 4px 10px;
  font-size: 12px;
  color: var(--color-text-dim);
  white-space: nowrap;
  transition: border-color 180ms ease, color 180ms ease;
}
.item-pill.relevant {
  border-color: var(--color-gold-dim);
  color: var(--color-gold);
}

/* ---- Crew avatar ---- */
.crew-avatar {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700;
  flex-shrink: 0;
}
.crew-avatar.engineer { background: #0d2a3a; color: var(--color-cyan); border: 1px solid var(--color-cyan-dim); }
.crew-avatar.doctor   { background: #2a0d1a; color: #ff9eb5; border: 1px solid #8b3a50; }
.crew-avatar.pilot    { background: #1a1a0d; color: var(--color-gold); border: 1px solid var(--color-gold-dim); }
.crew-avatar.survivor { background: #1a1a1a; color: var(--color-text-dim); border: 1px solid var(--color-border); }
.crew-avatar.dead     { background: #0d0d0d; color: #333; border: 1px solid #222; filter: grayscale(1); }

/* ---- Animazioni globali ---- */
@keyframes fade-in-up {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: none; }
}
.fade-in-up { animation: fade-in-up 300ms ease; }

@keyframes flash-good {
  0%   { background: rgba(0,212,255,0.15); }
  100% { background: transparent; }
}
@keyframes flash-bad {
  0%   { background: rgba(255,71,87,0.15); }
  100% { background: transparent; }
}
.flash-good { animation: flash-good 600ms ease; }
.flash-bad  { animation: flash-bad 600ms ease; }

@keyframes glitch {
  0%,100% { clip-path: inset(0 0 0 0); transform: translate(0); }
  20%     { clip-path: inset(0 0 60% 0); transform: translate(-2px, 1px); }
  40%     { clip-path: inset(40% 0 0 0); transform: translate(2px, -1px); }
}
.glitch { animation: glitch 1.4s infinite steps(2); }

@keyframes jolt {
  0%   { transform: translate(0,0); }
  20%  { transform: translate(-5px, 3px); }
  40%  { transform: translate(4px,-3px); }
  60%  { transform: translate(-3px,2px); }
  100% { transform: translate(0,0); }
}
.jolt { animation: jolt 400ms ease-in-out; }

/* ---- Trust bar ---- */
.trust-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 500ms ease;
  background: linear-gradient(90deg, #2d1a40, #7a3fa0);
}
.trust-bar-fill.low {
  background: linear-gradient(90deg, var(--color-red-dim), var(--color-red));
  animation: pulse-red 1.2s infinite;
}

/* ---- Silent card overlay ---- */
.silent-progress {
  height: 2px;
  background: var(--color-cyan);
  border-radius: 1px;
  transition: width 4s linear;
}

button { font-family: var(--font-ui); cursor: pointer; }
```

- [ ] **Step 2: Rimuovi riferimenti CSS obsoleti**

Cerca nel codebase `text-phosphor`, `bg-phosphor`, `border-phosphor`, `text-amber`, `text-alarm` e annotali — verranno sostituiti nei task successivi.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/index.css
git commit -m "feat: complete CSS redesign - cosmic night palette"
```

---

### Task 13: GameScreen — nuovo layout dashboard

**Files:**
- Modify: `frontend/src/components/GameScreen.tsx`
- Create: `frontend/src/components/SystemsBar.tsx`

- [ ] **Step 1: Crea SystemsBar.tsx**

```tsx
type SystemsBarProps = {
  systems: Record<string, { efficiency: number }>;
  crewTrust: number;
};

const SYSTEM_LABELS: Record<string, string> = {
  life_support:    "Vita",
  power_grid:      "Rete",
  hull_integrity:  "Scafo",
};

function dotClass(eff: number): string {
  if (eff >= 60) return "ok";
  if (eff >= 35) return "warning";
  return "critical";
}

export function SystemsBar({ systems, crewTrust }: SystemsBarProps) {
  const trustLow = crewTrust < 30;

  return (
    <div className="flex items-center gap-4 px-4 py-2 text-xs"
         style={{ color: "var(--color-text-dim)", fontFamily: "var(--font-mono)" }}>
      {Object.entries(systems).map(([code, s]) => (
        <div key={code} className="flex items-center gap-1.5">
          <span className={`system-dot ${dotClass(s.efficiency)}`} />
          <span>{SYSTEM_LABELS[code] ?? code}</span>
          <span style={{ color: "var(--color-text-muted)" }}>{s.efficiency}%</span>
        </div>
      ))}
      <div className="ml-auto flex items-center gap-2">
        <span>Fiducia</span>
        <div className="bar-track" style={{ width: 60, height: 6 }}>
          <div className={`trust-bar-fill ${crewTrust < 30 ? "low" : ""}`}
               style={{ width: `${crewTrust}%` }} />
        </div>
        {trustLow && <span style={{ color: "var(--color-red)", fontSize: 10 }}>⚠</span>}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Aggiorna GameScreen.tsx**

```tsx
import { useEffect, useState } from "react";
import type { RunState } from "../api";
import { CardView } from "./CardView";
import { CrewPanel } from "./CrewPanel";
import { Inventory } from "./Inventory";
import { ResourceBars } from "./ResourceBars";
import { SystemsBar } from "./SystemsBar";

type Props = {
  run: RunState;
  busy: boolean;
  lastLog: string | null;
  onChoose: (index: number) => void;
};

export function GameScreen({ run, busy, lastLog, onChoose }: Props) {
  const [flash, setFlash] = useState<{ text: string; good: boolean } | null>(null);

  useEffect(() => {
    if (!lastLog) return;
    const good = !lastLog.includes("perso") && !lastLog.includes("danneggi") && !lastLog.includes("croll");
    setFlash({ text: lastLog, good });
    const t = setTimeout(() => setFlash(null), 3000);
    return () => clearTimeout(t);
  }, [lastLog]);

  const relevantItems = run.card?.choices
    .flatMap(c => c.requires_item ? [c.requires_item] : []) ?? [];

  return (
    <div className="game-shell" style={{ background: "var(--color-bg)" }}>
      {/* Header */}
      <header style={{
        display: "flex", alignItems: "center", justifyContent: "space-between",
        padding: "0 20px", borderBottom: "1px solid var(--color-border)",
        background: "var(--color-surface)",
      }}>
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--color-text-dim)", letterSpacing: "0.15em" }}>
          STARFALL STATION
        </span>
        <span data-testid="day" style={{ fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--color-cyan)", fontWeight: 700 }}>
          GIORNO {run.day}
        </span>
      </header>

      {/* Body */}
      <div style={{ display: "grid", gridTemplateColumns: "160px 1fr 170px", gap: 16, padding: 16, minHeight: 0, overflow: "hidden" }}>
        {/* Risorse */}
        <aside style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          <ResourceBars resources={run.resources} meta={run.resource_meta} />
        </aside>

        {/* Centro: card + log */}
        <main style={{ display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", minHeight: 0, overflow: "hidden", gap: 12 }}>
          {run.card ? (
            <CardView card={run.card} busy={busy} onChoose={onChoose} relevantItems={relevantItems} />
          ) : (
            <div style={{ color: "var(--color-text-muted)" }}>…</div>
          )}

          {/* Flash log */}
          <div data-testid="log" style={{
            height: 20, fontSize: 13, fontStyle: "italic", textAlign: "center",
            color: flash?.good ? "var(--color-cyan)" : "var(--color-orange)",
            opacity: flash ? 1 : 0, transition: "opacity 400ms",
          }}>
            {flash?.text}
          </div>
        </main>

        {/* Equipaggio */}
        <aside>
          <CrewPanel characters={run.characters} epithet={run.epithet} />
        </aside>
      </div>

      {/* Footer: inventory + systems */}
      <footer style={{ borderTop: "1px solid var(--color-border)", background: "var(--color-surface)", display: "flex", flexDirection: "column" }}>
        <Inventory items={run.items} relevantItems={relevantItems} />
        <SystemsBar systems={run.systems} crewTrust={run.crew_trust} />
      </footer>
    </div>
  );
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/GameScreen.tsx frontend/src/components/SystemsBar.tsx
git commit -m "feat: new GameScreen layout with SystemsBar and crew trust"
```

---

### Task 14: CardView — zona art, scelte grandi, item highlight

**Files:**
- Modify: `frontend/src/components/CardView.tsx`

- [ ] **Step 1: Sostituisci CardView.tsx**

```tsx
import { useRef, useState } from "react";
import type { Card } from "../api";

type Props = {
  card: Card;
  busy: boolean;
  onChoose: (index: number) => void;
  relevantItems: string[];
};

function artClass(eventKey: string): string {
  if (eventKey.includes("crisis") || eventKey.includes("breach") || eventKey.includes("alarm") || eventKey.includes("trap") || eventKey.includes("mutiny")) return "card-art-crisis";
  if (eventKey.includes("explor") || eventKey.includes("discover") || eventKey.includes("signal")) return "card-art-exploration";
  if (eventKey.includes("char") || eventKey.includes("crew") || eventKey.includes("ayaka") || eventKey.includes("marco")) return "card-art-character";
  if (eventKey.includes("system") || eventKey.includes("power") || eventKey.includes("hull") || eventKey.includes("life")) return "card-art-system";
  if (eventKey.includes("silent") || eventKey.includes("quiet") || eventKey.includes("moment")) return "card-art-silent";
  if (eventKey.includes("moral") || eventKey.includes("dilemma") || eventKey.includes("choice")) return "card-art-moral";
  return "card-art-exploration";
}

const ROLE_COLORS: Record<string, string> = {
  engineer: "var(--color-cyan)",
  doctor:   "#ff9eb5",
  pilot:    "var(--color-gold)",
  default:  "var(--color-text-dim)",
};

export function CardView({ card, busy, onChoose, relevantItems }: Props) {
  const available = card.choices.filter(c => c.available);
  const binary = available.length === 2;

  const [drag, setDrag] = useState(0);
  const startX = useRef<number | null>(null);
  const COMMIT = 95;

  function onPointerDown(e: React.PointerEvent) {
    if (!binary || busy) return;
    startX.current = e.clientX;
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
  }
  function onPointerMove(e: React.PointerEvent) {
    if (startX.current === null) return;
    setDrag(e.clientX - startX.current);
  }
  function onPointerUp() {
    if (startX.current === null) return;
    const d = drag;
    startX.current = null;
    setDrag(0);
    if (d >= COMMIT && available[1]) onChoose(available[1].index);
    else if (d <= -COMMIT && available[0]) onChoose(available[0].index);
  }

  const tilt = Math.max(-10, Math.min(10, drag / 10));
  const tellSide = drag >= 50 ? "tell-right" : drag <= -50 ? "tell-left" : "";

  return (
    <div style={{ width: "100%", maxWidth: 420, display: "flex", flexDirection: "column", gap: 10 }}>
      <div
        key={card.key}
        data-testid="card"
        className={`card-shell card-enter ${tellSide}`}
        style={{ transform: `translateX(${drag}px) rotate(${tilt}deg)` }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
      >
        {/* Zona art */}
        <div className={`card-art ${artClass(card.key)}`}>
          <div className="card-art-stars" />
          {card.speaker && (
            <div style={{
              position: "absolute", bottom: 10, left: 14,
              background: "rgba(0,0,0,0.6)", borderRadius: 6, padding: "3px 10px",
              fontSize: 11, fontWeight: 700, letterSpacing: "0.1em",
              color: ROLE_COLORS["default"],
              backdropFilter: "blur(4px)",
            }}>
              {card.speaker.toUpperCase()}
            </div>
          )}
          {/* Swipe hint */}
          {binary && (
            <div style={{
              position: "absolute", bottom: 10, right: 14,
              display: "flex", gap: 8, fontSize: 10,
              color: "rgba(255,255,255,0.35)",
            }}>
              <span style={drag <= -50 ? { color: "var(--color-red)" } : {}}>
                ◄ {available[0]?.label}
              </span>
              <span style={drag >= 50 ? { color: "var(--color-cyan)" } : {}}>
                {available[1]?.label} ►
              </span>
            </div>
          )}
        </div>

        {/* Testo */}
        <div style={{ padding: "14px 18px 18px" }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: "var(--color-text)", lineHeight: 1.3 }}>
            {card.title}
          </h2>
          <p style={{ margin: "8px 0 0", fontSize: 14, lineHeight: 1.6, color: "rgba(232,244,253,0.82)" }}>
            {card.body}
          </p>
        </div>
      </div>

      {/* Scelte */}
      <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
        {card.choices.map(c => {
          const itemGated = c.requires_item !== null;
          return (
            <button
              key={c.index}
              data-testid={`choice-${c.index}`}
              disabled={!c.available || busy}
              onClick={() => onChoose(c.index)}
              className={`choice-btn ${itemGated ? "item-gated" : ""}`}
            >
              <span>{c.label}</span>
              <div style={{ display: "flex", alignItems: "center", gap: 8, flexShrink: 0 }}>
                {itemGated && (
                  <span style={{ fontSize: 10, color: "var(--color-gold)", fontFamily: "var(--font-mono)" }}>
                    ✦ {c.requires_item}
                  </span>
                )}
                {c.hint && (
                  <span style={{ fontSize: 11, fontStyle: "italic", color: "var(--color-text-dim)" }}>
                    {c.hint}
                  </span>
                )}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/CardView.tsx
git commit -m "feat: new CardView with art zone, item-gated choices, swipe tells"
```

---

### Task 15: ResourceBars, CrewPanel, Inventory redesign

**Files:**
- Modify: `frontend/src/components/ResourceBars.tsx`
- Modify: `frontend/src/components/CrewPanel.tsx`
- Modify: `frontend/src/components/Inventory.tsx`

- [ ] **Step 1: Nuova ResourceBars.tsx**

```tsx
import type { ResourceMeta } from "../api";

const LABELS: Record<string, string> = {
  oxygen: "Ossigeno",
  food:   "Cibo",
  power:  "Energia",
  morale: "Morale",
  hull:   "Scafo",
};

const ICONS: Record<string, string> = {
  oxygen: "○", food: "◇", power: "◈", morale: "♡", hull: "△",
};

type Props = {
  resources: Record<string, number>;
  meta: Record<string, ResourceMeta>;
};

export function ResourceBars({ resources, meta }: Props) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ fontSize: 10, letterSpacing: "0.12em", color: "var(--color-text-muted)", marginBottom: 2, fontFamily: "var(--font-mono)" }}>
        RISORSE
      </div>
      {Object.entries(resources).map(([code, value]) => {
        const max = meta[code]?.max ?? 100;
        const pct = Math.max(0, Math.min(100, (value / max) * 100));
        const fillClass = pct <= 20 ? "critical" : pct <= 40 ? "warning" : "";

        return (
          <div key={code} data-testid={`bar-${code}`}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 4 }}>
              <span style={{ fontSize: 11, color: "var(--color-text-dim)", display: "flex", alignItems: "center", gap: 5 }}>
                <span style={{ fontSize: 10 }}>{ICONS[code] ?? "·"}</span>
                {LABELS[code] ?? code}
              </span>
              <span style={{
                fontSize: 11, fontFamily: "var(--font-mono)", fontWeight: 700,
                color: pct <= 20 ? "var(--color-red)" : pct <= 40 ? "var(--color-orange)" : "var(--color-text)",
              }}>
                {value}
              </span>
            </div>
            <div className="bar-track" style={{ height: 6 }}>
              <div className={`bar-fill ${fillClass}`} style={{ width: `${pct}%` }} />
            </div>
          </div>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 2: Nuova CrewPanel.tsx**

```tsx
import type { Character } from "../api";

const ROLE_LABELS: Record<string, string> = {
  engineer: "Ingegnere", doctor: "Medico", pilot: "Pilota", survivor: "Superstite",
};

const EPITHET_LABELS: Record<string, string> = {
  il_generoso: "il Generoso",
  il_freddo:   "il Freddo",
  l_imprudente: "l'Imprudente",
  il_prudente: "il Prudente",
  il_solitario: "il Solitario",
};

type Props = { characters: Character[]; epithet: string | null };

export function CrewPanel({ characters, epithet }: Props) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      <div style={{ fontSize: 10, letterSpacing: "0.12em", color: "var(--color-text-muted)", fontFamily: "var(--font-mono)" }}>
        EQUIPAGGIO
      </div>

      {characters.map(c => {
        const roleKey = c.role ?? "survivor";
        const initials = c.name.split(" ").map(w => w[0]).join("").slice(0, 2).toUpperCase();
        const stressPct = c.stress;
        const stressColor = stressPct >= 85 ? "var(--color-red)" : stressPct >= 60 ? "var(--color-orange)" : "var(--color-text-dim)";

        return (
          <div key={c.name} data-testid={`crew-${c.name}`}
               style={{ display: "flex", gap: 10, alignItems: "flex-start", opacity: c.alive ? 1 : 0.4 }}>
            <div className={`crew-avatar ${c.alive ? roleKey : "dead"}`}>{initials}</div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline" }}>
                <span style={{ fontSize: 13, fontWeight: 600, textDecoration: c.alive ? "none" : "line-through" }}>
                  {c.name}
                </span>
                <span style={{ fontSize: 10, color: "var(--color-text-muted)" }}>
                  {ROLE_LABELS[roleKey] ?? roleKey}
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
                      background: stressPct >= 85 ? "var(--color-red)" : stressPct >= 60 ? "var(--color-orange)" : "var(--color-cyan-dim)",
                      transition: "width 500ms ease",
                    }} />
                  </div>
                </div>
              ) : (
                <div style={{ fontSize: 10, color: "var(--color-red)", marginTop: 2 }}>— perso —</div>
              )}
            </div>
          </div>
        );
      })}

      {epithet && (
        <div style={{ marginTop: 4, padding: "6px 10px", background: "rgba(255,209,102,0.06)", border: "1px solid var(--color-gold-dim)", borderRadius: 8, fontSize: 11, color: "var(--color-gold)", fontStyle: "italic" }}>
          Comandante {EPITHET_LABELS[epithet] ?? epithet}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Nuova Inventory.tsx**

```tsx
import type { Item } from "../api";

type Props = { items: Item[]; relevantItems: string[] };

export function Inventory({ items, relevantItems }: Props) {
  if (items.length === 0) return null;

  return (
    <div style={{ display: "flex", gap: 8, padding: "6px 16px", flexWrap: "nowrap", overflowX: "auto", alignItems: "center" }}>
      <span style={{ fontSize: 10, letterSpacing: "0.12em", color: "var(--color-text-muted)", flexShrink: 0, fontFamily: "var(--font-mono)" }}>
        ZAINO
      </span>
      {items.map(item => {
        const relevant = relevantItems.includes(item.key);
        return (
          <div key={item.key} className={`item-pill ${relevant ? "relevant" : ""}`} title={item.description}>
            {relevant && <span style={{ marginRight: 4 }}>✦</span>}
            {item.name}
          </div>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/ResourceBars.tsx frontend/src/components/CrewPanel.tsx frontend/src/components/Inventory.tsx
git commit -m "feat: redesign ResourceBars, CrewPanel (avatars, epithet), Inventory (item highlight)"
```

---

### Task 16: SilentCard + StartScreen + GameOverScreen

**Files:**
- Create: `frontend/src/components/SilentCard.tsx`
- Modify: `frontend/src/components/GameScreen.tsx`
- Modify: `frontend/src/components/StartScreen.tsx`
- Modify: `frontend/src/components/GameOverScreen.tsx`

- [ ] **Step 1: Crea SilentCard.tsx**

```tsx
import { useEffect, useState } from "react";
import type { Card } from "../api";

type Props = { card: Card; onAdvance: () => void };

export function SilentCard({ card, onAdvance }: Props) {
  const [progress, setProgress] = useState(0);

  useEffect(() => {
    const start = performance.now();
    const duration = 4000;
    let frame: number;

    const tick = (now: number) => {
      const elapsed = now - start;
      setProgress(Math.min(100, (elapsed / duration) * 100));
      if (elapsed < duration) {
        frame = requestAnimationFrame(tick);
      } else {
        onAdvance();
      }
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [onAdvance]);

  return (
    <div className="card-shell card-enter fade-in-up" style={{ maxWidth: 420, width: "100%" }}>
      <div className="card-art card-art-silent">
        <div className="card-art-stars" />
      </div>
      <div style={{ padding: "14px 18px 18px" }}>
        <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: "var(--color-text)", lineHeight: 1.3 }}>
          {card.title}
        </h2>
        <p style={{ margin: "8px 0 16px", fontSize: 14, lineHeight: 1.6, color: "rgba(232,244,253,0.7)", fontStyle: "italic" }}>
          {card.body}
        </p>
        <div className="bar-track" style={{ height: 2 }}>
          <div className="silent-progress" style={{ width: `${progress}%` }} />
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Usa SilentCard in GameScreen**

In `GameScreen.tsx`, nel blocco dove viene renderizzata la carta:

```tsx
import { SilentCard } from "./SilentCard";

// Nel JSX, sostituisci:
{run.card ? (
  <CardView card={run.card} busy={busy} onChoose={onChoose} relevantItems={relevantItems} />
) : ...}

// Con:
{run.card ? (
  run.card.choices.length === 0
    ? <SilentCard card={run.card} onAdvance={() => onChoose(-1)} />
    : <CardView card={run.card} busy={busy} onChoose={onChoose} relevantItems={relevantItems} />
) : (
  <div style={{ color: "var(--color-text-muted)" }}>…</div>
)}
```

Nota: il backend deve gestire `choice -1` come "avanza la carta silenziosa". Aggiungere in `RunController.resolveChoice`: se `$data['choice'] === -1` e l'evento è `is_silent`, semplicemente avanzare il giorno senza applicare effetti. (Oppure usare l'endpoint `/advance` già esistente.)

Modifica più semplice: la SilentCard chiama `onAdvance()` che usa `fetch('/api/runs/{id}/advance', {method:'POST'})` direttamente.

Aggiorna `useRun.ts`:

```typescript
const advance = useCallback(async () => {
  if (!run || busy) return;
  setBusy(true);
  try {
    const res = await fetch(`${BASE}/runs/${run.id}/advance`, { method: "POST", headers });
    const state = await json<RunState>(res);
    setRun(state);
  } catch (e) {
    setError(e instanceof Error ? e.message : "Errore");
  } finally {
    setBusy(false);
  }
}, [run, busy]);

return { run, phase, busy, error, begin, choose, advance, reset };
```

Aggiorna `App.tsx` per passare `advance` a `GameScreen`, che lo passa a `SilentCard`.

- [ ] **Step 3: StartScreen cosmico**

Sostituisci la struttura di `StartScreen.tsx` con:

```tsx
// Header: STARFALL STATION in stile cosmico
<div style={{ textAlign: "center" }}>
  <div style={{ fontSize: 11, letterSpacing: "0.3em", color: "var(--color-text-muted)", fontFamily: "var(--font-mono)", marginBottom: 8 }}>
    // PROTOCOLLO DI SOPRAVVIVENZA
  </div>
  <h1 style={{ margin: 0, fontSize: 28, fontWeight: 800, letterSpacing: "0.15em", color: "var(--color-cyan)" }}>
    STARFALL STATION
  </h1>
</div>

// Paragrafo
<p style={{ textAlign: "center", fontSize: 14, color: "var(--color-text-dim)", maxWidth: 380 }}>
  La stazione è compromessa. Scegli <span style={{ color: "var(--color-text)", fontWeight: 700 }}>{pick}</span> dotazioni prima del distacco.
</p>

// Grid items: bordi colored, font leggibile
// Bottone DISTACCO: background cyan al hover
```

- [ ] **Step 4: GameOverScreen stratificata**

```tsx
import type { Ending } from "../api";

const EPITHET_LABELS: Record<string, string> = {
  il_generoso: "il Generoso", il_freddo: "il Freddo",
  l_imprudente: "l'Imprudente", il_prudente: "il Prudente",
};

type Props = { ending: Ending; day: number; onRestart: () => void };

export function GameOverScreen({ ending, day, onRestart }: Props) {
  const win = ending?.type === "win";

  return (
    <div className="jolt" style={{
      height: "100%", display: "flex", flexDirection: "column",
      alignItems: "center", justifyContent: "center",
      padding: 40, textAlign: "center", gap: 20,
      background: win ? "radial-gradient(ellipse at center, #040d18 0%, var(--color-bg) 70%)"
                      : "radial-gradient(ellipse at center, #180404 0%, var(--color-bg) 70%)",
    }}>
      <div style={{ fontSize: 11, letterSpacing: "0.25em", fontFamily: "var(--font-mono)",
                    color: win ? "var(--color-cyan)" : "var(--color-red)" }}>
        {win ? "// SEGNALE TRASMESSO" : "// SEGNALE PERSO"}
      </div>

      <h1 className="glitch" data-testid="ending-name" style={{
        fontSize: 36, fontWeight: 900, letterSpacing: "0.1em", margin: 0,
        color: win ? "var(--color-cyan)" : "var(--color-red)",
      }}>
        {ending?.name ?? "FINE"}
      </h1>

      <p style={{ maxWidth: 420, fontSize: 15, lineHeight: 1.7, color: "rgba(232,244,253,0.8)" }}>
        {ending?.text}
      </p>

      <div style={{ display: "flex", flexDirection: "column", gap: 4, alignItems: "center" }}>
        <div style={{ fontSize: 12, color: "var(--color-text-muted)", fontFamily: "var(--font-mono)" }}>
          Giorno {day}
        </div>
        {ending?.epithet && (
          <div style={{ fontSize: 13, color: "var(--color-gold)", fontStyle: "italic" }}>
            Comandante {EPITHET_LABELS[ending.epithet] ?? ending.epithet}
          </div>
        )}
      </div>

      <button data-testid="restart" onClick={onRestart}
              style={{
                marginTop: 8, padding: "10px 32px", fontSize: 14, letterSpacing: "0.1em",
                background: "transparent", border: `1px solid ${win ? "var(--color-cyan)" : "var(--color-red)"}`,
                color: win ? "var(--color-cyan)" : "var(--color-red)", borderRadius: 10, cursor: "pointer",
                fontFamily: "var(--font-ui)", fontWeight: 600,
                transition: "background 200ms, color 200ms",
              }}
              onMouseEnter={e => { (e.target as HTMLElement).style.background = win ? "var(--color-cyan)" : "var(--color-red)"; (e.target as HTMLElement).style.color = "var(--color-bg)"; }}
              onMouseLeave={e => { (e.target as HTMLElement).style.background = "transparent"; (e.target as HTMLElement).style.color = win ? "var(--color-cyan)" : "var(--color-red)"; }}>
        ANCORA
      </button>
    </div>
  );
}
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/SilentCard.tsx frontend/src/components/GameScreen.tsx frontend/src/components/StartScreen.tsx frontend/src/components/GameOverScreen.tsx frontend/src/useRun.ts frontend/src/App.tsx
git commit -m "feat: SilentCard auto-advance, StartScreen cosmico, GameOverScreen stratificata"
```

---

## FASE 3 — CONTENUTO NARRATIVO

### Task 17: Domino chains + Trap events nel ContentEventSeeder

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php`

Aggiungere metodo `dominoEvents()` e `trapEvents()` e includerli in `events()`.

- [ ] **Step 1: Aggiungi `dominoEvents()` al seeder**

```php
private function dominoEvents(): array
{
    return [
        // CATENA 1: perdita carburante ignorata → crisi propulsori
        $this->ev([
            'key' => 'fuel_leak_warning', 'title' => 'Perdita di carburante',
            'body' => "Un sensore segnala una piccola perdita nel serbatoio principale. Niente di urgente, per ora.",
            'base_weight' => 8, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Ripara subito', [['resource' => 'power', 'delta' => -12]], 'La perdita è sigillata. Costi energetici elevati.', null, null),
                array_merge($this->one('Monitora e basta', [['spawn_event' => ['key' => 'fuel_crisis', 'in_days' => 6]]], 'Segnato nel registro. Probabilmente si stabilizzerà.'),
                    ['tags' => ['ignored_warning']]),
            ],
        ]),

        $this->ev([
            'key' => 'fuel_crisis', 'title' => 'CRISI PROPULSORI',
            'body' => "La piccola perdita che hai ignorato giorni fa non si è stabilizzata. Ora il sistema propulsivo sta cedendo. Non c'è via d'uscita pulita.",
            'requires' => ['chosen_tag' => 'ignored_warning'],
            'base_weight' => 0, 'cooldown_days' => 999, 'is_filler' => false,
            'choices' => [
                $this->one('Sacrifica energia per stabilizzare', [['resource' => 'power', 'delta' => -30], ['damage_system' => 'power_grid', 'amount' => 20]], 'I propulsori reggono. Energia quasi esaurita.'),
                $this->one('Abbandona il settore propulsivo', [['damage_system' => 'hull_integrity', 'amount' => 25], ['resource' => 'hull', 'delta' => -20]], 'Settore abbandonato. Lo scafo ha subito danni strutturali.'),
            ],
        ]),

        // CATENA 2: medico esausto ignorato → paziente muore
        $this->ev([
            'key' => 'doctor_exhausted', 'title' => 'Il medico è a pezzi',
            'body' => "Marco non dorme da tre giorni. Ti chiede un turno di riposo. Puoi permettertelo?",
            'requires' => ['has_role' => 'doctor'],
            'base_weight' => 7, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Concedigli il riposo', [['character' => 'Marco', 'stress' => -25]], 'Marco si riposa. Ci vorrà un giorno.', null, null),
                array_merge($this->one('Non possiamo fermarci ora', [['character' => 'Marco', 'stress' => 20], ['spawn_event' => ['key' => 'patient_lost', 'in_days' => 4]]], 'Marco annuisce e torna al lavoro, silenzioso.'),
                    ['tags' => ['sacrifice_crew']]),
            ],
        ]),

        $this->ev([
            'key' => 'patient_lost', 'title' => 'Troppo tardi',
            'body' => "Il paziente che Marco stava seguendo non ce l'ha fatta. Marco ti guarda. Non dice niente. Non deve.",
            'base_weight' => 0, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Prendi la responsabilità', [['resource' => 'morale', 'delta' => -8], ['modify_trust' => 10]], 'L\'equipaggio apprezza la tua onestà. Il peso rimane.'),
                $this->one('Era inevitabile', [['resource' => 'morale', 'delta' => -20], ['modify_trust' => -15]], 'Marco si allontana. Qualcosa si è rotto.', null, null),
            ],
        ]),

        // CATENA 3: razioni tagliate → ammutinamento nascosto
        $this->ev([
            'key' => 'ration_cut_decision', 'title' => 'Le razioni non bastano',
            'body' => "Il cibo sta finendo più in fretta del previsto. Devi decidere come gestire la distribuzione.",
            'base_weight' => 9, 'cooldown_days' => 999,
            'requires' => ['resource' => 'food', 'op' => '<', 'value' => 50],
            'choices' => [
                $this->one('Taglio uguale per tutti', [['resource' => 'food', 'delta' => 0], ['resource' => 'morale', 'delta' => -5]], 'Nessuno è contento. Almeno nessuno è trattato diversamente.', null, null),
                array_merge($this->one('Priorità a chi lavora di più', [['resource' => 'morale', 'delta' => -15], ['modify_trust' => -20], ['spawn_event' => ['key' => 'ration_revolt', 'in_days' => 5]]], 'La decisione ha un senso logico. L\'equipaggio non è d\'accordo.'),
                    ['tags' => ['sacrifice_crew']]),
            ],
        ]),

        $this->ev([
            'key' => 'ration_revolt', 'title' => 'La rivolta delle razioni',
            'body' => "Quello che hai fatto con le razioni ha bollito sotto la superficie. Ora è esploso. Due membri dell'equipaggio si rifiutano di lavorare finché il sistema non cambia.",
            'base_weight' => 0, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Cedi e ridistribuisci', [['resource' => 'food', 'delta' => -10], ['modify_trust' => 15], ['resource' => 'morale', 'delta' => 10]], 'La tensione cala. Il cibo diminuisce.'),
                $this->one('Mantieni la linea', [['modify_trust' => -25], ['resource' => 'morale', 'delta' => -15], ['set_flag' => 'mutiny_occurred', 'value' => true]], 'Silenzio. Del tipo sbagliato.'),
            ],
        ]),

        // Evento ammutinamento (trigger da TrustEngine)
        $this->ev([
            'key' => 'mutiny_trigger', 'title' => 'AMMUTINAMENTO',
            'body' => "Hanno aspettato che dormissi. Quando ti svegli, i codici di accesso sono stati cambiati. L'equipaggio controlla la stazione. Tu no.",
            'base_weight' => 0, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Negozia', [['modify_trust' => 30], ['resource' => 'morale', 'delta' => -10], ['set_flag' => 'mutiny_occurred', 'value' => true]], 'Raggiungi un accordo doloroso. Non sei più solo tu a comandare.'),
                $this->one('Cedi il controllo', [['set_flag' => 'mutiny_occurred', 'value' => true], ['resource' => 'morale', 'delta' => 10]], 'Lasci andare. Forse è la cosa più saggia che hai fatto.'),
            ],
        ]),
    ];
}
```

- [ ] **Step 2: Aggiungi `trapEvents()` al seeder**

```php
private function trapEvents(): array
{
    return [
        // TRAP 1: ignorato 3+ warning
        $this->ev([
            'key' => 'trap_cascade_failure', 'title' => 'CASCATA DI GUASTI',
            'body' => "Hai ignorato troppi segnali. Ora sono tutti diventati reali, contemporaneamente. Non c'è una buona opzione.",
            'requires' => ['chosen_tag' => 'ignored_warning'],
            'base_weight' => 15, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Salva il sistema vita', [['damage_system' => 'power_grid', 'amount' => 40], ['damage_system' => 'hull_integrity', 'amount' => 20]], 'Il supporto vitale regge. Tutto il resto no.'),
                $this->one('Salva la propulsione', [['damage_system' => 'life_support', 'amount' => 35], ['resource' => 'oxygen', 'delta' => -20]], 'Potete muovervi. Ma l\'aria si sta rarefacendo.'),
            ],
        ]),

        // TRAP 2: morale al collasso
        $this->ev([
            'key' => 'trap_morale_collapse', 'title' => 'IL PUNTO DI ROTTURA',
            'body' => "L'equipaggio ha raggiunto il limite. Non è rabbia, è vuoto. Devi scegliere come usare le ultime riserve di fiducia che hai.",
            'requires' => ['resource' => 'morale', 'op' => '<', 'value' => 20],
            'base_weight' => 20, 'cooldown_days' => 999,
            'choices' => [
                $this->one('Consuma le ultime riserve di cibo per un pasto vero', [['resource' => 'food', 'delta' => -30], ['resource' => 'morale', 'delta' => 25]], 'Un pasto. Un momento di umanità. Costerà.'),
                $this->one('Discorso motivazionale — le parole costano poco', [['resource' => 'morale', 'delta' => 5], ['modify_trust' => -10]], 'Le parole cadono nel vuoto. Sanno che non credi nemmeno tu.'),
            ],
        ]),

        // TRAP 3: scafo critico + sacrificio
        $this->ev([
            'key' => 'trap_hull_critical', 'title' => 'LO SCAFO STA CEDENDO',
            'body' => "Una breccia nel settore 7. Puoi tappare il buco, ma qualcuno deve tenere in posizione il pannello dall'esterno, in tuta EVA, mentre lo scafo vibra.",
            'requires' => ['resource' => 'hull', 'op' => '<', 'value' => 25],
            'base_weight' => 18, 'cooldown_days' => 999,
            'choices' => [
                array_merge(
                    $this->one('Vai tu — tuta EVA nell\'inventario', [['resource' => 'hull', 'delta' => 30], ['character' => 'random', 'stress' => 15]], 'Esci. Fa freddo. La riparazione regge.'),
                    ['requires' => ['has_item' => 'eva_suit'], 'tags' => ['cautious']]
                ),
                $this->one('Manda qualcuno dell\'equipaggio', [['resource' => 'hull', 'delta' => 20], ['kill' => 'random']], 'Lo scafo regge. Qualcuno non torna.', null, null),
                $this->one('Sigilla il settore e abbandonalo', [['resource' => 'hull', 'delta' => -15], ['damage_system' => 'hull_integrity', 'amount' => 30]], 'Perdi il settore. Lo scafo perde stabilità strutturale.'),
            ],
        ]),
    ];
}
```

- [ ] **Step 3: Aggiungi metodi di supporto e `silentEvents()` + `moralEvents()`**

```php
private function silentEvents(): array
{
    return [
        $this->ev([
            'key' => 'silent_window', 'title' => 'Una finestra nello spazio',
            'body' => "Ayaka è ferma davanti al pannello di osservazione da venti minuti. Non si gira quando entri. Le stelle non rispondono, ma almeno non mentono.",
            'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 8,
            'choices' => [$this->one('Continua', [], '', null, null)],
            // Nota: il frontend riconosce is_silent dalla mancanza di choices reali
            // (o dal campo is_silent se aggiunto allo schema)
        ]),

        $this->ev([
            'key' => 'silent_engine_hum', 'title' => 'Il ronzio dei motori',
            'body' => "Di notte la stazione ha un suono diverso. Non sai se è rassicurante o inquietante. Hai smesso di interrogarti su queste cose.",
            'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 10,
            'choices' => [$this->one('Continua', [], '', null, null)],
        ]),
    ];
}

private function moralEvents(): array
{
    return [
        $this->ev([
            'key' => 'moral_last_dose', 'title' => 'L\'ultima dose',
            'body' => "Ci sono due feriti. Una dose di antidolorifico. Marco ti guarda. Non è una decisione medica — è una decisione umana.",
            'requires' => ['has_role' => 'doctor'],
            'base_weight' => 6, 'cooldown_days' => 999,
            'choices' => [
                array_merge($this->one('A chi ha più probabilità di sopravvivere', [['character' => 'random', 'stress' => 10]], 'Una scelta razionale. Difficile da guardare in faccia.'), ['tags' => ['il_freddo']]),
                array_merge($this->one('A chi soffre di più', [['resource' => 'morale', 'delta' => 8]], 'Non è efficiente. Ma è giusto.'), ['tags' => ['generous']]),
            ],
        ]),

        $this->ev([
            'key' => 'moral_log_falsification', 'title' => 'Il registro dei danni',
            'body' => "Il rapporto ufficiale sui danni allo scafo deve essere inviato. La verità è molto peggio di quello che puoi ammettere. Puoi falsificare i dati — nessuno lo scoprirà.",
            'base_weight' => 5, 'cooldown_days' => 999,
            'choices' => [
                array_merge($this->one('Invia i dati reali', [['resource' => 'morale', 'delta' => 5], ['modify_trust' => 10]], 'La verità è trasmessa. Qualcuno da qualche parte lo saprà.'), ['tags' => ['honest']]),
                array_merge($this->one('Minimizza i danni nel rapporto', [['set_flag' => 'log_falsified', 'value' => true]], 'Il messaggio parte. Nessuno fa domande. Per ora.'), ['tags' => ['lone_decision']]),
            ],
        ]),
    ];
}
```

- [ ] **Step 4: Aggiorna `events()` per includere tutti i nuovi metodi**

```php
private function events(): array
{
    return array_merge(
        $this->resourceEvents(),
        $this->systemEvents(),
        $this->characterEvents(),
        $this->relationshipEvents(),
        $this->itemEvents(),
        $this->memoryEvents(),
        $this->fillerEvents(),
        $this->dominoEvents(),   // ← nuovo
        $this->trapEvents(),     // ← nuovo
        $this->silentEvents(),   // ← nuovo
        $this->moralEvents(),    // ← nuovo
    );
}
```

- [ ] **Step 5: Esegui il seeder**

```bash
cd backend && php artisan db:seed --class=ContentEventSeeder
```

Output atteso: nessun errore (ogni evento viene validato)

- [ ] **Step 6: Esegui la test suite completa**

```bash
cd backend && php artisan test
```

Output atteso: tutti PASS

- [ ] **Step 7: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: add domino chains, trap events, silent cards, moral dilemmas to content"
```

---

### Task 18: Test end-to-end visivo

- [ ] **Step 1: Avvia il server**

```bash
cd backend && php artisan serve --port=8000 &
cd frontend && npm run dev &
```

- [ ] **Step 2: Apri http://localhost:5173 nel browser**

Verifica:
- Schermata iniziale: stile cosmico, sfondo scuro, accenti ciano
- Selezione item: bordi definiti, testo leggibile
- Schermata di gioco: tre colonne (risorse | carta | equipaggio), footer con inventario e sistemi
- Carta: zona art colorata, testo grande, scelte ben separate
- Barre risorse: colori che cambiano (ciano → arancio → rosso)
- Epiteto visibile nel pannello equipaggio dopo scelte ripetute

- [ ] **Step 3: Gioca una run completa**

Fai almeno 10 scelte, verifica:
- choice_log in `GET /api/runs/{id}` si popola
- crew_trust diminuisce con scelte di tipo `sacrifice_crew`
- Gli eventi domino appaiono dopo il numero di giorni corretto
- I trap events appaiono nelle condizioni giuste

- [ ] **Step 4: Commit finale**

```bash
git add -A
git commit -m "feat: complete Spacefall redesign - visual, mechanics, content"
```

---

## Note per l'esecutore

- **PHP artisan serve** va avviato dalla cartella `backend/`
- **npm run dev** va avviato dalla cartella `frontend/`
- Il database è SQLite: `backend/database/database.sqlite`
- Se i test Pest falliscono per factory mancante, verificare che `RunFactory` esista in `backend/database/factories/`
- Per i test che richiedono `withEvents()`, creare questo stato nel factory o usare `DatabaseSeeder` come `RefreshDatabase`
- `EventSchema::EFFECT_KEYS` e `CONDITION_KEYS` devono sempre essere aggiornate insieme agli effetti/condizioni implementati
