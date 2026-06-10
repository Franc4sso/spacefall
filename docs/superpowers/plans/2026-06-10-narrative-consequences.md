# Conseguenze narrative in-run — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sì che le scelte della run producano carte-eco e catene IN-RUN (non solo un riassunto finale), e che nessuna scelta cada nel vuoto — usando solo contenuto + config, zero motore nuovo.

**Architecture:** Le primitive esistono già e sono testate (`set_flag` scrive, `spawn_event` accoda `{key, fire_on_day}` in `scheduled_events`, `requires:{flag}` gate, `EpilogueComposer` legge `config.epilogue.witness_flags`). Il lavoro è: (1) un test di raggiungibilità che rende ogni flag-nel-vuoto un fallimento; (2) carte-eco nuove per i flag davvero scoperti; (3) catene a tappe nuove dove mancano; (4) righe `witness_flags` per gli orfani che pagano solo in epilogo; (5) opzionale, finali nuovi gated dal sim.

**Tech Stack:** Laravel/PHP, Pest (test), seeder `ContentEventSeeder.php`, `config/game.php`, simulatore `php artisan sim:run`.

---

## Stato verificato (NON rifare)

- `EpilogueComposer` è puro e legge `config('game.epilogue.witness_flags')` (`flag => frase`). Collegare un flag all'epilogo = 1 riga di config. **11 flag già letti** (cannibalism, ate_alone, made_the_sacrifice, sos_sent, mutiny_occurred, log_falsified, vented_the_technician, lost_on_expedition, arc_garden_bloomed, arc_rescue_answered, arc_truth_found).
- **Cole è già un arco completo** (`cole_finds_exit → cole_defection / cole_heroics / cole_guilt`, chiuso da `cole_thread_done`). I flag Cole sono letti DENTRO il thread come gate. NON ricostruire l'arco Cole.
- **Già esistono eco** per: `vented_the_technician` → `technician_ghost` (EventSeeder.php:157); `left_someone` → `c_left_someone_ghost` (ContentEventSeeder.php:495). NON duplicare.
- `made_promise` è già letto a ContentEventSeeder.php:735. NON è orfano.
- Comando test: `cd backend && php artisan test`. Singolo file: `php artisan test --filter=NomeTest`.
- Comando sim: `cd backend && php artisan sim:run` (flag `--memory` per profilo persistito).

## Orfani VERI da collegare (verificati sul seeder)

| Flag | Origine | Scena ovvia? | Destino |
|------|---------|--------------|---------|
| `knows_the_past` | ContentEventSeeder.php:702 "Leggo i log" | sì (vantaggio) | eco-carta + witness |
| `illness_caught` | :978 atto silenzioso di Bex | sì | witness (eco opzionale) |
| `tended_crops` | :207, :684 coltivato | debole | witness |
| `research_complete` | :1286 dati trasmessi | sì | eco-carta + witness |
| `cole_found_exit`/`cole_heroics`/`cole_left`/`cole_resentful`/`cole_caused_death` | coleThread | letti nel thread | witness (pagano in epilogo) |
| `sensors_warned` | :287 (co-set con arc_truth_found, già in witness) | — | witness |

---

## Task 1: Test di raggiungibilità (il guardiano) — RED

**Files:**
- Create: `backend/tests/Feature/FlagReachabilityTest.php`

- [ ] **Step 1: Scrivi il test che fallisce**

Il test carica gli eventi seedati, raccoglie i flag SCRITTI (`set_flag`, scope run) e i flag LETTI (`requires` ricorsivo su all/any/not, `config.endings[].when`, `config.epilogue.witness_flags`), e pretende che ogni scritto sia letto (modulo whitelist).

```php
<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use App\Models\Event;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/** Flag di servizio non-narrativi: scritti/letti dal motore, non dalle scelte. */
const FLAG_REACHABILITY_WHITELIST = [
    'expedition_active', '__scheduled_only', '__never',
    'crew_trust', 'died_of_hunger', 'epithet',
];

/** Raccoglie ricorsivamente i flag letti da un albero di condizioni. */
function collectReadFlags(?array $cond, array &$into): void
{
    if ($cond === null) {
        return;
    }
    foreach (['all', 'any'] as $combinator) {
        if (array_key_exists($combinator, $cond)) {
            foreach ((array) $cond[$combinator] as $sub) {
                collectReadFlags($sub, $into);
            }
        }
    }
    if (array_key_exists('not', $cond)) {
        collectReadFlags($cond['not'], $into);
    }
    if (array_key_exists('flag', $cond)) {
        $into[$cond['flag']] = true;
    }
}

it('ogni flag scritto da una scelta è letto da una carta, un finale o l\'epilogo', function () {
    $written = [];
    $read = [];

    // Flag SCRITTI: scorri ogni evento/choice/outcome cercando set_flag (scope run).
    // Flag LETTI: requires a livello evento + a livello choice.
    foreach (Event::all() as $event) {
        collectReadFlags($event->requires, $read);

        foreach ($event->choices as $choice) {
            if (isset($choice['requires'])) {
                collectReadFlags($choice['requires'], $read);
            }
            foreach ($choice['outcomes'] ?? [] as $outcome) {
                foreach ($outcome['effects'] ?? [] as $effect) {
                    if (array_key_exists('set_flag', $effect)
                        && ($effect['scope'] ?? 'run') === 'run') {
                        $written[$effect['set_flag']] = true;
                    }
                    // spawn_event NON è un flag, ma garantisce che l'eco appaia:
                    // la carta-eco gated su quel flag conta come "letto" via requires.
                }
            }
        }
    }

    // Flag letti dai finali.
    foreach (config('game.endings') as $ending) {
        collectReadFlags($ending['when'] ?? null, $read);
    }

    // Flag letti dall'epilogo.
    foreach (array_keys(config('game.epilogue.witness_flags', [])) as $flag) {
        $read[$flag] = true;
    }

    $orphans = array_values(array_filter(
        array_keys($written),
        fn ($f) => ! isset($read[$f]) && ! in_array($f, FLAG_REACHABILITY_WHITELIST, true),
    ));

    expect($orphans)->toBe([], 'Flag scritti ma mai letti (collegali a una carta/finale/epilogo): ' . implode(', ', $orphans));
});
```

- [ ] **Step 2: Esegui il test, verifica che FALLISCE elencando gli orfani**

Run: `cd backend && php artisan test --filter=FlagReachabilityTest`
Expected: FAIL. Il messaggio elenca gli orfani reali (es. `knows_the_past, illness_caught, tended_crops, research_complete, cole_found_exit, ...`).

> Questo fallimento È la lista di lavoro per i task successivi. Annota gli orfani che emergono — i task 2-5 li azzerano.

- [ ] **Step 3: NON implementare ancora il fix.** Il test resta rosso fino al Task 6 (witness_flags). Commit del solo test.

```bash
cd backend && git add tests/Feature/FlagReachabilityTest.php
git commit -m "test: flag reachability guard (red — elenca gli orfani)"
```

---

## Task 2: Eco-carta per `knows_the_past` (vantaggio concreto)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` — la scelta "Leggo i log" (riga ~702) aggiunge uno `spawn_event`; nuova carta-eco nel gruppo `memoryEvents()`.
- Test: `backend/tests/Feature/NarrativeEchoTest.php` (create)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use App\Models\Event;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

it('esiste una carta-eco gated su knows_the_past che richiama la scelta', function () {
    $echo = Event::where('key', 'echo_knows_the_past')->first();
    expect($echo)->not->toBeNull();
    // Gated sul flag: appare solo se hai letto i log.
    expect($echo->requires)->toBe(['flag' => 'knows_the_past', 'is' => true]);
    // Richiamo esplicito alla scelta originale nel testo.
    expect(strtolower($echo->body))->toContain('log');
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: FAIL ("expect(null)->not->toBeNull()").

- [ ] **Step 3: Aggiungi lo spawn alla scelta originale**

In `ContentEventSeeder.php`, trova la scelta `$this->one('Leggo i log', [...], 'Sai com\'è finita, per loro.')` (riga ~702). Aggiungi lo `spawn_event` agli effetti:

```php
$this->one('Leggo i log', [['resource' => 'morale', 'delta' => -3], ['set_flag' => 'knows_the_past', 'value' => true], ['spawn_event' => ['key' => 'echo_knows_the_past', 'in_days' => 4]]], 'Sai com\'è finita, per loro.'),
```

- [ ] **Step 4: Aggiungi la carta-eco in `memoryEvents()`**

Dentro `memoryEvents()`, aggiungi al return array:

```php
$this->ev([
    'key' => 'echo_knows_the_past',
    'title' => 'Quello che sapevi',
    'body' => "Una valvola sibila come descritto nei log dell'equipaggio precedente — proprio il guasto che li ha uccisi. Stavolta sai cosa fare. La isoli prima che ceda.",
    'requires' => ['flag' => 'knows_the_past', 'is' => true],
    'base_weight' => 8,
    'cooldown_days' => 999,
    'choices' => [
        $this->one('Isolo la valvola, come da registro', [['resource' => 'hull', 'delta' => 8], ['resource' => 'morale', 'delta' => 5]], 'Il sapere dei morti vi tiene in vita. Per oggi.'),
    ],
]),
```

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/seeders/ContentEventSeeder.php tests/Feature/NarrativeEchoTest.php
git commit -m "feat: eco in-run per knows_the_past (vantaggio: eviti la trappola dei predecessori)"
```

---

## Task 3: Eco-carta per `research_complete` (il segnale parte)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` — la scelta "Completo l'analisi"/dati (riga ~1286) aggiunge `spawn_event`; nuova carta-eco.
- Test: `backend/tests/Feature/NarrativeEchoTest.php` (estendi)

- [ ] **Step 1: Aggiungi il test che fallisce (in coda al file esistente)**

```php
it('esiste una carta-eco gated su research_complete', function () {
    $echo = Event::where('key', 'echo_research_complete')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires)->toBe(['flag' => 'research_complete', 'is' => true]);
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: FAIL sul nuovo caso.

- [ ] **Step 3: Aggiungi lo spawn alla scelta originale (riga ~1286)**

Trova la choice che setta `research_complete` e aggiungi agli effetti dell'outcome:

```php
['set_flag' => 'research_complete', 'value' => true], ['spawn_event' => ['key' => 'echo_research_complete', 'in_days' => 5]],
```

(mantieni gli effetti esistenti `['resource' => 'morale', 'delta' => -8], ['character' => 'all', 'stress' => 6]`)

- [ ] **Step 4: Aggiungi la carta-eco in `memoryEvents()`**

```php
$this->ev([
    'key' => 'echo_research_complete',
    'title' => 'Un\'eco nel vuoto',
    'body' => "Giorni fa hai trasmesso i dati nel buio. Stanotte la radio gracchia: non una risposta, ma un riscontro automatico. Qualcuno, da qualche parte, ha ricevuto. La vostra storia non morirà con voi.",
    'requires' => ['flag' => 'research_complete', 'is' => true],
    'base_weight' => 7,
    'cooldown_days' => 999,
    'choices' => [
        $this->one('Almeno sapranno', [['resource' => 'morale', 'delta' => 8]], 'Non è salvezza. Ma è qualcosa.'),
    ],
]),
```

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: PASS (entrambi i casi eco).

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/seeders/ContentEventSeeder.php tests/Feature/NarrativeEchoTest.php
git commit -m "feat: eco in-run per research_complete (un riscontro dal vuoto)"
```

---

## Task 4: Catena rara — la spirale della fame (`ate_alone` → `echo_ate_alone`)

**Files:**
- Modify: `backend/database/seeders/EventSeeder.php` — la scelta che setta `ate_alone` (riga ~384) aggiunge `spawn_event`.
- Modify: `backend/database/seeders/ContentEventSeeder.php` — nuova carta-eco `echo_ate_alone` in `moralEvents()`.
- Test: `backend/tests/Feature/NarrativeEchoTest.php` (estendi)

> Nota: `ate_alone` e `cannibalism` sono GIÀ letti dall'epilogo (witness). Questa catena aggiunge l'eco IN-RUN che oggi manca: l'egoismo che si fa notare dal resto dell'equipaggio.

- [ ] **Step 1: Aggiungi il test che fallisce**

```php
it('esiste una carta-eco gated su ate_alone che richiama lo strappo', function () {
    $echo = Event::where('key', 'echo_ate_alone')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires)->toBe(['flag' => 'ate_alone', 'is' => true]);
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: FAIL sul nuovo caso.

- [ ] **Step 3: Aggiungi lo spawn alla scelta originale in `EventSeeder.php` (riga ~384)**

Trova l'effetto `['set_flag' => 'ate_alone', 'value' => true]` e affiancagli, nello stesso array di effetti:

```php
['spawn_event' => ['key' => 'echo_ate_alone', 'in_days' => 3]],
```

- [ ] **Step 4: Aggiungi la carta-eco in `moralEvents()` (ContentEventSeeder.php)**

```php
$this->ev([
    'key' => 'echo_ate_alone',
    'title' => 'Hanno visto',
    'speaker' => 'Anna',
    'body' => "Tre giorni fa hai mangiato voltando le spalle agli altri. Non l'hanno dimenticato. «Pensavo fossimo una squadra», dice Anna, senza alzare la voce. È peggio così.",
    'requires' => ['all' => [['flag' => 'ate_alone', 'is' => true], ['alive' => 'Anna']]],
    'base_weight' => 9,
    'cooldown_days' => 999,
    'choices' => [
        $this->one('Dovevo reggere. Per tutti voi.', [['resource' => 'morale', 'delta' => -4], ['modify_standing' => ['who' => 'Anna', 'delta' => -8]]], 'Anna annuisce piano. Non ci crede.'),
        $this->one('Avete ragione. Non succederà più.', [['resource' => 'morale', 'delta' => 4], ['modify_standing' => ['who' => 'Anna', 'delta' => 6]]], 'Una crepa ricucita a fatica.'),
    ],
]),
```

> Il gate `['alive' => 'Anna']` evita che un personaggio morto parli (coerenza morti, commit c447dcc).

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/seeders/EventSeeder.php database/seeders/ContentEventSeeder.php tests/Feature/NarrativeEchoTest.php
git commit -m "feat: eco in-run per ate_alone (l'equipaggio se n'è accorto)"
```

---

## Task 5: Eco-carta per `illness_caught` (Bex aveva ragione)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` — la scelta a riga ~978 aggiunge `spawn_event`; nuova carta-eco.
- Test: `backend/tests/Feature/NarrativeEchoTest.php` (estendi)

- [ ] **Step 1: Aggiungi il test che fallisce**

```php
it('esiste una carta-eco gated su illness_caught', function () {
    $echo = Event::where('key', 'echo_illness_caught')->first();
    expect($echo)->not->toBeNull();
    expect($echo->requires['all'][0])->toBe(['flag' => 'illness_caught', 'is' => true]);
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: FAIL sul nuovo caso.

- [ ] **Step 3: Aggiungi lo spawn alla scelta originale (riga ~978)**

All'outcome che setta `illness_caught` (l'atto silenzioso di Bex), affianca:

```php
['spawn_event' => ['key' => 'echo_illness_caught', 'in_days' => 4]],
```

- [ ] **Step 4: Aggiungi la carta-eco (in `characterEvents()` o `crosstalkEvents()`)**

```php
$this->ev([
    'key' => 'echo_illness_caught',
    'title' => 'Quello che Bex ha evitato',
    'speaker' => 'Bex',
    'body' => "Una febbre gira nei corridoi, lieve, già in ritirata. Sarebbe stata molto peggio se Bex non avesse agito in silenzio, giorni fa. Non se ne vanta. Ma tu lo sai.",
    'requires' => ['all' => [['flag' => 'illness_caught', 'is' => true], ['alive' => 'Bex']]],
    'base_weight' => 7,
    'cooldown_days' => 999,
    'choices' => [
        $this->one('Grazie, Bex.', [['resource' => 'morale', 'delta' => 5], ['modify_standing' => ['who' => 'Bex', 'delta' => 8]]], 'Bex scrolla le spalle. Ma qualcosa si scioglie.'),
    ],
]),
```

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=NarrativeEchoTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/seeders/ContentEventSeeder.php tests/Feature/NarrativeEchoTest.php
git commit -m "feat: eco in-run per illness_caught (Bex aveva ragione)"
```

---

## Task 6: Estendi `witness_flags` per gli orfani che pagano in epilogo — il test diventa VERDE

**Files:**
- Modify: `backend/config/game.php` — sezione `epilogue.witness_flags` (riga ~418).
- Test: `backend/tests/Unit/EpilogueComposerTest.php` (estendi)

> Questo collega gli orfani residui che NON hanno una scena in-run ovvia (i flag Cole, tended_crops, sensors_warned, e i flag che ora hanno eco ma meritano anche una frase finale: knows_the_past, illness_caught, research_complete). Dopo questo task, il Task 1 deve passare.

- [ ] **Step 1: Aggiungi il test che fallisce (in EpilogueComposerTest.php)**

```php
it('include una riga epilogo per knows_the_past', function () {
    $c = new EpilogueComposer();
    $state = endedState(['flags' => ['knows_the_past' => true]]);
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $choices = collect($sections)->firstWhere('title', 'Le tue scelte');
    expect($choices)->not->toBeNull();
    expect(implode(' ', $choices['lines']))->toContain('prima di voi');
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EpilogueComposerTest`
Expected: FAIL ("expect(null)->not->toBeNull()").

- [ ] **Step 3: Aggiungi le righe a `witness_flags` in `config/game.php`**

Dentro l'array `'witness_flags' => [ ... ]`, aggiungi:

```php
'knows_the_past'    => 'Hai letto cosa è successo a chi c\'era prima di voi. Vi è servito.',
'research_complete' => 'Hai mandato la verità nel buio. Forse qualcuno la troverà.',
'illness_caught'    => 'Un contagio è stato fermato prima che vedeste il suo volto.',
'tended_crops'      => 'Hai tenuto in vita qualcosa di verde.',
'cole_found_exit'   => 'Cole ha cercato una via di fuga. L\'hai seguito.',
'cole_heroics'      => 'Quando contava, Cole ha preso i comandi e non ha tremato.',
'cole_left'         => 'Cole se n\'è andato nel buio. Non saprai mai se ce l\'ha fatta.',
'cole_resentful'    => 'Cole non ti ha perdonato di averlo trattenuto qui.',
```

- [ ] **Step 4: Esegui, verifica PASS (epilogo)**

Run: `cd backend && php artisan test --filter=EpilogueComposerTest`
Expected: PASS (incluso il nuovo caso).

- [ ] **Step 5: Esegui il GUARDIANO (Task 1), verifica che ora è VERDE**

Run: `cd backend && php artisan test --filter=FlagReachabilityTest`
Expected: PASS — `$orphans` è `[]`. Se restano orfani, aggiungi una riga witness o un'eco per ciascuno (o, se è un flag di servizio, aggiungilo alla whitelist con motivazione).

- [ ] **Step 6: Commit**

```bash
cd backend && git add config/game.php tests/Unit/EpilogueComposerTest.php
git commit -m "feat: witness_flags per orfani residui — reachability guard verde"
```

---

## Task 7: Verifica di non-regressione (suite intera + sim)

**Files:** nessuno (solo verifica).

- [ ] **Step 1: Suite intera verde**

Run: `cd backend && php artisan test`
Expected: tutti i test passano (baseline 263 + i nuovi). 0 fallimenti.

- [ ] **Step 2: Sim — distribuzione finali sana, 0 stalli**

Run: `cd backend && php artisan sim:run`
Expected: la simulazione completa senza stalli; nessun collasso anomalo della distribuzione finali. Le eco (narrative, swing risorse piccoli) non devono spostare sensibilmente i win-rate. Annota i numeri.

- [ ] **Step 3: Se il sim mostra deriva di bilanciamento**, ridurre i `delta` risorsa delle eco (sono pensate per essere narrative, non meccaniche) e ri-eseguire. Le eco non devono diventare leve di tuning.

- [ ] **Step 4: Commit (se sono serviti aggiustamenti)**

```bash
cd backend && git add -A && git commit -m "chore: tuning eco per non perturbare il bilanciamento (sim verde)"
```

---

## Task 8 (OPZIONALE, isolato): Finale dedicato "Il prezzo della fame"

> Solo se vuoi che un percorso estremo cambi anche COME finisce, non solo il testo. Gated dal sim: se cannibalizza la distribuzione finali, NON mergiare questo task.

**Files:**
- Modify: `backend/config/game.php` — sezione `endings` (ordine = priorità).
- Test: `backend/tests/Feature/EndingTest.php` (estendi) — verifica conteggio e priorità.

- [ ] **Step 1: Conta gli ending attuali e scrivi il test che fallisce**

Run prima: `cd backend && grep -c "'type' => 'win'\|'type' => 'lose'" config/game.php` per sapere quanti sono.

```php
it('il finale prezzo_della_fame scatta solo con cannibalism + ate_alone, dopo i lose letali', function () {
    // Costruisci un run vivo a giorno >25 con i due flag.
    $run = app(\App\Game\RunFactory::class)->create(1, ['welder']);
    $run->day = 26;
    $run->flags = array_merge($run->flags, ['cannibalism' => true, 'ate_alone' => true]);
    $run->save();

    $ending = app(\App\Game\Engine\EndingService::class)->check($run->fresh());
    expect($ending['key'])->toBe('prezzo_della_fame');
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: FAIL (scatta `lone_survivor` o altro win generico).

- [ ] **Step 3: Aggiungi l'ending in `config/game.php`**

Inseriscilo DOPO i lose letali (`crew_lost`, `death_*`, `mutiny_end`) ma PRIMA dei win generici (`win_*`, `lone_survivor`):

```php
[
    'key' => 'prezzo_della_fame', 'type' => 'win',
    'name' => 'IL PREZZO DELLA FAME',
    'text' => 'Siete vivi. Ma per restarlo avete oltrepassato un confine da cui non si torna. La stazione vi ha salvati; non vi ha resi innocenti.',
    'when' => ['all' => [
        ['flag' => 'cannibalism', 'is' => true],
        ['flag' => 'ate_alone', 'is' => true],
        ['day' => ['op' => '>=', 'value' => 25]],
        ['living_crew' => ['op' => '>=', 'value' => 1]],
    ]],
],
```

- [ ] **Step 4: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: PASS.

- [ ] **Step 5: Sim — verifica che il fallback regga**

Run: `cd backend && php artisan sim:run`
Expected: `lone_survivor` resta raggiungibile come fallback; `prezzo_della_fame` appare solo nelle run estreme (raro). Se la distribuzione collassa o un win diventa irraggiungibile, RIMUOVI questo task.

- [ ] **Step 6: Commit (solo se sim verde)**

```bash
cd backend && git add config/game.php tests/Feature/EndingTest.php
git commit -m "feat: finale dedicato 'Il prezzo della fame' (cannibalism + ate_alone)"
```

---

## Self-review note (per l'esecutore)

- Il Task 1 è volutamente lasciato ROSSO fino al Task 6: è il contratto che dice "lavoro finito = nessun orfano".
- Prima di scrivere QUALSIASI eco nuova, esegui `grep -n "<flag>" database/seeders/*.php` per confermare che non esista già una carta che lo legge. Rafforza, non duplicare.
- Ogni eco che NOMINA un personaggio DEVE avere un gate `['alive' => 'Nome']` (o `relationship`, che già richiede entrambi vivi).
- Le eco sono narrative: `delta` risorsa piccoli. Se il sim si muove, sono troppo grossi.
```
