# Oggetti-strumento nelle crisi comuni — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere a 6 carte-crisi frequenti una scelta extra gated su un oggetto-strumento, che è una scorciatoia più economica della via base ma consuma l'attrezzo.

**Architecture:** Solo contenuto + un test-guardiano. Il motore è già pronto: `has_item` filtra le scelte (`ConditionEvaluator`/`EventEngine`), `consume_item` toglie l'oggetto (`EffectApplier`). Nessuna modifica all'engine. Le scelte si aggiungono ai seeder dove ogni carta già vive; un test garantisce che ogni scelta gated usi solo oggetti della griglia sbloccata e consumi il proprio attrezzo.

**Tech Stack:** PHP 8 / Laravel, Pest (test), seeder array-data. Spec: `docs/superpowers/specs/2026-06-11-tool-items-common-crises-design.md`.

---

## File Structure

- **Modify** `backend/database/seeders/EventSeeder.php` — 4 carte (raw-array style): `power_cascade`, `survivor_strained`, `survivor_breaks`, `ration_crisis`.
- **Modify** `backend/database/seeders/ContentEventSeeder.php` — 2 carte (`$this->one()` helper style): `ration_cut_decision`, `fuel_leak_warning`.
- **Create** `backend/tests/Feature/ToolItemChoiceTest.php` — guardiano: presenza scelta, consumo, solo-griglia-sbloccata, visibilità.

Ogni carta è un cambiamento self-contained. Si committa una carta alla volta.

## Convenzioni del codebase (leggere prima di iniziare)

**Forma scelta gated in `EventSeeder.php` (raw array)** — pattern esistente a `EventSeeder.php:65-70` (welder su power_flicker):
```php
[
    'label'         => 'Testo scelta',
    'hint'          => 'consuma <attrezzo>',
    'requires'      => ['has_item' => '<key>'],
    'requires_item' => '<key>',
    'outcomes' => [
        ['weight' => 1, 'effects' => [
            ['consume_item' => '<key>'],
            // effetti-risparmio
        ], 'log' => 'Esito riuscito.'],
    ],
],
```

**Forma scelta gated in `ContentEventSeeder.php`** — via helper `one()` (firma a `ContentEventSeeder.php:46`):
```php
$this->one(string $label, array $effects, string $log, ?string $hint = null, ?array $requires = null)
// → ['label','hint','outcomes'=>[['weight'=>1,'effects'=>$effects,'log'=>$log]]] (+ 'requires' se passato)
```
Helper NON imposta `requires_item`. Per aggiungerlo, `array_merge` sul risultato:
```php
array_merge(
    $this->one('Label', [['consume_item' => '<key>'], /* effetti */], 'Log',
        'consuma <attrezzo>', ['has_item' => '<key>']),
    ['requires_item' => '<key>']
),
```

**Effetti disponibili (visti nei seeder):** `['resource'=>'X','delta'=>N]` (X ∈ power/oxygen/hull/food/morale), `['character'=>'all'|'highest_stress'|nome,'stress'=>N]`, `['consume_item'=>'key']`, `['set_flag'=>'k','value'=>true]`, `['spawn_event'=>['key'=>...,'in_days'=>N]]`, `['modify_trust'=>N]`, `['damage_system'=>'k','amount'=>N]`.

**Oggetti griglia sbloccata** (`config/game.php:496-504`): drone, scanner, welder, medkit, rifle, seedbank, spacesuit, comms, rations. Solo questi sono ammessi nei `has_item` delle nuove scelte.

**Eseguire i test:** dalla dir `backend/`: `php artisan test --filter <NomeTest>`. Suite intera: `php artisan test`.

---

## Task 1: Test-guardiano (presenza + consumo + solo-griglia)

Scriviamo PRIMA il test, che fallirà finché le 6 scelte non esistono. È data-driven sul seeder, non simula il gioco.

**Files:**
- Test: `backend/tests/Feature/ToolItemChoiceTest.php`

- [ ] **Step 1: Scrivere il test guardiano**

Create `backend/tests/Feature/ToolItemChoiceTest.php`:
```php
<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Le 6 carte-crisi che ricevono una scelta gated su oggetto-strumento, con
 * l'attrezzo atteso. La scelta è la scorciatoia che consuma l'attrezzo.
 *
 * @return array<string, string> card key => expected item key
 */
function toolItemExpectations(): array
{
    return [
        'ration_crisis'       => 'rations',
        'power_cascade'       => 'scanner',
        'survivor_strained'   => 'medkit',
        'survivor_breaks'     => 'medkit',
        'ration_cut_decision' => 'rations',
        'fuel_leak_warning'   => 'spacesuit',
    ];
}

/** Carica tutte le carte seedate come mappa key => event row. */
function seededEvents(): array
{
    (new EventSeeder())->run();
    (new ContentEventSeeder())->run();

    return \App\Models\Event::all()->keyBy('key')->all();
}

/** Trova la scelta gated su $item dentro le choices di una carta. */
function gatedChoice(array $choices, string $item): ?array
{
    foreach ($choices as $c) {
        if (($c['requires']['has_item'] ?? null) === $item) {
            return $c;
        }
    }

    return null;
}

it('ogni carta-crisi ha una scelta gated sul proprio attrezzo', function () {
    $events = seededEvents();

    foreach (toolItemExpectations() as $cardKey => $itemKey) {
        expect($events)->toHaveKey($cardKey, "carta mancante: {$cardKey}");
        $choices = $events[$cardKey]->choices;
        $choice = gatedChoice($choices, $itemKey);
        expect($choice)->not->toBeNull("scelta gated su {$itemKey} mancante in {$cardKey}");
        expect($choice['requires_item'] ?? null)->toBe($itemKey,
            "requires_item incoerente in {$cardKey}");
    }
});

it('ogni scelta-strumento consuma il proprio attrezzo in tutti gli outcome', function () {
    $events = seededEvents();

    foreach (toolItemExpectations() as $cardKey => $itemKey) {
        $choice = gatedChoice($events[$cardKey]->choices, $itemKey);
        foreach ($choice['outcomes'] as $o) {
            $consumes = collect($o['effects'])
                ->contains(fn ($e) => ($e['consume_item'] ?? null) === $itemKey);
            expect($consumes)->toBeTrue("outcome senza consume_item {$itemKey} in {$cardKey}");
        }
    }
});

it('nessuna scelta-strumento gata su un oggetto fuori dalla griglia sbloccata', function () {
    $unlocked = collect(config('game.items'))
        ->reject(fn ($i) => $i['locked'] ?? false)
        ->pluck('key')
        ->all();

    foreach (toolItemExpectations() as $itemKey) {
        expect($unlocked)->toContain($itemKey,
            "attrezzo {$itemKey} non è nella griglia sbloccata");
    }
});
```

- [ ] **Step 2: Eseguire il test, verificare che fallisce**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: FAIL — la prima asserzione cade su `power_cascade` (scelta scanner mancante). `ration_crisis` potrebbe già fallire perché ha rifle, non rations.

- [ ] **Step 3: Commit del test rosso**

```bash
git add backend/tests/Feature/ToolItemChoiceTest.php
git commit -m "test: guardiano scelte oggetto-strumento nelle crisi (rosso)"
```

---

## Task 2: `power_cascade` + scanner

Via base peggiore: `power -12` (tieni l'aria, perdi il settore) oppure `oxygen -10` (sacrifichi aria). Scorciatoia: leggi quale linea cede, niente sacrificio.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (carta a `:79`, dentro l'array `choices`)

- [ ] **Step 1: Aggiungere la scelta gated**

In `EventSeeder.php`, nella carta `power_cascade` (`'key' => 'power_cascade'`), aggiungere come ultima voce dell'array `choices`:
```php
[
    'label'         => 'Lo scanner mi dice quale linea sta cedendo',
    'hint'          => 'consuma lo scanner',
    'requires'      => ['has_item' => 'scanner'],
    'requires_item' => 'scanner',
    'outcomes' => [
        ['weight' => 1, 'effects' => [
            ['consume_item' => 'scanner'],
            ['resource' => 'power', 'delta' => 4],
        ], 'log' => 'Isoli la linea guasta prima che trascini le altre. Lo scanner si brucia nello sforzo, ma il settore regge.'],
    ],
],
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: avanza oltre `power_cascade` (ora fallisce su un'altra carta ancora mancante). Nessun errore di seeding.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/EventSeeder.php
git commit -m "feat: power_cascade — scanner isola la linea (consuma)"
```

---

## Task 3: `survivor_strained` + medkit

Via base peggiore: `morale -4` (faccio finta di niente). Scorciatoia: un sedativo, lo calmi senza perdita.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (carta a `:447`)

- [ ] **Step 1: Aggiungere la scelta gated**

Nella carta `survivor_strained`, ultima voce di `choices`:
```php
[
    'label'         => 'Gli do qualcosa dal kit per calmarlo',
    'hint'          => 'consuma il kit medico',
    'requires'      => ['has_item' => 'medkit'],
    'requires_item' => 'medkit',
    'outcomes' => [
        ['weight' => 1, 'effects' => [
            ['consume_item' => 'medkit'],
            ['resource' => 'morale', 'delta' => 3],
        ], 'log' => 'Un sedativo leggero. Si scioglie, respira, torna a galla. Il kit ne esce vuoto.'],
    ],
],
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: avanza oltre `survivor_strained`.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/EventSeeder.php
git commit -m "feat: survivor_strained — medkit lo calma (consuma)"
```

---

## Task 4: `survivor_breaks` + medkit

Via base peggiore: `morale -12` + danno (lo costringo a lavorare, ramo cattivo) o `morale -8` (lo lascio stare). Scorciatoia: tratti il crollo, niente danni.

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (carta a `:475`)

- [ ] **Step 1: Aggiungere la scelta gated**

Nella carta `survivor_breaks`, ultima voce di `choices`:
```php
[
    'label'         => 'Lo seda e lo tratto col kit',
    'hint'          => 'consuma il kit medico',
    'requires'      => ['has_item' => 'medkit'],
    'requires_item' => 'medkit',
    'outcomes' => [
        ['weight' => 1, 'effects' => [
            ['consume_item' => 'medkit'],
            ['character' => 'highest_stress', 'stress' => -20],
            ['resource' => 'morale', 'delta' => 2],
        ], 'log' => 'Lo riporti in sé prima che faccia danni. Quando si sveglia è svuotato, ma intero. Il kit no.'],
    ],
],
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: avanza oltre `survivor_breaks`.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/EventSeeder.php
git commit -m "feat: survivor_breaks — medkit tratta il crollo (consuma)"
```

---

## Task 5: `ration_crisis` + rations (2ª scelta gated)

Questa carta ha **già** una scelta gated su `rifle` (caccia). Aggiungiamo una seconda via gated su `rations`: apri la riserva, nessuno mangia da solo. Via base peggiore: `morale -14` + flag `ate_alone` (mangio solo io).

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` (carta a `:347`)

- [ ] **Step 1: Aggiungere la scelta gated**

Nella carta `ration_crisis`, ultima voce di `choices` (dopo la scelta `rifle`):
```php
[
    'label'         => 'Apro le razioni di riserva',
    'hint'          => 'consuma le razioni extra',
    'requires'      => ['has_item' => 'rations'],
    'requires_item' => 'rations',
    'outcomes' => [
        ['weight' => 1, 'effects' => [
            ['consume_item' => 'rations'],
            ['resource' => 'food', 'delta' => 12],
            ['resource' => 'morale', 'delta' => 4],
        ], 'log' => 'Tiri fuori il cuscinetto che tenevi da parte. Stasera nessuno salta il turno, nessuno mangia di nascosto. Domani sarà un altro problema.'],
    ],
],
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: avanza oltre `ration_crisis`.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/EventSeeder.php
git commit -m "feat: ration_crisis — razioni di riserva, nessuno mangia solo (consuma)"
```

---

## Task 6: `ration_cut_decision` + rations (helper `one()`)

Via base peggiore: `morale -15` + sfiducia + spawn rivolta (priorità a chi lavora). Scorciatoia: attingi alla riserva, eviti del tutto il taglio. Questa carta vive in `ContentEventSeeder.php` e usa `$this->one()`.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (carta a `:1738`)

- [ ] **Step 1: Aggiungere la scelta gated**

Nella carta `ration_cut_decision`, aggiungere all'array `choices` (ultima voce):
```php
array_merge(
    $this->one(
        'Attingo alle razioni di riserva',
        [['consume_item' => 'rations'], ['resource' => 'food', 'delta' => 14], ['resource' => 'morale', 'delta' => 2]],
        'Non tagli niente. Apri la riserva e fai finta che basti ancora a lungo. Le scatole sono finite.',
        'consuma le razioni extra',
        ['has_item' => 'rations'],
    ),
    ['requires_item' => 'rations'],
),
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: avanza oltre `ration_cut_decision`.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: ration_cut_decision — riserva evita il taglio (consuma)"
```

---

## Task 7: `fuel_leak_warning` + spacesuit (helper `one()`)

Via base peggiore: `power -12` (ripara subito) o ramo ignora → `fuel_crisis` a `power -30`. Scorciatoia: esci in tuta a sigillare, niente costo energetico.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (carta a `:1690`)

- [ ] **Step 1: Aggiungere la scelta gated**

Nella carta `fuel_leak_warning`, ultima voce di `choices`:
```php
array_merge(
    $this->one(
        'Esco in tuta a sigillare la perdita',
        [['consume_item' => 'spacesuit'], ['resource' => 'power', 'delta' => -2]],
        'Quaranta minuti nel vuoto, mani ferme sul sigillante. La perdita è chiusa senza bruciare energia. La tuta, dopo, non è più affidabile.',
        'consuma la tuta EVA',
        ['has_item' => 'spacesuit'],
    ),
    ['requires_item' => 'spacesuit'],
),
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: PASS su tutte e 3 le asserzioni (presenza, consumo, solo-griglia). Tutte le 6 carte ora coperte.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/ContentEventSeeder.php
git commit -m "feat: fuel_leak_warning — tuta sigilla la perdita (consuma)"
```

---

## Task 8: Test di visibilità end-to-end

Verifica che il motore mostri/nasconda davvero la scelta in base al possesso, su una carta rappresentativa (`power_cascade`).

**Files:**
- Modify: `backend/tests/Feature/ToolItemChoiceTest.php`

- [ ] **Step 1: Aggiungere il test di visibilità**

Aggiungere in coda a `ToolItemChoiceTest.php`. Adattare la costruzione dello stato al modo in cui gli altri Feature test costruiscono una Run + valutano le scelte visibili (cercare un test esistente che usa `visibleChoices` o l'`EventEngine`, es. in `EndingTest.php`/`EscapeChainTest.php`, e copiarne il setup):
```php
it('la scelta-strumento è visibile solo con l\'oggetto in inventario', function () {
    (new EventSeeder())->run();

    $card = \App\Models\Event::where('key', 'power_cascade')->firstOrFail();

    $evaluator = new \App\Game\Engine\ConditionEvaluator();

    $withScanner = new \App\Game\Engine\GameState(items: ['scanner']);
    $without     = new \App\Game\Engine\GameState(items: []);

    $visibleWith = collect($card->choices)->filter(
        fn ($c) => $evaluator->evaluate($c['requires'] ?? null, $withScanner)
    );
    $visibleWithout = collect($card->choices)->filter(
        fn ($c) => $evaluator->evaluate($c['requires'] ?? null, $without)
    );

    $hasScanner = fn ($coll) => $coll->contains(
        fn ($c) => ($c['requires']['has_item'] ?? null) === 'scanner'
    );

    expect($hasScanner($visibleWith))->toBeTrue();
    expect($hasScanner($visibleWithout))->toBeFalse();
});
```

> NOTA per l'esecutore: i nomi esatti di `GameState` (costruttore, campo `items`) e di `ConditionEvaluator::evaluate` vanno verificati contro il codice reale prima di scrivere — leggere `app/Game/Engine/ConditionEvaluator.php` e come gli altri Feature test istanziano lo stato. Se lo stato si costruisce via `RunFactory`/`Run` model, usare quel percorso invece del costruttore diretto. L'intento del test (visibile con oggetto, nascosta senza) resta invariato.

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter ToolItemChoiceTest`
Expected: PASS (4 test verdi).

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/ToolItemChoiceTest.php
git commit -m "test: visibilità scelta-strumento legata al possesso"
```

---

## Task 9: Taratura e verifica finale

- [ ] **Step 1: Sanity sul bilanciamento**

Rileggere le 6 scelte: per ognuna, il costo sulla risorsa-chiave deve essere chiaramente migliore della via base peggiore (vedi tabella spec §3). Se un numero stona col tono (es. troppo generoso), aggiustarlo — è una leva di tuning, non una regola fissa. Annotare eventuali modifiche.

- [ ] **Step 2: Suite intera + typecheck**

Run (da `backend/`): `php artisan test`
Expected: tutti verdi (i ~281 esistenti + i nuovi).

Run (da `frontend/`): `npx tsc --noEmit`
Expected: exit 0 (nessuna modifica frontend, deve restare pulito).

- [ ] **Step 3: Aggiornare il TODO**

In `docs/superpowers/TODO.md`, segnare Tier 1 #1 come fatto (o ridotto): le 6 carte frequenti con costo reale ora hanno scelte-strumento; nota che le 3 carte a costo-zero (old_scorch, reactor_gamble, the_sacrifice) sono state escluse di proposito e che `power_flicker` era già gated.

- [ ] **Step 4: Commit finale**

```bash
git add docs/superpowers/TODO.md
git commit -m "docs: Tier 1 #1 oggetti-strumento crisi comuni — fatto (6 carte)"
```

---

## Self-Review (note per l'esecutore)

- **Copertura spec:** le 6 carte della tabella §3 → Task 2-7. Test presenza/consumo/solo-griglia (§6 punti 1-3) → Task 1. Visibilità (§6 punto 4) → Task 8. Vantaggio/tuning (§6 punto 5) → Task 9 step 1.
- **Esclusioni dichiarate:** power_flicker (già gated), old_scorch/reactor_gamble/the_sacrifice (costo-base 0). Coerente con spec §3.
- **Rischio noto (Task 8):** i nomi esatti di `GameState`/`ConditionEvaluator` non sono confermati — il task istruisce di verificarli contro il codice reale prima di scrivere il test. Non è un placeholder sul *cosa* (intento chiaro), ma sul *come* istanziare lo stato, che dipende dall'API reale.
- **Numeri = tuning:** i delta delle scorciatoie sono punti di partenza ragionati (sempre migliori della via base), da validare a playtest come fame/spedizioni.
