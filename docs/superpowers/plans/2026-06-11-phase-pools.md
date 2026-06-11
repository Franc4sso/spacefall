# Pool di eventi per Atto/Fase — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere 15 eventi nuovi (5 per fase) al pool, gated `requires:{phase}`, con posta crescente, così che la run cambi carattere col tempo (giorno 30 ≠ giorno 3).

**Architecture:** Solo contenuto + test-guardiano. L'engine ad Atti è già completo (`PhaseResolver`, `ConditionEvaluator` valuta `phase`, `Selector` filtra via `requires`). I 15 eventi entrano nel metodo già esistente `ContentEventSeeder::phaseEvents()`, che oggi contiene 6 eventi (2 per fase) ed è già registrato in `events()`. Nessuna modifica all'engine, alle soglie di fase, o al pool base. Per non violare `FlagReachabilityTest`, i nuovi eventi NON introducono flag nuovi: usano effetti `resource`/`damage_system`/`character`/`kill`/`modify_trust` e, dove serve un flag, riusano flag già letti da finali/epilogo.

**Tech Stack:** PHP 8 / Laravel, Pest. Spec: `docs/superpowers/specs/2026-06-11-phase-pools-design.md`. Memoria: l'engine fasi esiste già (vedi spec §2.1).

---

## File Structure

- **Modify** `backend/database/seeders/ContentEventSeeder.php` — metodo `phaseEvents()` (inizia a `:96`). I 15 nuovi eventi si aggiungono dentro le 3 sezioni esistenti (ISOLATION / DETERIORATION / RECKONING), accanto ai 6 attuali. Nessun altro metodo cambia (`phaseEvents()` è già in `events()` a `:89`).
- **Create** `backend/tests/Feature/PhasePoolTest.php` — guardiano: presenza+gating, conteggio 5 nuovi/fase, posta crescente (iso senza kill/flag-finale; rec con esito definitivo), spawn end-to-end con fase forzata.

## Convenzioni del codebase (leggere prima di iniziare)

**Helper del seeder** (firme reali):
```php
$this->ev(array $e): array          // applica default: speaker=null, base_weight=12, cooldown_days=4, is_filler=false, requires=null
$this->one(string $label, array $effects, string $log, ?string $hint = null, ?array $requires = null): array
$this->gamble(string $label, array $good, string $goodLog, array $bad, string $badLog, int $goodW, int $badW, ?string $hint = null): array
```

**Forma di un evento** (pattern esistente, es. `iso_signal` a `:100`):
```php
$this->ev([
    'key' => 'iso_xxx', 'title' => '...', 'speaker' => 'Anna'|null,
    'body' => "...",
    'requires' => ['phase' => 'isolation'],      // o 'deterioration' / 'reckoning'
    'base_weight' => 8, 'cooldown_days' => 6,
    'choices' => [
        $this->one('Label', [['resource' => 'morale', 'delta' => 2]], 'Log esito.'),
        $this->one('Label2', [...], 'Log2.'),
    ],
]),
```

**Effetti validi** (`EventSchema::EFFECT_KEYS`): `resource` (`['resource'=>'power'|'oxygen'|'hull'|'food'|'morale','delta'=>N]`), `set_flag`, `spawn_event` (`['spawn_event'=>['key'=>...,'in_days'=>N]]`), `character` (`['character'=>'all'|'highest_stress'|nome,'stress'=>N]`), `damage_system` (`['damage_system'=>'power_grid'|'life_support'|'hull_integrity','amount'=>N]`), `kill` (`['kill'=>nome]`), `modify_trust` (`['modify_trust'=>N]`), `consume_item`, ecc.

**VINCOLO FLAG (critico):** `FlagReachabilityTest` impone che ogni flag *scritto* sia *letto* da qualche carta/finale/epilogo. NON introdurre flag nuovi. Se un evento ha bisogno di un `set_flag`, usare SOLO un flag già letto: `made_the_sacrifice`, `left_someone`, `cannibalism`, `knows_the_past`, `ate_alone`, `made_promise`. In dubbio, NON usare `set_flag` — bastano effetti `resource`/`kill`/`character`/`damage_system`.

**Fasi e prefissi:** `iso_*`→`['phase'=>'isolation']`, `det_*`→`['phase'=>'deterioration']`, `rec_*`→`['phase'=>'reckoning']`. Coerenza prefisso↔fase obbligatoria (il test la verifica).

**Posta crescente (regola numeri):**
- `iso_*`: delta piccoli (±2…±6), MAI `kill`, MAI flag-finale, esiti reversibili.
- `det_*`: delta medi (±6…±14), strascichi via `spawn_event`/flag-esistente ammessi, `gamble` ammesso.
- `rec_*`: delta estremi, `kill`/flag-finale (riusati) ammessi, esiti definitivi.

**Eseguire i test** (da `backend/`): `php artisan test --filter PhasePoolTest`. Suite intera: `php artisan test`.

---

## Task 1: Test-guardiano (presenza + gating + conteggio + posta)

TDD: il test fallisce finché i 15 eventi non esistono.

**Files:**
- Test: `backend/tests/Feature/PhasePoolTest.php`

- [ ] **Step 1: Scrivere il test**

Create `backend/tests/Feature/PhasePoolTest.php`:
```php
<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * I 15 nuovi eventi-fase introdotti da questa feature, con la fase attesa.
 * (Gli 8 eventi-fase preesistenti — iso_signal, iso_previous_crew,
 * det_chain_fault, det_ration_strain, rec_unrecoverable, rec_promises, più i 2
 * in dominoEvents — NON sono in questa lista: qui si guardano solo i nuovi.)
 *
 * @return array<string, string> event key => expected phase
 */
function phasePoolNewEvents(): array
{
    return [
        // Isolamento
        'iso_inventory'        => 'isolation',
        'iso_first_friction'   => 'isolation',
        'iso_routine'          => 'isolation',
        'iso_old_terminal'     => 'isolation',
        'iso_ration_habit'     => 'isolation',
        // Deterioramento
        'det_compound_failure' => 'deterioration',
        'det_dwindling_stores' => 'deterioration',
        'det_cracks_showing'   => 'deterioration',
        'det_rumor'            => 'deterioration',
        'det_make_do'          => 'deterioration',
        // Resa dei conti
        'rec_who_eats'         => 'reckoning',
        'rec_the_truth'        => 'reckoning',
        'rec_last_repair'      => 'reckoning',
        'rec_who_stays'        => 'reckoning',
        'rec_reckoning_vote'   => 'reckoning',
    ];
}

function phasePoolEvents(): array
{
    (new EventSeeder())->run();
    (new ContentEventSeeder())->run();

    return \App\Models\Event::all()->keyBy('key')->all();
}

it('ogni nuovo evento-fase esiste ed è gated sulla fase attesa', function () {
    $events = phasePoolEvents();

    foreach (phasePoolNewEvents() as $key => $phase) {
        expect(array_key_exists($key, $events))->toBeTrue("evento mancante: {$key}");
        $req = $events[$key]->requires;
        expect($req['phase'] ?? null)->toBe($phase, "fase errata in {$key}");
    }
});

it('ci sono esattamente 5 nuovi eventi per fase', function () {
    $byPhase = [];
    foreach (phasePoolNewEvents() as $phase) {
        $byPhase[$phase] = ($byPhase[$phase] ?? 0) + 1;
    }
    expect($byPhase)->toBe([
        'isolation' => 5,
        'deterioration' => 5,
        'reckoning' => 5,
    ]);
});

it('posta crescente: gli iso_* non uccidono né scrivono flag-finale; almeno un rec_* è definitivo', function () {
    $events = phasePoolEvents();

    // Helper: tutti gli effetti di un evento (su tutti gli outcome di tutte le scelte).
    $effectsOf = function ($event) {
        $all = [];
        foreach ($event->choices as $choice) {
            foreach ($choice['outcomes'] as $o) {
                foreach ($o['effects'] as $e) {
                    $all[] = $e;
                }
            }
        }
        return $all;
    };

    $finalFlags = ['made_the_sacrifice', 'left_someone', 'cannibalism'];

    // iso_*: nessun kill, nessun flag-finale.
    foreach (phasePoolNewEvents() as $key => $phase) {
        if ($phase !== 'isolation') {
            continue;
        }
        foreach ($effectsOf($events[$key]) as $e) {
            expect(array_key_exists('kill', $e))->toBeFalse("iso {$key} non deve uccidere");
            $flag = $e['set_flag'] ?? null;
            expect(in_array($flag, $finalFlags, true))->toBeFalse("iso {$key} non deve scrivere flag-finale");
        }
    }

    // rec_*: almeno uno ha un esito definitivo (kill o flag-finale).
    $hasDefinitive = false;
    foreach (phasePoolNewEvents() as $key => $phase) {
        if ($phase !== 'reckoning') {
            continue;
        }
        foreach ($effectsOf($events[$key]) as $e) {
            if (array_key_exists('kill', $e) || in_array($e['set_flag'] ?? null, $finalFlags, true)) {
                $hasDefinitive = true;
            }
        }
    }
    expect($hasDefinitive)->toBeTrue('almeno un rec_* deve avere un esito definitivo (kill o flag-finale)');
});
```

- [ ] **Step 2: Eseguire, verificare fallimento**

Run: `php artisan test --filter PhasePoolTest`
Expected: FAIL — primo test cade su `iso_inventory` mancante (asserzione, non crash). Se è un fatal error PHP, sistemare il test perché fallisca su un'asserzione.

- [ ] **Step 3: Commit rosso**

```bash
git add tests/Feature/PhasePoolTest.php
git commit -m "test: guardiano pool eventi per fase (rosso)"
```

---

## Task 2: 5 eventi Isolamento (`iso_*`)

Aggiungere alla sezione `// --- ISOLATION ...` di `phaseEvents()` (dopo `iso_previous_crew`, prima del commento `// --- DETERIORATION`).

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (`phaseEvents()`, sezione ISOLATION)

- [ ] **Step 1: Inserire i 5 eventi**

```php
$this->ev([
    'key' => 'iso_inventory', 'title' => 'L\'inventario', 'speaker' => null,
    'body' => "Conti scatole, filtri, celle. Numeri piccoli, ma è la prima volta che li guardi davvero. Decidi come tenerli.",
    'requires' => ['phase' => 'isolation'],
    'base_weight' => 7, 'cooldown_days' => 7,
    'choices' => [
        $this->one('Ottimizzo subito gli spazi', [['resource' => 'food', 'delta' => 4]], 'Un po\' d\'ordine. Qualche scatola in più di quanto pensassi.'),
        $this->one('Rimando, c\'è tempo', [['resource' => 'morale', 'delta' => 2]], 'Chiudi il portello. Tanto i numeri non scappano.'),
    ],
]),
$this->ev([
    'key' => 'iso_first_friction', 'title' => 'Il primo screzio', 'speaker' => null,
    'body' => "Due voci alzate nel corridoio per una sciocchezza: un turno, una tazza, niente. Ma è il primo.",
    'requires' => ['phase' => 'isolation'],
    'base_weight' => 8, 'cooldown_days' => 6,
    'choices' => [
        $this->one('Medio io', [['resource' => 'morale', 'delta' => -2]], 'Si stringono la mano, controvoglia. Per ora basta.'),
        $this->one('Lascio correre', [['character' => 'highest_stress', 'stress' => 6]], 'Si allontanano in silenzio. Qualcosa resta sospeso.'),
    ],
]),
$this->ev([
    'key' => 'iso_routine', 'title' => 'Una routine', 'speaker' => 'Cole',
    'body' => "Cole propone turni fissi, orari, una tabella. 'Se ci diamo una regola adesso, dopo è più facile.' Oppure no.",
    'requires' => ['phase' => 'isolation'],
    'base_weight' => 7, 'cooldown_days' => 8,
    'choices' => [
        $this->one('Imponiamo i turni', [['resource' => 'morale', 'delta' => 3], ['character' => 'highest_stress', 'stress' => 4]], 'La tabella regge. Qualcuno la odia già.'),
        $this->one('Restiamo liberi', [['resource' => 'morale', 'delta' => -2]], 'Niente regole. Più leggero adesso, più caos poi.'),
    ],
]),
$this->ev([
    'key' => 'iso_old_terminal', 'title' => 'Un terminale acceso', 'speaker' => null,
    'body' => "Uno schermo che credevi morto lampeggia: frammenti di log della stazione, di prima. Puoi leggere, o spegnere e basta.",
    'requires' => ['phase' => 'isolation'],
    'base_weight' => 6, 'cooldown_days' => 9,
    'choices' => [
        $this->one('Leggo tutto', [['set_flag' => 'knows_the_past', 'value' => true], ['resource' => 'morale', 'delta' => -3]], 'Sai cosa è successo a chi c\'era prima. Non aiuta a dormire.'),
        $this->one('Spengo', [], 'Lo schermo si fa nero. Alcune cose è meglio non saperle.'),
    ],
]),
$this->ev([
    'key' => 'iso_ration_habit', 'title' => 'Le prime razioni', 'speaker' => null,
    'body' => "Stabilisci la norma: quanto si mangia, da oggi. Tutto ciò che viene dopo parte da qui.",
    'requires' => ['phase' => 'isolation'],
    'base_weight' => 8, 'cooldown_days' => 7,
    'choices' => [
        $this->one('Generoso, per il morale', [['resource' => 'morale', 'delta' => 4], ['resource' => 'food', 'delta' => -5]], 'Si mangia bene. Finché dura.'),
        $this->one('Prudente, mettiamo via', [['resource' => 'food', 'delta' => 5], ['resource' => 'morale', 'delta' => -2]], 'Porzioni strette. Un cuscinetto per il buio che verrà.'),
    ],
]),
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter PhasePoolTest`
Expected: il 1° test avanza oltre tutti gli `iso_*` (ora fallisce sui `det_*`). Il 3° test (posta) deve PASSARE per la parte iso (nessun kill/flag-finale negli iso). Nessun errore di seeding.

- [ ] **Step 3: Commit**

```bash
git add database/seeders/ContentEventSeeder.php
git commit -m "feat: 5 eventi Isolamento (posta bassa, reversibile)"
```

---

## Task 3: 5 eventi Deterioramento (`det_*`)

Aggiungere alla sezione `// --- DETERIORATION ...` (dopo `det_ration_strain`, prima del commento `// --- RECKONING`).

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (`phaseEvents()`, sezione DETERIORATION)

- [ ] **Step 1: Inserire i 5 eventi**

```php
$this->ev([
    'key' => 'det_compound_failure', 'title' => 'Tre cose insieme', 'speaker' => 'Cole',
    'body' => "Cole urla dal ponte inferiore: 'Tre allarmi insieme! Posso correre solo da una parte — dimmi dove!'",
    'requires' => ['phase' => 'deterioration'],
    'base_weight' => 11, 'cooldown_days' => 5,
    'choices' => [
        $this->one('Salva l\'energia', [['resource' => 'power', 'delta' => -6], ['damage_system' => 'life_support', 'amount' => 12]], 'Le luci reggono. L\'aria peggiora.'),
        $this->one('Salva l\'aria', [['resource' => 'oxygen', 'delta' => -6], ['damage_system' => 'power_grid', 'amount' => 12]], 'Si respira. Qualcosa, al buio, cede.'),
        $this->one('Salva lo scafo', [['resource' => 'hull', 'delta' => -6], ['damage_system' => 'power_grid', 'amount' => 8], ['damage_system' => 'life_support', 'amount' => 8]], 'La paratia tiene. Tutto il resto arranca.'),
    ],
]),
$this->ev([
    'key' => 'det_dwindling_stores', 'title' => 'Il fondo del magazzino', 'speaker' => null,
    'body' => "Le mensole sono quasi vuote. Quello che resta, lo gestisci adesso: stringere ancora, o aprire tutto e sperare.",
    'requires' => ['phase' => 'deterioration'],
    'base_weight' => 10, 'cooldown_days' => 5,
    'choices' => [
        $this->one('Razioniamo duro', [['character' => 'all', 'stress' => 8], ['resource' => 'food', 'delta' => 4]], 'Pance vuote, ma le scorte durano un altro po\'.'),
        array_merge(
            $this->one('Apriamo tutto adesso', [['resource' => 'food', 'delta' => 10], ['resource' => 'morale', 'delta' => 4], ['spawn_event' => ['key' => 'det_dwindling_stores', 'in_days' => 6]]], 'Stasera si mangia. Domani sarà un problema più grande.'),
            ['tags' => ['short_term']]
        ),
    ],
]),
$this->ev([
    'key' => 'det_cracks_showing', 'title' => 'Qualcuno cede', 'speaker' => null,
    'body' => "Lo vedi negli occhi di chi è più a pezzi: mani che tremano, frasi che non finiscono. Sta per rompersi.",
    'requires' => ['phase' => 'deterioration'],
    'base_weight' => 10, 'cooldown_days' => 5,
    'choices' => [
        $this->one('Mi fermo a sostenerlo', [['character' => 'highest_stress', 'stress' => -14], ['resource' => 'power', 'delta' => -4]], 'Un\'ora persa, accanto a lui. Ma respira di nuovo.'),
        array_merge(
            $this->one('Si stringe i denti', [['character' => 'highest_stress', 'stress' => 12], ['spawn_event' => ['key' => 'survivor_breaks', 'in_days' => 4]]], 'Annuisce e torna al lavoro. Qualcosa, dentro, scricchiola.'),
            ['tags' => ['pushed_too_hard']]
        ),
    ],
]),
$this->ev([
    'key' => 'det_rumor', 'title' => 'Una voce', 'speaker' => null,
    'body' => "Gira un mormorio: che tu nascondi qualcosa, che le scelte non sono giuste. Non sai chi l\'ha cominciato.",
    'requires' => ['phase' => 'deterioration'],
    'base_weight' => 9, 'cooldown_days' => 6,
    'choices' => [
        $this->one('Lo affronto apertamente', [['resource' => 'morale', 'delta' => -6], ['modify_trust' => 10]], 'Metti tutto sul tavolo. Disagio, ma aria più pulita.'),
        $this->one('Lo ignoro', [['modify_trust' => -8]], 'Fai finta di niente. Il mormorio si fa più fitto.'),
    ],
]),
$this->ev([
    'key' => 'det_make_do', 'title' => 'Arrangiarsi', 'speaker' => 'Anna',
    'body' => "Anna soppesa un pezzo storto. 'Non è il ricambio giusto. Posso forzarlo — magari regge, magari peggiora. Provo?'",
    'requires' => ['phase' => 'deterioration'],
    'base_weight' => 10, 'cooldown_days' => 5,
    'choices' => [
        $this->gamble('Improvvisa',
            [['resource' => 'hull', 'delta' => 8]], 'Tiene. Brutto a vedersi, ma tiene.',
            [['resource' => 'hull', 'delta' => -10], ['damage_system' => 'hull_integrity', 'amount' => 12]], 'Si spacca peggio di prima. Anna impreca piano.',
            6, 4, 'potrebbe peggiorare'),
        $this->one('Non tocchiamo niente', [['resource' => 'hull', 'delta' => -4]], 'Meglio non rischiare. Il degrado va avanti, lento.'),
    ],
]),
```

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter PhasePoolTest`
Expected: il 1° test avanza oltre i `det_*` (ora fallisce sui `rec_*`). Nessun errore di seeding. (Nota: `det_*` riusa solo lo spawn dei propri key o di `survivor_breaks` già esistente — nessun flag nuovo.)

- [ ] **Step 3: Commit**

```bash
git add database/seeders/ContentEventSeeder.php
git commit -m "feat: 5 eventi Deterioramento (posta media, strascichi)"
```

---

## Task 4: 5 eventi Resa dei conti (`rec_*`)

Aggiungere alla sezione `// --- RECKONING ...` (dopo `rec_promises`, prima della chiusura `];` del metodo). Posta estrema: ammessi `kill` e flag-finale RIUSATI (`made_the_sacrifice`, `left_someone`, `cannibalism`). Nessun flag nuovo.

**Files:**
- Modify: `backend/database/seeders/ContentEventSeeder.php` (`phaseEvents()`, sezione RECKONING)

- [ ] **Step 1: Inserire i 5 eventi**

```php
$this->ev([
    'key' => 'rec_who_eats', 'title' => 'Chi mangia', 'speaker' => null,
    'body' => "Non c\'è abbastanza per tutti, non stasera. Qualcuno salterà — e lo sai tu chi.",
    'requires' => ['phase' => 'reckoning'],
    'base_weight' => 12, 'cooldown_days' => 999,
    'choices' => [
        $this->one('Privo me stesso', [['resource' => 'morale', 'delta' => 6], ['resource' => 'food', 'delta' => -4]], 'Cedi la tua parte. Ti guardano in un modo nuovo.'),
        $this->one('Privo i più deboli', [['character' => 'highest_stress', 'stress' => 18], ['resource' => 'morale', 'delta' => -10]], 'Decidi tu chi stringe la cinghia. Nessuno te lo perdona del tutto.'),
    ],
]),
$this->ev([
    'key' => 'rec_the_truth', 'title' => 'La verità', 'speaker' => null,
    'body' => "Tutto quello che hai tenuto nascosto preme per uscire. Puoi dirlo, adesso, o portartelo dietro fino alla fine.",
    'requires' => ['phase' => 'reckoning'],
    'base_weight' => 11, 'cooldown_days' => 999,
    'choices' => [
        $this->one('Confesso tutto', [['resource' => 'morale', 'delta' => -8], ['modify_trust' => 14]], 'Lo dici. Cala il silenzio, poi qualcosa si scioglie.'),
        $this->one('Me lo tengo', [['modify_trust' => -6], ['character' => 'highest_stress', 'stress' => 8]], 'Seppellisci tutto. Il peso resta tuo, e si vede.'),
    ],
]),
$this->ev([
    'key' => 'rec_last_repair', 'title' => 'L\'ultima riparazione', 'speaker' => 'Anna',
    'body' => "Anna ti guarda con le mani sporche. 'Questa è l\'ultima volta che possiamo scegliere. Dopo, qualunque cosa lasciamo andare, è andata.'",
    'requires' => ['phase' => 'reckoning'],
    'base_weight' => 12, 'cooldown_days' => 999,
    'choices' => [
        $this->one('Teniamo viva la stazione', [['resource' => 'power', 'delta' => -16], ['resource' => 'oxygen', 'delta' => -10]], 'Strappi un altro giorno al buio. A un prezzo che si sente.'),
        $this->one('Lasciamo morire un sistema', [['damage_system' => 'life_support', 'amount' => 40], ['resource' => 'morale', 'delta' => -6]], 'Spegni un settore per sempre. Più freddo, più stretto, ma vivi.'),
    ],
]),
$this->ev([
    'key' => 'rec_who_stays', 'title' => 'Chi resta', 'speaker' => null,
    'body' => "Per farcela, qualcuno deve restare indietro. Non c\'è modo gentile di dirlo, e non c\'è tempo.",
    'requires' => ['phase' => 'reckoning'],
    'base_weight' => 10, 'cooldown_days' => 999,
    'choices' => [
        $this->one('Resto io', [['set_flag' => 'made_the_sacrifice', 'value' => true], ['resource' => 'morale', 'delta' => 8]], 'Lo dici prima di pensarci troppo. Nessuno protesta abbastanza forte.'),
        $this->one('Lascio uno di loro', [['kill' => 'highest_stress'], ['set_flag' => 'left_someone', 'value' => true], ['resource' => 'morale', 'delta' => -16]], 'Scegli, e chiudi il portello. Il silenzio dopo è assoluto.'),
    ],
]),
$this->ev([
    'key' => 'rec_reckoning_vote', 'title' => 'La conta', 'speaker' => null,
    'body' => "Si radunano tutti. Te lo chiedono in faccia: dove stiamo andando? Resistere, arrendersi, o giocarci tutto in una volta?",
    'requires' => ['phase' => 'reckoning'],
    'base_weight' => 11, 'cooldown_days' => 999,
    'choices' => [
        $this->one('Resistiamo ancora', [['character' => 'all', 'stress' => -6], ['resource' => 'morale', 'delta' => 4]], 'Stringi i denti per tutti. Si torna ai posti, in silenzio.'),
        $this->one('Ci arrendiamo alla deriva', [['resource' => 'morale', 'delta' => -12]], 'Smetti di combattere il buio. C\'è una pace, terribile, in questo.'),
        $this->one('Giochiamoci tutto', [['resource' => 'power', 'delta' => -12], ['resource' => 'hull', 'delta' => -8], ['resource' => 'morale', 'delta' => 10]], 'Tutto su una carta. Almeno si muore provandoci.'),
    ],
]),
```

> NOTA esecutore: `['kill' => 'highest_stress']` è valido — verificato: `EffectApplier::applyKill()` instrada il selettore in `resolveTarget()`, che gestisce `'highest_stress'` (`EffectApplier.php:269`). Scrivere così com'è.

- [ ] **Step 2: Eseguire il test**

Run: `php artisan test --filter PhasePoolTest`
Expected: TUTTI e 3 i test PASSANO. In particolare il 3° (posta) verde perché `rec_who_stays` ha sia `kill` sia flag-finale. Nessun errore di seeding.

- [ ] **Step 3: Commit**

```bash
git add database/seeders/ContentEventSeeder.php
git commit -m "feat: 5 eventi Resa dei conti (posta estrema, definitiva)"
```

---

## Task 5: Test spawn end-to-end (fase forzata)

Verifica che il `Selector` includa i `det_*` quando la fase è `deterioration` ed escluda gli `iso_*`/`rec_*`.

**Files:**
- Modify: `backend/tests/Feature/PhasePoolTest.php`

- [ ] **Step 1: Aggiungere il test**

Adattare il setup al modo reale in cui gli altri Feature test costruiscono lo stato e forzano la fase. Riferimento: `RunState` espone `phase`/`phaseIndex`; il `Run` ha la colonna `phase_floor`; `PhaseResolver::resolve($day,$resources,$floor)` calcola la fase (floor monotòno → forzare `phase_floor='deterioration'` o `day>=10` mette la run in deterioration). Cercare un test esistente che istanzia un `Run`/`RunState` con un giorno specifico (es. `EscapeChainTest`, `EndingTest`) e copiarne il pattern.

```php
it('in deterioration il selector include i det_* e non gli iso_*/rec_* esclusivi', function () {
    $events = phasePoolEvents();

    $evaluator = new \App\Game\Engine\ConditionEvaluator();

    // Stato forzato in deterioration. Adattare alla costruzione reale dello stato:
    // qui via RunFactory + RunState con giorno nella banda deterioration (>=10).
    $run = app(\App\Game\RunFactory::class)->create(1, []);
    $run->day = 12;
    $run->phase_floor = 'deterioration';
    $state = \App\Game\Engine\RunState::fromRun($run);
    expect($state->phase)->toBe('deterioration');

    $eligible = fn ($key) => $evaluator->evaluate($events[$key]->requires, $state);

    expect($eligible('det_compound_failure'))->toBeTrue('un det_* deve essere eleggibile in deterioration');
    expect($eligible('iso_inventory'))->toBeFalse('un iso_* non deve essere eleggibile in deterioration');
    expect($eligible('rec_who_eats'))->toBeFalse('un rec_* non deve essere eleggibile in deterioration');
});
```

> NOTA esecutore: se `RunState::fromRun` legge `day`/`phase_floor` dal model in modo diverso (es. servono `$run->save()` o un costruttore differente), adattare. Verificare che `$state->phase` risulti `'deterioration'` prima di asserire il resto; se non lo è, correggere il modo di forzare la fase (non l'intento). Se la costruzione diretta del `Run` non funziona, usare il pattern di un test esistente che già ottiene uno stato a un giorno dato.

- [ ] **Step 2: Eseguire**

Run: `php artisan test --filter PhasePoolTest`
Expected: 4 test verdi.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PhasePoolTest.php
git commit -m "test: spawn eventi-fase con fase forzata (end-to-end)"
```

---

## Task 6: Verifica finale

- [ ] **Step 1: FlagReachability + suite intera**

Run (da `backend/`): `php artisan test`
Expected: tutti verdi, INCLUSO `FlagReachabilityTest` (nessun flag-orfano: i nuovi eventi scrivono solo `knows_the_past`, `made_the_sacrifice`, `left_someone` — tutti già letti). Se `FlagReachabilityTest` fallisce, un evento ha introdotto un flag non letto: rimuoverlo o sostituirlo con un flag già letto.

- [ ] **Step 2: Typecheck frontend**

Run (da `frontend/`): `npx tsc --noEmit`
Expected: exit 0 (nessuna modifica frontend).

- [ ] **Step 3: Sanity bilanciamento**

Rileggere i 15 eventi: confermare la curva di posta (iso piccoli/reversibili, det medi/strascichi, rec estremi/definitivi). Aggiustare numeri che stonano col tono — leva di tuning.

- [ ] **Step 4: Aggiornare il TODO**

In `docs/superpowers/TODO.md`, segnare Tier 2 #2 come fatto: l'engine ad Atti c\'era già; aggiunti 15 eventi (5/fase) a posta crescente nel pool `phaseEvents()`.

- [ ] **Step 5: Commit finale**

```bash
git add docs/superpowers/TODO.md
git commit -m "docs: Tier 2 #2 pool eventi per fase — fatto (15 eventi)"
```

---

## Self-Review (note per l'esecutore)

- **Copertura spec:** 15 eventi §4 → Task 2-4 (uno per fase). Test presenza/gating/conteggio §7.1-2 → Task 1. Posta crescente §7.3 → Task 1 (3° test). Spawn end-to-end §7.4 → Task 5. FlagReachability §7.5 → Task 6 step 1. Tuning §5 → Task 6 step 3.
- **Vincolo flag rispettato:** gli unici `set_flag` introdotti sono `knows_the_past`, `made_the_sacrifice`, `left_someone` — tutti già letti (verificato: compaiono nella lista flag già usati). Nessun flag nuovo → `FlagReachabilityTest` resta verde.
- **Un rischio dichiarato (dipende dall'API reale):** forzare la fase nel test end-to-end (Task 5) — adattare al pattern reale di costruzione stato; l'intento è fisso, il "come" si verifica contro il codice. (Il rischio `kill`/`highest_stress` è stato chiuso: verificato valido in `EffectApplier::resolveTarget`.)
- **Coerenza prefisso↔fase:** tutti gli `iso_*`/`det_*`/`rec_*` hanno `requires.phase` coerente — verificato evento per evento nel codice del piano.
