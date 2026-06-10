# Vittorie meritate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trasformare la Fuga da vittoria-regalo (possiedi tuta + aspetti) in una catena di scelte difficili, far raccontare all'epilogo COME hai vinto, e fixare il superstite "?" senza nome.

**Architecture:** La catena della Fuga è contenuto (carte nel seeder) + config (ending). Due interventi mirati al motore: `recruit` assegna un nome (EffectApplier), e una nuova sezione "Come avete vinto" nel composer puro `EpilogueComposer`. Le altre win vengono solo irrigidite (config). Tutto sul motore flag/spawn già collaudato.

**Tech Stack:** Laravel/PHP, Pest, seeder `ContentEventSeeder.php`, `config/game.php`, `EffectApplier.php`, `EpilogueComposer.php`, sim `php artisan sim:run`.

---

## Stato verificato (per l'esecutore)

- **recruit senza nome** (`app/Game/Engine/EffectApplier.php:85-93`): aggiunge `['role'=>..., 'alive'=>true, 'stress'=>0, 'hunger'=>0, 'traits'=>[]]` — NIENTE `name`. Causa del "?".
- **choice_log** (scritto in `EventEngine.php:188-195`): ogni voce ha `{day, event_key, choice_index, choice_label, tags, reaction_summary, effects_summary}`. Cap a 30. Letto in RunState come `$state->choiceLog`.
- **EpilogueComposer** (`app/Game/Engine/EpilogueComposer.php`): `compose(RunState $state, array $ending): array` costruisce `$sections` in ordine (Esito, Caduti, Le tue scelte, I superstiti, Come ti ricorderanno). Ogni sezione `['title'=>..., 'lines'=>[...]]`. Sezioni vuote omesse. La nuova sezione "Come avete vinto" va inserita SUBITO DOPO "Esito" (dopo la riga 21, prima del blocco Caduti).
- **roster**: 3 personaggi (Anna engineer, Bex doctor, Cole pilot). I reclutati sono il 4°+.
- **win_escape attuale** (`config/game.php:319-329`): `all[has_item spacesuit, day>=12, power>=40]`.
- **helper seeder**: `$this->ev([...])`, `$this->one($label,$effects,$log,$hint=null,$requires=null)`. Effetti: `resource`, `set_flag`, `spawn_event{key,in_days}`, `modify_standing{who,delta}`, `recruit{role}`.
- **Test**: `cd backend && php artisan test [--filter=X]`. Sim: `php artisan sim:run --count=300 --memory`.

---

## Task 1: Fix recruit "?" — assegna un nome

**Files:**
- Modify: `backend/config/game.php` (aggiungi lista `recruit_names`)
- Modify: `backend/app/Game/Engine/EffectApplier.php:85-93` (assegna name)
- Test: `backend/tests/Unit/RecruitNameTest.php` (create)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

use App\Game\Engine\EffectApplier;
use App\Game\Engine\RunState;

function recruitState(): RunState
{
    return new RunState(
        day: 5,
        resources: ['oxygen' => 50, 'food' => 50, 'power' => 50, 'morale' => 50, 'hull' => 50],
        flags: [],
        characters: [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 0, 'hunger' => 0, 'away_until' => 0],
        ],
    );
}

it('assigns a real name to a recruited survivor (never "?")', function () {
    $state = recruitState();
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, new \App\Game\Engine\SeededRng(1), []);

    $recruited = end($state->characters);
    expect($recruited['name'] ?? '?')->not->toBe('?');
    expect($recruited['name'] ?? '')->not->toBe('');
});

it('gives two recruits distinct names', function () {
    $state = recruitState();
    $rng = new \App\Game\Engine\SeededRng(1);
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, $rng, []);
    app(EffectApplier::class)->apply([['recruit' => ['role' => 'survivor']]], $state, $rng, []);

    $names = array_column($state->characters, 'name');
    expect(count($names))->toBe(count(array_unique($names)));
});
```

> NOTA: verifica la firma reale di `EffectApplier::apply` e del costruttore `RunState`/`SeededRng` con `grep -n "function apply" backend/app/Game/Engine/EffectApplier.php` e `grep -n "__construct" backend/app/Game/Engine/RunState.php backend/app/Game/Engine/SeededRng.php`. Se i parametri differiscono (es. apply non prende un context array, o RunState ha parametri obbligatori diversi), ADATTA la costruzione dello state e la chiamata mantenendo l'INTENTO del test (recruit → name non vuoto, due recruit → due nomi). Riporta gli adattamenti.

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=RecruitNameTest`
Expected: FAIL (il reclutato ha `name` assente → '?').

- [ ] **Step 3: Aggiungi la lista nomi in `config/game.php`**

Dopo la sezione `'roster' => [...]` (riga ~219-223), aggiungi:

```php
    /*
     | Nomi assegnati ai sopravvissuti reclutati in-game (effetto `recruit`),
     | così un nuovo membro ha sempre un'identità — mai "?" in carte/epilogo.
     */
    'recruit_names' => ['Vela', 'Renn', 'Mira', 'Sol', 'Juno', 'Tam'],
```

- [ ] **Step 4: Modifica il blocco recruit in `EffectApplier.php`**

Sostituisci il blocco `recruit` (righe 85-93) con una versione che assegna un nome non già in uso:

```php
        if (array_key_exists('recruit', $effect)) {
            $used = array_column($state->characters, 'name');
            $pool = config('game.recruit_names', []);
            $available = array_values(array_filter($pool, fn ($n) => ! in_array($n, $used, true)));
            $name = $available[0] ?? ('Sopravvissuto ' . (count($state->characters) + 1));
            $state->characters[] = [
                'name' => $name,
                'role' => $effect['recruit']['role'] ?? 'survivor',
                'alive' => true,
                'stress' => 0,
                'hunger' => 0,
                'traits' => [],
                'away_until' => 0,
            ];
            return;
        }
```

> Deterministico (primo nome libero) → non rompe la riproducibilità seed. Il fallback `'Sopravvissuto N'` copre il caso (raro) di pool esaurito — comunque mai "?".

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=RecruitNameTest`
Expected: PASS (entrambi i casi).

- [ ] **Step 6: Commit**

```bash
cd backend && git add config/game.php app/Game/Engine/EffectApplier.php tests/Unit/RecruitNameTest.php
git commit -m "fix: i sopravvissuti reclutati ricevono un nome (mai più '?')"
```

---

## Task 2: La catena della Fuga — tappe 1-2 (scoperta + riparazione)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` — nuovo metodo `escapeArc()`, registrato in `events()`.
- Test: `backend/tests/Feature/EscapeChainTest.php` (create)

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

it('seeds escape stage 1 gated on the spacesuit', function () {
    $e = Event::where('key', 'escape_1_discovery')->first();
    expect($e)->not->toBeNull();
    $json = json_encode($e->requires);
    expect($json)->toContain('spacesuit');
});

it('seeds escape stage 2 gated on escape_found', function () {
    $e = Event::where('key', 'escape_2_repair')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_found', 'is' => true]);
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EscapeChainTest`
Expected: FAIL (eventi inesistenti).

- [ ] **Step 3: Aggiungi il metodo `escapeArc()` in `ContentEventSeeder.php`**

Aggiungi questo metodo privato (vicino agli altri arc, es. dopo `objectArcEvents()`):

```php
    private function escapeArc(): array
    {
        return [
            // Tappa 1 — La scoperta del modulo di fuga.
            $this->ev([
                'key' => 'escape_1_discovery',
                'title' => 'Il modulo di fuga',
                'body' => "Dietro una paratia sigillata c'è un modulo di fuga. Danneggiato, ma forse recuperabile. Riportarlo in vita costerebbe risorse e tempo che forse non avete — ma è una via d'uscita vera.",
                'requires' => ['all' => [
                    ['has_item' => 'spacesuit'],
                    ['day' => ['op' => '>=', 'value' => 8]],
                    ['not' => ['flag' => 'escape_found', 'is' => true]],
                ]],
                'base_weight' => 9,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ci proviamo. È la nostra via fuori.', [['resource' => 'power', 'delta' => -4], ['set_flag' => 'escape_found', 'value' => true], ['spawn_event' => ['key' => 'escape_2_repair', 'in_days' => 3]]], 'Apri la paratia. Il modulo è messo male, ma c\'è.'),
                    $this->one('Non possiamo permettercelo ora', [['resource' => 'morale', 'delta' => -2]], 'Richiudi la paratia. Forse un altro giorno.'),
                ],
            ]),

            // Tappa 2 — La riparazione (sacrificio di risorse).
            $this->ev([
                'key' => 'escape_2_repair',
                'title' => 'Rimettere in sesto il modulo',
                'body' => "Il modulo ha bisogno di energia e parti che servono anche alla stazione. Ogni pezzo che ci metti è un pezzo che togli alla sopravvivenza di oggi.",
                'requires' => ['flag' => 'escape_found', 'is' => true],
                'base_weight' => 10,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ci lavoro sul serio', [['resource' => 'power', 'delta' => -8], ['resource' => 'hull', 'delta' => -4], ['set_flag' => 'escape_repaired', 'value' => true], ['spawn_event' => ['key' => 'escape_3_fuel', 'in_days' => 3]]], 'Mani nel metallo. Il modulo comincia a rispondere.'),
                    $this->one('Solo il minimo, per ora', [['resource' => 'power', 'delta' => -3]], 'Un cerotto. Il modulo resta a metà.'),
                ],
            ]),
        ];
    }
```

- [ ] **Step 4: Registra `escapeArc()` in `events()`**

In `ContentEventSeeder.php`, nel metodo `events()` (l'array `array_merge(...)`), aggiungi `$this->escapeArc(),` accanto a `$this->objectArcEvents(),`.

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EscapeChainTest`
Expected: PASS (entrambi i casi).

- [ ] **Step 6: Commit**

```bash
cd backend && git add database/seeders/ContentEventSeeder.php tests/Feature/EscapeChainTest.php
git commit -m "feat: catena Fuga tappe 1-2 (scoperta + riparazione del modulo)"
```

---

## Task 3: La catena della Fuga — tappe 3-4 (carburante + chi parte)

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` — estendi `escapeArc()`.
- Test: `backend/tests/Feature/EscapeChainTest.php` (estendi)

- [ ] **Step 1: Aggiungi i test che falliscono**

```php
it('seeds escape stage 3 gated on escape_repaired', function () {
    $e = Event::where('key', 'escape_3_fuel')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_repaired', 'is' => true]);
});

it('seeds escape stage 4 (who leaves) gated on escape_fueled and setting escape_launched', function () {
    $e = Event::where('key', 'escape_4_who_leaves')->first();
    expect($e)->not->toBeNull();
    expect($e->requires)->toBe(['flag' => 'escape_fueled', 'is' => true]);
    // almeno una choice setta escape_launched
    $sets = collect($e->choices)->flatMap(fn ($c) => collect($c['outcomes'] ?? [])->flatMap(fn ($o) => $o['effects'] ?? []))
        ->contains(fn ($eff) => ($eff['set_flag'] ?? null) === 'escape_launched');
    expect($sets)->toBeTrue();
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EscapeChainTest`
Expected: FAIL sui 2 nuovi casi.

- [ ] **Step 3: Estendi `escapeArc()` con le tappe 3 e 4**

Aggiungi questi due `$this->ev([...])` al return array di `escapeArc()` (dopo `escape_2_repair`):

```php
            // Tappa 3 — Il carburante (sacrificio netto).
            $this->ev([
                'key' => 'escape_3_fuel',
                'title' => 'Carburante',
                'body' => "Il modulo è pronto ma a secco. C'è abbastanza propellente solo se prosciugate una riserva della stazione. È un punto di non ritorno: dopo, vivere qui sarà più dura.",
                'requires' => ['flag' => 'escape_repaired', 'is' => true],
                'base_weight' => 10,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Prosciugo le riserve. Partiamo.', [['resource' => 'power', 'delta' => -10], ['resource' => 'oxygen', 'delta' => -6], ['set_flag' => 'escape_fueled', 'value' => true], ['spawn_event' => ['key' => 'escape_4_who_leaves', 'in_days' => 2]]], 'Il serbatoio si riempie. La stazione, dietro, si svuota.'),
                    $this->one('Non ancora. Troppo rischioso.', [['resource' => 'morale', 'delta' => -3]], 'Il modulo resta a terra. Per ora.'),
                ],
            ]),

            // Tappa 4 — Chi parte (2 posti). Sigillo della vittoria.
            $this->ev([
                'key' => 'escape_4_who_leaves',
                'title' => 'Due posti',
                'body' => "Il modulo ha due posti. Siete di più. Qualcuno deve restare a tenere accese le luci — e a morire con la stazione. La scelta è tua.",
                'requires' => ['flag' => 'escape_fueled', 'is' => true],
                'base_weight' => 12,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Salgono i più giovani. Io resto.', [['set_flag' => 'escape_launched', 'value' => true], ['set_flag' => 'escape_captain_stayed', 'value' => true], ['resource' => 'morale', 'delta' => -8]], 'Chiudi il portello dall\'esterno. Il modulo parte senza di te.'),
                    $this->one('Decido io chi merita di vivere.', [['set_flag' => 'escape_launched', 'value' => true], ['set_flag' => 'escape_captain_chose', 'value' => true], ['resource' => 'morale', 'delta' => -4]], 'Due salgono. Gli altri ti guardano dal vetro che si allontana.'),
                ],
            ]),
```

- [ ] **Step 4: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EscapeChainTest`
Expected: PASS (4 casi).

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/seeders/ContentEventSeeder.php tests/Feature/EscapeChainTest.php
git commit -m "feat: catena Fuga tappe 3-4 (carburante + chi parte, 2 posti)"
```

---

## Task 4: win_escape richiede la catena (config)

**Files:**
- Modify: `backend/config/game.php:319-329` (win_escape `when`)
- Test: `backend/tests/Feature/EndingTest.php` (estendi)

- [ ] **Step 1: Aggiungi i test che falliscono**

```php
it('win_escape does NOT fire from spacesuit alone without the chain', function () {
    $e = endingFor([
        'items' => ['spacesuit'],
        'day' => 14,
        'resources' => ['power' => 50, 'oxygen' => 30, 'food' => 30, 'morale' => 30, 'hull' => 30],
    ]);
    expect($e['key'] ?? null)->not->toBe('win_escape');
});

it('win_escape fires when the escape chain is launched', function () {
    $e = endingFor([
        'items' => ['spacesuit'],
        'flags' => ['escape_launched' => true],
        'day' => 16,
        'resources' => ['power' => 50, 'oxygen' => 30, 'food' => 30, 'morale' => 30, 'hull' => 30],
    ]);
    expect($e['key'])->toBe('win_escape');
});
```

- [ ] **Step 2: Esegui, verifica FAIL sul primo caso**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: FAIL — oggi win_escape scatta col solo spacesuit+day12+power, quindi il primo test (NON deve scattare) fallisce.

- [ ] **Step 3: Modifica `win_escape` in `config/game.php`**

Sostituisci il blocco `when` di `win_escape` (righe ~324-328) con:

```php
            'when' => ['all' => [
                ['flag' => 'escape_launched', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 15]],
            ]],
```

(Lascia invariati key/type/name/text.)

- [ ] **Step 4: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: PASS (entrambi i nuovi casi + tutti gli esistenti).

> Se un test esistente conta gli ending o assume il vecchio win_escape, aggiornalo coerentemente e annotalo nel report.

- [ ] **Step 5: Verifica il guardiano raggiungibilità (escape_launched dev'essere letto)**

Run: `cd backend && php artisan test --filter=FlagReachabilityTest`
Expected: PASS — `escape_launched` è letto dall'ending win_escape; `escape_found`/`escape_repaired`/`escape_fueled` sono letti dalle tappe successive (requires). Se restano orfani `escape_captain_stayed`/`escape_captain_chose`, NON aggiungerli alla whitelist: saranno letti dall'epilogo nel Task 5 — per ora il guardiano potrebbe segnalarli. Se segnala SOLO quei due, prosegui (Task 5 li chiude) e annotalo; se segnala altro, fermati e riporta.

- [ ] **Step 6: Commit**

```bash
cd backend && git add config/game.php tests/Feature/EndingTest.php
git commit -m "feat: win_escape richiede la catena (escape_launched), non più tuta+giorno12"
```

---

## Task 5: Epilogo — sezione "Come avete vinto" + righe chi-parte

**Files:**
- Modify: `backend/app/Game/Engine/EpilogueComposer.php` (nuova sezione dopo "Esito")
- Modify: `backend/config/game.php` (nuovo `epilogue.victory_beats`)
- Test: `backend/tests/Unit/EpilogueComposerTest.php` (estendi)

- [ ] **Step 1: Aggiungi il test che fallisce**

```php
it('builds a "Come avete vinto" section from the escape chain beats', function () {
    $c = new EpilogueComposer();
    $state = endedState([
        'flags' => ['escape_repaired' => true, 'escape_fueled' => true, 'escape_launched' => true, 'escape_captain_stayed' => true],
    ]);
    // choiceLog con i giorni delle tappe
    $state->choiceLog = [
        ['day' => 14, 'event_key' => 'escape_2_repair', 'choice_label' => 'Ci lavoro sul serio'],
        ['day' => 19, 'event_key' => 'escape_3_fuel', 'choice_label' => 'Prosciugo le riserve. Partiamo.'],
    ];
    $sections = $c->compose($state, ['key' => 'win_escape', 'name' => 'Fuga', 'text' => 'La tuta tiene...']);
    $vic = collect($sections)->firstWhere('title', 'Come avete vinto');
    expect($vic)->not->toBeNull();
    $joined = implode(' ', $vic['lines']);
    expect($joined)->toContain('modulo');   // tappa riparazione
    expect($joined)->toContain('14');        // giorno dal choiceLog
});
```

> Verifica che `endedState` permetta di settare `choiceLog` (è una proprietà pubblica di RunState). Se l'helper non lo espone, settalo dopo la costruzione come nel test sopra (`$state->choiceLog = [...]`).

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EpilogueComposerTest`
Expected: FAIL (sezione inesistente).

- [ ] **Step 3: Aggiungi `victory_beats` in `config/game.php`**

Dentro `'epilogue' => [...]`, accanto a `witness_flags`, aggiungi:

```php
        'victory_beats' => [
            'escape_repaired' => 'Hai rimesso in sesto il modulo di fuga, giorno {day}.',
            'escape_fueled'   => 'Hai prosciugato le riserve per il carburante, giorno {day}.',
        ],
        'victory_beats_event' => [
            'escape_repaired' => 'escape_2_repair',
            'escape_fueled'   => 'escape_3_fuel',
        ],
        'escape_outcome_lines' => [
            'escape_captain_stayed' => 'Sei rimasto indietro perché loro vivessero.',
            'escape_captain_chose'  => 'Hai scelto tu chi saliva. Gli altri sono rimasti.',
        ],
```

- [ ] **Step 4: Aggiungi la sezione nel composer**

In `EpilogueComposer::compose`, SUBITO DOPO il blocco "Esito" (dopo la riga `$sections[] = ['title' => 'Esito', ...];`, riga 21) e PRIMA del blocco `$causes`, inserisci:

```php
        // "Come avete vinto" — ricostruisce le tappe-chiave di una win con catena.
        $beats = config('game.epilogue.victory_beats', []);
        $beatEvents = config('game.epilogue.victory_beats_event', []);
        $victoryLines = [];
        foreach ($beats as $flag => $template) {
            if (($state->flags[$flag] ?? false) !== true) {
                continue;
            }
            $day = null;
            $eventKey = $beatEvents[$flag] ?? null;
            foreach ($state->choiceLog as $entry) {
                if (($entry['event_key'] ?? null) === $eventKey) {
                    $day = $entry['day'] ?? null;
                }
            }
            $victoryLines[] = $day !== null
                ? str_replace('{day}', (string) $day, $template)
                : str_replace(', giorno {day}', '', $template);
        }
        foreach (config('game.epilogue.escape_outcome_lines', []) as $flag => $line) {
            if (($state->flags[$flag] ?? false) === true) {
                $victoryLines[] = $line;
            }
        }
        if ($victoryLines !== []) {
            $sections[] = ['title' => 'Come avete vinto', 'lines' => $victoryLines];
        }
```

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EpilogueComposerTest`
Expected: PASS (nuovo caso + i 6 esistenti verdi).

- [ ] **Step 6: Verifica il guardiano (escape_captain_* ora letti dall'epilogo)**

Run: `cd backend && php artisan test --filter=FlagReachabilityTest`
Expected: PASS — `escape_captain_stayed`/`escape_captain_chose` ora letti da `escape_outcome_lines`. Il guardiano legge `witness_flags` ma NON `escape_outcome_lines`: se segnala questi due come orfani, aggiungi al guardiano la lettura di `config('game.epilogue.escape_outcome_lines')` (stesso pattern con cui legge witness_flags) — modifica minima a `FlagReachabilityTest.php`. Annota la modifica.

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Game/Engine/EpilogueComposer.php config/game.php tests/Unit/EpilogueComposerTest.php tests/Feature/FlagReachabilityTest.php
git commit -m "feat: epilogo 'Come avete vinto' — ricostruisce le tappe della Fuga"
```

---

## Task 6: Irrigidire le altre win (config)

**Files:**
- Modify: `backend/config/game.php` (win_research, lone_survivor)
- Test: `backend/tests/Feature/EndingTest.php` (estendi)

- [ ] **Step 1: Aggiungi il test che fallisce**

```php
it('lone_survivor requires surviving past day 28 (not an easy late-game out)', function () {
    $e = endingFor([
        'day' => 27,
        'resources' => ['oxygen' => 30, 'food' => 30, 'power' => 30, 'morale' => 30, 'hull' => 30],
    ]);
    expect($e['key'] ?? null)->not->toBe('lone_survivor');
});
```

- [ ] **Step 2: Esegui, verifica FAIL**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: FAIL — oggi lone_survivor scatta a day>25, quindi a day 27 scatterebbe.

- [ ] **Step 3: Alza la soglia di `lone_survivor` in `config/game.php`**

Nel blocco `lone_survivor` (`when.all`), cambia `['day' => ['op' => '>', 'value' => 25]]` in `['day' => ['op' => '>', 'value' => 28]]`.

- [ ] **Step 4: Alza `win_research` da day>=22 a day>=25**

Nel blocco `win_research`, cambia `['day' => ['op' => '>=', 'value' => 22]]` in `['day' => ['op' => '>=', 'value' => 25]]`.

- [ ] **Step 5: Esegui, verifica PASS**

Run: `cd backend && php artisan test --filter=EndingTest`
Expected: PASS (nuovo caso + esistenti).

> ATTENZIONE: alzando lone_survivor potrebbe esistere un test "ending reachable" che costruisce un run a un certo giorno. Se un test esistente si rompe, verifica che sia per la soglia cambiata e aggiornalo coerentemente (es. usa day 29 invece di 26). Annota.

- [ ] **Step 6: Commit**

```bash
cd backend && git add config/game.php tests/Feature/EndingTest.php
git commit -m "feat: irrigidite win_research e lone_survivor (soglie più tardive — cerotto provvisorio)"
```

---

## Task 7: Verifica finale (suite + sim)

**Files:** nessuno (verifica).

- [ ] **Step 1: Suite intera verde**

Run: `cd backend && php artisan test`
Expected: tutti i test passano (271 baseline + nuovi). 0 fallimenti.

- [ ] **Step 2: Sim — distribuzione, fallback, 0 stalli**

Run: `cd backend && php artisan sim:run --count=300 --memory`
Expected: 0 stalli; `lone_survivor` ancora presente nella distribuzione (resta fallback); il win-rate complessivo è SCESO rispetto a prima (voluto: le win sono più dure) ma NON è zero. Annota la distribuzione finali.

> CONTESTO: prima di questa fetta il sim mostrava `win_escape` assente con greedy_survival (la policy non costruisce la catena) — è ATTESO che win_escape resti raro/assente col bot greedy, perché ora richiede una sequenza deliberata di scelte. Il punto è che il GIOCATORE possa costruirla, non il bot. Verifica solo: 0 stalli, lone_survivor presente, nessun collasso patologico.

- [ ] **Step 3: Se il sim mostra stalli o collasso**, riduci la durezza (es. abbassa i costi delle tappe della catena, o riallinea le soglie del Task 6) e ri-esegui. Annota gli aggiustamenti.

- [ ] **Step 4: Commit (se servono aggiustamenti)**

```bash
cd backend && git add -A && git commit -m "chore: tuning bilanciamento vittorie meritate (sim verde)"
```

---

## Self-review note (per l'esecutore)

- La catena usa lo stesso pattern delle catene esistenti (spawn_event + flag gate). Prima di scrivere, `grep -n "escape_" database/seeders/*.php config/game.php` per confermare che le key non esistano già.
- I flag `escape_*` devono restare raggiungibili dal guardiano: escape_found/repaired/fueled letti dalle tappe successive (requires), escape_launched dall'ending, escape_captain_* dall'epilogo (Task 5 estende il guardiano a leggere escape_outcome_lines se serve).
- Nessuna carta-eco nomina un personaggio per nome qui (parlano in seconda persona "tu/voi"), quindi nessun gate `alive` necessario nelle tappe — MA la tappa 4 "chi parte" idealmente dovrebbe riflettere chi è VIVO: per questa fetta resta una scelta narrativa generica (2 posti, tu decidi), senza nominare nomi vivi/morti. Eventuale personalizzazione per-nome è fuori scope.
- Il fix recruit (Task 1) è indipendente dalla catena: si può fare per primo (lo è).
