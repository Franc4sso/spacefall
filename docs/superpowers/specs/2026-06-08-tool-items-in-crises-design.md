# Spec — Oggetti-strumento nelle crisi comuni

> Stato: approvato 2026-06-08. Slice Tier 1 #1 dal `docs/superpowers/TODO.md`.
> Fonte design: brainstorming 2026-06-08 (questa sessione).

## Obiettivo

Le 7 crisi a peso di spawn più alto ottengono **una scelta condizionata al possesso di un
oggetto in griglia**. Oggi solo gli oggetti-cibo e l'attrezzatura-spedizioni interagiscono con
le carte; questo estende l'interazione oggetto↔crisi a tutto il pool comune. È l'arma più
efficiente contro la ripetizione: ogni oggetto della griglia guadagna un ruolo nelle crisi.

## Principio di design

- **Esito migliore ma con costo/rischio.** Lo strumento dà un esito migliore delle scelte
  base, ma mai gratis: gamble con odds migliori, consumo dell'oggetto, o costo collaterale
  (risorsa, follow-up). Chi non possiede l'oggetto gioca esattamente come oggi — niente
  regressione di bilanciamento.
- **Solo oggetti in griglia.** welder, scanner, drone, medkit, rifle, seedbank, comms.
  Niente oggetti dormienti (toolkit/manual/reactor_cell/sensors/flare/logbank): quegli
  oggetti non finiscono mai nella griglia finché non esiste il meta-sblocco (Tier 3 #6),
  quindi le loro scelte non comparirebbero comunque. Nessuna dipendenza da quel sistema.
- **No `rations`.** Tematicamente perfetto per le carte-razione, ma `food_emergency_rations`
  già consuma `rations`; evitiamo la ridondanza.

## Architettura

**Zero modifiche al motore.** Solo dati seeder. Il pattern è già implementato e testato.

### Pattern di gating (esistente)

Le scelte si gateano via due campi sulla singola opzione:

- `requires: {has_item: 'X'}` — gating logico. `ConditionEvaluator` valuta `has_item`
  contro `state->items`; `EventEngine::visibleChoices` marca la scelta `available: false`
  se manca l'oggetto, e `resolve` lancia `RuntimeException` se una scelta gateata viene
  scelta senza l'oggetto.
- `requires_item: 'X'` — hint per la UI (icona oggetto). Separato dal gating logico:
  vanno impostati entrambi.

Riferimenti del pattern già in uso:
- `EventSeeder.php` → `hull_breach` (choice-level `requires: {has_item: 'welder'}`).
- `ContentEventSeeder.php` → `trap_hull_critical` (spacesuit, via `array_merge` con
  `requires`/`requires_item`).

### Helper del seeder

`ContentEventSeeder` ha:

```php
one(string $label, array $effects, string $log, ?string $hint = null, ?array $requires = null)
gamble(string $label, array $good, string $goodLog, array $bad, string $badLog, int $goodW, int $badW, ?string $hint = null)
```

- `one()` accetta già `requires` come 5° argomento. Per impostare anche l'hint UI:
  `array_merge(one(...), ['requires_item' => 'X'])`.
- `gamble()` **non** accetta `requires`. Per un gamble gateato:
  `array_merge(gamble(...), ['requires' => ['has_item' => 'X'], 'requires_item' => 'X'])`
  (stesso schema di `trap_hull_critical`).

`EventSeeder` non usa questi helper — scrive array di scelte a mano. Lì la scelta-strumento
si aggiunge come array literal con `requires` + `requires_item`.

### Effetti disponibili (già nel motore)

`consume_item`, `grant_item`, `spawn_event`, `damage_system`, `resource/delta`,
`character/stress`, `character/hunger`, `kill`, `set_flag`, `modify_trust`, `modify_standing`.
Validati da `EventSchema`. Nessun nuovo effetto serve.

## Le 7 carte

`trap_hull_critical` è **lasciata com'è** (già gateata su spacesuit) e non conta in questa slice.

| # | Carta | Seeder | Scelta-strumento | Oggetto | Costo/rischio |
|---|-------|--------|------------------|---------|---------------|
| 1 | `power_flicker` | EventSeeder | salda il fusibile che cede | `welder` | fix solido (deterministico), piccolo costo `power` |
| 2 | `technician_panic` | EventSeeder | scansiona l'aria per provarlo/smentirlo | `scanner` | calma l'equipaggio; a volte rivela una perdita reale → `spawn_event` follow-up |
| 3 | `ration_crisis` | EventSeeder | vai a caccia di carne fresca | `rifle` | gamble: `food` vs ferita/`stress` |
| 4 | `ration_night` | EventSeeder | drone in ricognizione per scorte sparse | `drone` | gamble: `food` vs `consume_item: drone` |
| 5 | `trap_morale_collapse` | ContentEventSeeder | sedazione/triage medico | `medkit` | `morale` su, `consume_item: medkit` |
| 6 | `trap_cascade_failure` | ContentEventSeeder | guida remota via radio | `comms` | dimezza il danno a un sistema, costo `oxygen` |
| 7 | `food_sacrifice` | ContentEventSeeder | germoglio d'emergenza | `seedbank` | evita il `kill`; gamble: poco `food` vs niente (raccolto lento) |

Note per carta:
- **power_flicker**: la scelta base "lascio perdere" resta un gamble con possibile
  `power_cascade`. Il welder è la via sicura ma con piccolo costo.
- **technician_panic**: lo scanner deve poter *non* risolvere — l'outcome "rivela perdita"
  giustifica il costo e mantiene tensione. Riusa `spawn_event` con una key esistente o un
  follow-up leggero (decisione in fase di piano).
- **food_sacrifice**: è una carta one-shot (`cooldown_days: 999`) ad alta posta. Il seedbank
  offre una *terza* via che evita la morte ma non è garantita — gamble, non salvezza certa.
- **trap_cascade_failure**: ha due opzioni entrambe costose (`damage_system`). Il comms
  aggiunge una terza che riduce un danno ma non azzera, con costo `oxygen`.

## Testing

1. **Test dati (nuovo).** Un test che carica gli eventi seedati e asserisce, per ciascuna
   delle 7 carte:
   - esiste esattamente una scelta con `requires.has_item == <oggetto atteso>`;
   - quella scelta ha `requires_item == <oggetto atteso>` (hint UI presente);
   - gli effetti della scelta sono validi secondo `EventSchema` (incluso `consume_item`
     dove previsto).
2. **Simulatore di bilanciamento.** Girare il simulatore esistente per confermare assenza
   di regressioni (nessun crash, metriche di sopravvivenza nei range attesi).
3. **Copertura motore esistente.** `ItemTest` già copre il meccanismo di gating
   (available flip, RuntimeException su scelta gateata senza oggetto). Non va riscritto.

## Fuori scope

- Oggetti dormienti e meta-sblocco (Tier 3 #6).
- `trap_hull_critical` (già gateata).
- Uso di `rations` (overlap con `food_emergency_rations`).
- Modifiche al motore, allo schema effetti, o alla UI oltre l'uso di `requires_item`.

## Loose ends toccati

- Parziale progresso sul filo "contenuto oggetti dormiente": questa slice **non** li risveglia
  (scelta esplicita), ma stabilisce il pattern che il meta-sblocco riuserà.
