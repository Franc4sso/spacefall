# Spec — Struttura ad Atti / Fasi

> Stato: approvato 2026-06-08. Slice Tier 2 #2 dal `docs/superpowers/TODO.md`.
> Fonte design: brainstorming 2026-06-08 (questa sessione).

## Obiettivo

La run evolve attraverso tre fasi — **Isolamento → Deterioramento → Resa dei conti** — così
che il giorno 30 si senta diverso dal giorno 3. Le fasi cambiano *quali* eventi compaiono
(gating), *quanto mordono* i sistemi (decadimento per fase), e segnano i passaggi con carte
narrative dedicate. Oggi non esiste alcun concetto di fase: i gate sono `requires {day}`
ad-hoc sparsi e gli endings scalano per soglia-giorno, ma niente raggruppa la run in atti.

## Le tre fasi

Chiavi inglesi (convenzione codebase) / display italiano:

| index | key | display | carattere |
|-------|-----|---------|-----------|
| 0 | `isolation` | Isolamento | calma, mistero, presentazione |
| 1 | `deterioration` | Deterioramento | i sistemi cedono, tensioni |
| 2 | `reckoning` | Resa dei conti | crisi terminali, spinta ai finali |

Ordine canonico definito **una volta** in config (`['isolation','deterioration','reckoning']`),
riusato da PhaseResolver (per `max`) e da ConditionEvaluator (per `phase_index`).

## Modello di fase

La fase è **derivata, non una colonna mutabile**, e **monotòna crescente** (il deterioramento
non si annulla). Calcolo:

```
fase_corrente = max(phase_floor, fascia_giorno(day), fascia_pressione(state))
```

- **fascia_giorno** (config): isolation g1–9, deterioration g10–20, reckoning g21+.
- **fascia_pressione** (conservativa): conta le risorse sotto la soglia-pericolo.
  - `< 3` risorse critiche → nessuna anticipazione (resta alla fascia-giorno);
  - `>= 3` risorse critiche → almeno `deterioration`;
  - tutte le risorse vitali critiche → `reckoning`.
  Il giorno resta il driver primario; la pressione anticipa solo in crisi vere.
- **phase_floor**: unica cosa persistita sul run. Aggiornato a fine giorno in DayProcessor
  se la fase calcolata supera il floor. Garantisce la monotonicità: una run che recupera
  non torna a una fase più calma.

Tutte le soglie (confini-giorno, n. risorse per la pressione, valore soglia-pericolo) vivono
in `config/game.php`, tarabili dal simulatore.

## Architettura (3 unità a responsabilità singola)

### 1. PhaseResolver — il calcolo

Nuova classe `app/Game/Engine/PhaseResolver.php`. Un solo metodo puro:

```php
resolve(RunState $state): string   // restituisce la chiave fase
```

Legge giorno + risorse + soglie config. Nessuno stato proprio. Unica fonte di verità del
calcolo. Banalmente testabile in isolamento.

Helper interni privati: `dayBand(int $day): string`, `pressureBand(RunState $state): string`.
Il `max` usa l'ordine canonico config.

### 2. Stato sul run — phase_floor

- Migration: nuova colonna `phase_floor` su `runs` (string, default `'isolation'`).
- `RunState` espone `phase` (calcolata via PhaseResolver) e legge/scrive `phase_floor`.
- `DayProcessor` aggiorna `phase_floor` a fine giorno (vedi sotto).
- La fase corrente **non** si salva mai — solo il floor.

### 3. I lettori

- **ConditionEvaluator** — due nuovi predicati:
  - `{phase: 'deterioration'}` — uguaglianza esatta con la fase corrente.
  - `{phase_index: {op:'>=', value:1}}` — confronto **numerico** sull'indice di fase
    (0/1/2), riusa il comparatore numerico esistente. Abilita "da questa fase in poi".
  L'indice si ricava dall'ordine canonico config.
- **DayProcessor** — legge la fase per il decadimento per-fase e per i marcatori (sotto).
- **API/UI** — la fase corrente (key + display) esce nel payload di stato del run
  (es. `currentCard`/stato), così il frontend mostra l'etichetta. Mappa key→display da config.

**Nota engine:** le sole aggiunte al motore sono PhaseResolver, i due predicati, il payload,
e il gancio decadimento+marcatori in DayProcessor. Tutto il resto è dati/config.

## Decadimento per fase

Nuovo dizionario in `config/game.php`:

```php
'phase_decay' => [
    'isolation'     => 1.0,   // baseline: comportamento attuale invariato
    'deterioration' => 1.25,
    'reckoning'     => 1.6,
],
```

`DayProcessor` legge la fase corrente e moltiplica il `daily_drain` delle risorse e il
`daily_decay` dei sistemi per quel fattore, prima di applicarli. Un solo punto di lettura.
`isolation = 1.0` ⇒ **regressione zero** per l'early/short game.

## Marcatori di transizione

Quando `phase_floor` **sale** (a fine giorno in DayProcessor), si accoda una carta-marcatore
dedicata per la nuova fase:

- `phase_enter_deterioration` — *"I sistemi iniziano a cedere"*
- `phase_enter_reckoning` — *"Non c'è più tempo"*

Carte narrative a **scelta singola** (stile dei "momenti" esistenti): segnano il passaggio,
non puniscono. Nessun marcatore per `isolation` (stato iniziale). Si attiva **una sola volta**
per fase (legato all'innalzamento monotòno del floor). Meccanismo: riuso di `spawn_event`
(immediato) o dello scheduling già usato per le conseguenze ritardate.

Gancio engine: in DayProcessor, "se la fase è salita, accoda il marcatore della nuova fase" —
una riga di logica + 2 eventi-dato.

## Contenuti: gating, retag, nuovi eventi

### A) Retag selettivo dell'esistente

Convertire i `requires {day}` ad-hoc a `{phase}`/`{phase_index}` **solo dove la soglia-giorno
corrisponde a un confine di fase e "fase" è il concetto giusto**. NON è una conversione
meccanica di ogni `day`.

- crisi generiche mid (day ≥8–14) → `{phase_index: {op:'>=', value:1}}` (deterioration+);
- terminali (day ≥21+) → `{phase: 'reckoning'}`;
- **Cautela thread di personaggio** (anna/bex/cole): i loro `day` fine-grana restano com'è —
  retag solo le crisi generiche, per non rompere assunzioni narrative dei fili.

**Rischio noto (esplicito):** con la pressione conservativa, `deterioration` può arrivare prima
del giorno 10 in una run in crisi (3 risorse critiche). Un evento prima gated a day 8 può
quindi comparire un po' prima — di norma è *voluto* (è il punto della fase). Nel piano:
retag un evento alla volta con verifica; lasciare i thread di personaggio fuori dal retag.

### B) Nuovi eventi distintivi — 2-3 per fase

Chiavi inglesi, testo italiano, gated per fase, in `ContentEventSeeder.php`, helper esistenti
(`ev`/`one`/`gamble`). Posta crescente:

- **isolation** (mistero/presentazione, bassa posta): segnale radio indecifrabile; oggetto
  dell'equipaggio precedente; la stazione che "respira" di notte.
- **deterioration** (i sistemi cedono, posta media): guasto a catena tra due sistemi;
  razionamento che incrina i rapporti; riparazione che regge a malapena.
- **reckoning** (terminale, alta posta): scelta irreversibile su chi salvare; un sistema non
  più recuperabile; il conto delle promesse non mantenute.

Essendo eventi nuovi, l'ordine dei choices è libero (la lezione "scelte in coda" della slice
precedente vale solo quando si modificano eventi esistenti con test index-based).

**Taglio di scope se serve:** se il piano si gonfia, tagliare per primi i nuovi di `isolation`
(atmosfera, meno critici del feeling "le cose peggiorano").

### C) Coerenza pool

Ogni fase ha sia eventi ereditati-retaggati sia nativi → il pool si sente diverso senza buchi.
I filler restano always-eligible come fallback, quindi il Selector ha sempre di che pescare.

## Testing

1. **PhaseResolver (unit).** Soglie-giorno (1/9→isolation, 10/20→deterioration, 21+→reckoning);
   pressione (2 critiche non anticipano; 3+ → deterioration+); **monotonicità** (floor alto +
   calcolo basso → resta alta); `max(floor,giorno,pressione)` in casi misti.
2. **Predicato DSL (unit, ConditionEvaluator).** `{phase:'x'}` matcha solo quella fase;
   `{phase_index:{op:'>=',value:1}}` matcha deterioration+reckoning, non isolation.
3. **Decadimento per fase (feature, DayProcessor).** Stesso stato, fase diversa → drain scala
   col moltiplicatore. **Verifica chiave: isolation=1.0 ⇒ numeri identici a oggi.**
4. **Marcatori (feature).** Innalzamento di phase_floor → marcatore accodato una sola volta;
   nessun marcatore per isolation; nessuna ripetizione.
5. **Gating contenuti (data, stile ToolChoiceTest).** Eventi nativi hanno il `requires {phase}`
   atteso; marcatori esistono e sono a scelta singola. `ContentTest` (schema su tutti gli
   eventi) resta verde → valida i nuovi predicati come DSL valido.
6. **Selector non-stallo per fase.** Estendere il test esistente: **ogni** fase deve avere
   pool pescabile (eventi + filler).
7. **Sim di bilanciamento.** 200 run: run-length nella banda ~30-60, esiti sani, **niente
   spirale precoce** da pressione+decay (rischio #1). Confronto win-rate pre/post; tarare
   `phase_decay` e soglie-pressione in config se reckoning arriva troppo presto.
8. **Regressione:** suite intera verde (oggi 184) + nuovi test.

## Fuori scope

- **Peso eventi per fase** (weight_modifiers su `{phase}`): scartato in questa fetta a favore
  del gating duro. Si può aggiungere dopo (l'infrastruttura `{phase}` lo abiliterà gratis).
- **Quarta fase "Speranza/Svolta"**: 3 fasi ora; la beat di ripresa è servita meglio da
  Tier 2 #3 (archi oggetti: orto, radio).
- **Contenuto ricco per fase** (10+ eventi nuovi): questa fetta fa 2-3 per fase; il resto in
  una fetta successiva, validato col sim.
- Modifiche agli endings esistenti (già scalano per giorno; li si potrà legare a `{phase}` dopo).

## Loose ends toccati

- Riduce (non elimina) i `requires {day}` ad-hoc sparsi, dando un concetto di fase di prima
  classe che le fette future (archi oggetti, epiloghi) potranno leggere.
