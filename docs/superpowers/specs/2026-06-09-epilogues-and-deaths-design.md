# Spec — Epiloghi personalizzati, morti visibili, finali legati alle scelte

> Stato: approvato 2026-06-09. Slice Tier 3 #5 (Epiloghi) + i ganci flag-testimone.
> Fonte design: brainstorming 2026-06-09 (questa sessione).

## Problema

I finali oggi scattano su soglie di stato (giorno/risorsa) e mostrano una frase statica,
scollegata dalle scelte del giocatore ("ho vinto, PER COSA?"). E le morti passano quasi
inosservate (solo `alive=false` + una reaction generica). Il giocatore non capisce come il
finale e le morti si leghino alla sua storia.

**Due parti.** PARTE A — epiloghi/morti visibili/finali legati alle scelte (il racconto della
run). PARTE B — far sì che le scelte si SENTANO reali e impattanti e che la catena
causa→morte/salvezza sia derivabile (fix bug oggetti, mostrare i delta, registrare i delta per
scelta). L'audit ha confermato che le scelte HANNO già effetti reali nel motore; la Parte B
chiude i buchi che le fanno *sentire* leggere.

## Materiale già esistente (da esplorazione)

- `EndingService::check()` scorre `config('game.endings')` in ordine, primo match vince; salva
  `ending_key`/`ending_type`/`status` sul run. Chiamato da `EventEngine::resolveChoice` e
  `DayProcessor::advance`.
- `choice_log` (colonna JSON, ultimi 30): per voce `{day, event_key, choice_index, choice_label,
  tags, reaction_summary}`. Materiale per "le scelte chiave".
- 32 flag-testimone vengono SET dalle scelte (cannibalism, ate_alone, made_the_sacrifice,
  sos_sent, mutiny_occurred, cole_left, lost_on_expedition, bex_saw_death, ...) ma **quasi
  nessuno è LETTO** da un finale.
- Stato finale equipaggio: `characters[]` (name, role, traits, alive, stress, hunger),
  `relationships[]`, `flags[standing_*]`, `crew_trust`.
- `EpithetEngine` calcola un epiteto dai tag del choice_log (≥4 di un tag → il_freddo, il_generoso...).
- Ending payload (`RunController::endingPayload`) già esce con key/type/name/text/epithet.
- **Buco:** nessun registro delle morti (chi/quando/come); le morti sono solo un bool, e a
  schermo solo una reaction generica.

## I quattro pezzi

### 1. Registro morti (motore — il dato mancante)

Nuova colonna `death_log` su `runs` (JSON, default `[]`): lista di
`{name, day, cause, context}`.
- `name`: il caduto.
- `day`: giorno della morte (`$state->day`).
- `cause`: enum leggibile — `expedition` | `starvation` | `morale` | `event` (estendibile).
- `context`: stringa breve dalla fonte — per le morti da evento, l'`event_key` corrente
  (così l'epilogo dice "nella breccia dello scafo"); per fame/spedizione, una costante.

Popolato ai 3 (tre) punti-morte:
- **`EffectApplier::applyKill`** (kill da evento): cause `event`, context = event key in corso.
  L'engine passa l'event key al punto di kill (oggi `applyKill` non lo conosce — va inoltrato
  dalla chiamata in `EventEngine::resolveChoice`/`EffectApplier::apply`).
- **`DayProcessor` fame** (oggi setta `died_of_hunger`): cause `starvation`, context costante.
- **Spedizioni perse** (ExpeditionResolver/return, l'esito `lost`): cause `expedition`,
  context costante o la meta.

Il registro è additivo e sopravvive fino al fine-run per l'epilogo.

### 2. Annuncio di morte immediato (la morte è un beat visibile)

Quando una morte viene registrata, il motore **accoda subito una carta-momento** per il
giorno corrente che nomina il caduto + giorno + causa/contesto. Carta a scelta singola (un
addio), tono secco: es. *"Cole. Giorno 14. La spedizione non è tornata."*

- Meccanismo: riusa lo scheduling delle carte (come i marcatori di fase) — `scheduled_events`
  con una key `death_notice`. La carta legge l'ultima voce del `death_log` per personalizzarsi
  (o si generano voci puntuali; decisione in piano — preferenza: una carta `death_notice`
  generica il cui testo è composto a runtime dall'ultima morte registrata).
- **Caso-limite (no doppione):** se la morte coincide con la FINE della run (es. ultimo
  membro, o la morte fa scattare un ending), NON si accoda la carta-momento — lo dice
  l'epilogo. La carta appare solo per morti che lasciano il giocatore a proseguire.

### 3. Epilogo a sezioni (composizione — il "racconto della tua run")

Nuovo `EpilogueComposer` (puro): da `RunState` + `death_log` + flag-testimone + epiteto
produce una lista ordinata di **sezioni**, ognuna 1-2 frasi scarne (tono conciso e tagliente,
coerente coi finali attuali). Sezioni, in ordine:
1. **Esito** — perché è finita: riusa il `text` del finale-base esistente.
2. **Caduti** — per ogni voce del `death_log`: "Cole, giorno 14. Perso in spedizione."
3. **Scelte-cardine** — frammenti attivati dai flag-testimone non letti (cannibalism,
   ate_alone, made_the_sacrifice, sos_sent, mutiny_occurred, log_falsified, vented_the_technician...).
   Es.: "Hai mangiato voltando le spalle agli altri."
4. **Superstiti** — per ogni vivo: che fine fa, colorato da stress/standing/relazioni.
   Es.: "Anna resta. Non ti perdona." / "Bex tiene duro. Vi siete capiti."
5. **Epiteto** — la tua etichetta, se presente.

Esce nel payload `ending` come nuovo campo `epilogue: [{section, lines}]` accanto a
key/name/text/epithet. Frammenti (flag→frase, superstite→frase, causa→frase) in dati/config
o nel composer, tarabili.

### 4. Finali legati alle scelte (trigger — vinci PER qualcosa che hai fatto)

Le `when` dei win esistenti guadagnano un requisito d'AZIONE registrata, non solo numeri:
- `win_rescue` (oggi: comms + giorno≥24 + morale≥38) → aggiunge `flag sos_sent` (hai DAVVERO
  chiamato i soccorsi, non solo "hai la radio").
- `win_colony` (oggi: seedbank + giorno≥25 + food≥60) → aggiunge un flag/azione che provi di
  aver coltivato (es. un `farmed`/uso seedbank registrato).
- Gli altri win: dove esiste un'azione-chiave naturale, legarla; dove non esiste, lasciarli.

**Vincolo di sicurezza (critico):**
- Ogni azione richiesta deve essere RAGGIUNGIBILE (esiste una scelta che setta quel flag).
- `lone_survivor` (catch-all: vivo a giorno >25) RESTA il fallback garantito → nessuna run
  finisce senza un finale sensato.
- Verificato col sim: la distribuzione finali non deve collassare tutto su `lone_survivor`, e
  i win legati devono restare raggiungibili. Se un win diventa irraggiungibile, allentare.

## Testing

1. **Registro morti (unit, 3 punti-morte).** kill da evento → voce `{name, day:state.day,
   cause:'event', context:event_key}`; fame → `cause:'starvation'`; spedizione persa →
   `cause:'expedition'`. Più morti → più voci in ordine.
2. **Annuncio morte (feature).** Una morte che NON chiude la run accoda `death_notice` per il
   giorno corrente, una sola volta per morte; una morte che chiude la run NON la accoda.
3. **EpilogueComposer (unit).** death_log con 2 morti → sezione "caduti" con 2 righe corrette
   (nome/giorno/causa); flag cannibalism set → riga scelte-cardine presente, assente se non
   set; ogni superstite → una riga colorata da stress/standing; ordine sezioni esatto;
   nessuna sezione vuota emessa.
4. **Finali legati (feature).** `win_rescue` NON scatta senza `sos_sent` (a parità di
   soglie); scatta con `sos_sent`. `lone_survivor` copre il caso "vivo, nessun win-azione".
5. **Payload (feature).** Il payload `ending` include `epilogue` (lista sezioni) a run finita;
   null mentre attiva.
6. **Sim (`sim:run --memory`, 200 run).** Distribuzione finali sana (no collasso su
   lone_survivor; win legati ancora raggiungibili), 0 stalli, suite intera verde (oggi 223).

> **Nota onestà:** i test provano che l'epilogo SI COMPONE dai fatti giusti (morti, flag,
> superstiti) e che i finali scattano per le azioni giuste; che *commuova* lo dici tu al
> playtest. Ma il collegamento causa→racconto, che era il problema, è ora strutturale e
> verificabile.

## Parte B — Le scelte devono SENTIRSI reali e impattanti

L'audit conferma che le scelte HANNO già effetti reali e persistenti (mutano risorse/
equipaggio/sistemi/flag, possono chiudere la run, pool di effetti diversi per scelta diversa).
Ma tre buchi le fanno *sentire* leggere e impediscono di sapere il "perché" — vanno chiusi.

### B1. Bug: oggetti non persistiti (fix necessario)

`RunState::fromRun` carica `items`, ma `RunState::applyTo` NON riscrive `$this->items` su
`$run->items`. Quindi `grant_item`/`consume_item` applicati a metà run mutano lo stato in
memoria e vengono **persi al save** (es. lo scanner concesso al ritorno spedizione non resta).
Una scelta che dà/toglie un oggetto oggi non ha effetto reale. Fix: aggiungere
`$run->items = $this->items;` in `applyTo`. Più un test che resolveChoice con `grant_item`
persista l'oggetto sul run ricaricato.

### B2. Mostrare i delta degli effetti (la causa #1 del "non sento le scelte")

L'API ritorna già `resolution.effects` ma il frontend (`useRun.ts`) tiene solo il `log` testo:
il giocatore vede prosa, non "−12 ossigeno, +8 morale". Le barre si muovono in silenzio.
- **Backend:** assicurare che la risposta di resolveChoice esponga gli effetti applicati in
  forma leggibile dal client (delta per risorsa + eventi notevoli: morte, oggetto, sistema).
  Se già presenti in `resolution.effects`, normalizzarli in una forma stabile per la UI.
- **Frontend:** dopo una scelta, mostrare i delta (es. "−12 ossigeno", "+8 morale", "Anna:
  +stress") come feedback transitorio sulla card-risultato; e registrarli nel **Diario** accanto
  alla scelta. Stile coerente col gioco (secco). Tocca `useRun.ts`, la schermata risultato,
  `Diario.tsx`.

### B3. Registrare i delta per scelta (il "perché" di morte/salvezza)

Oggi il `choice_log` salva `{day, event_key, choice_index, choice_label, tags,
reaction_summary}` ma NON i delta-risorsa della scelta. Quindi sai *cosa* ti ha ucciso
(ending_key → risorsa) ma non *quale scelta* l'ha prosciugata.
- Aggiungere ai voci del choice_log un campo `effects_summary` (delta-risorsa netti +
  marcatori: kill/grant_item/damage_system) della scelta risolta.
- L'**epilogo** (Parte A #3) usa questo per la sezione caduti/scelte-cardine: "Giorno 16: hai
  sfiatato l'ossigeno. Giorno 18: l'aria è finita." — collega l'azione alla conseguenza fatale.
- Il `death_log` (Parte A #1) e l'`effects_summary` insieme danno la catena causa→morte.

### Testing Parte B
- **B1:** feature — resolveChoice con un outcome `grant_item` → `Run::find()->items` contiene
  l'oggetto dopo il save; `consume_item` lo rimuove. (Oggi fallirebbe.)
- **B2:** backend — la risposta di resolveChoice contiene gli effetti in forma normalizzata.
  Frontend — test (vitest) che il Diario/risultato mostra i delta dati un payload con effetti.
- **B3:** unit/feature — dopo una scelta con effetti risorsa, l'ultima voce del choice_log ha
  `effects_summary` coi delta corretti; l'epilogo li richiama nella timeline.

> Nota scope: la Parte B tocca anche il FRONTEND (prima volta sostanziale). Va trattata come
> blocco a sé nel piano. Senza un frontend test runner adeguato, i test B2-frontend possono
> essere minimi (asserzione su funzione di formattazione) — l'importante è che i delta
> raggiungano la UI.

## Fuori scope

- **Finali completamente nuovi** (es. "il prezzo della fame"): questa fetta rende meritati e
  raccontati i 13 esistenti; finali nuovi sono una fetta successiva.
- **Meta-progressione cross-run** (Tier 3 #6).
- **Epilogo in prosa cucita**: scelto il formato a sezioni (più chiaro e testabile).
- **Causa-di-morte ultra-dettagliata** oltre {name,day,cause,context}.

## Loose ends chiusi

- Risveglia i flag-testimone (cannibalism, lost_on_expedition, bex_saw_death, ...) dando loro
  finalmente un lettore (epilogo + finali).
- Rende le morti un evento visibile invece di un bool silenzioso.
- Collega i finali alle scelte — il "PER COSA?" trova risposta.
