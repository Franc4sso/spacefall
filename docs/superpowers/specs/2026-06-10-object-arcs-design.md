# Spec — Archi narrativi degli oggetti

> Stato: approvato 2026-06-10. Slice Tier 2 #3 dal `docs/superpowers/TODO.md`.
> Fonte design: brainstorming 2026-06-10 (questa sessione).

## Obiettivo

Trasformare gli oggetti da "modificatori di risorsa" in **mini-storie a tappe**. Ogni oggetto
con un arco dà un *motivo per sceglierlo* (rigiocabilità mirata, non casuale) e profondità che
sfrutta i sistemi già costruiti (fasi, scelte-a-costo, epiloghi, win-gating). Un arco = una
storia a 3 capitoli sequenziali che si dipana lungo la run.

## I 3 archi (solo oggetti in griglia, accessibili oggi)

Decisione: solo oggetti che il giocatore può già scegliere — niente dipendenza dal meta-sblocco
(i 6 oggetti dormienti sono permanentemente inaccessibili oggi; risvegliarli è una fetta a sé).

- **seedbank — "L'orto"** (tema: vita / speranza): pianti i semi → curi l'orto → fiorisce o
  appassisce, a seconda di come l'hai trattato.
- **comms — "Il segnale"** (tema: salvezza): capti un'eco → la insegui → i soccorsi rispondono
  o il segnale tace per sempre.
- **scanner — "La verità"** (tema: mistero): la stazione mostra anomalie → indaghi → scopri cosa
  è successo a chi c'era prima di te.

## Struttura: catena sequenziale a flag (3 tappe)

Per ogni arco, 3 eventi concatenati con flag-progresso `arc_<item>_stage1` / `arc_<item>_stage2`.
Tutti i mecccanismi esistono già (esplorazione confermata): `set_flag`, `requires {flag}`,
`spawn_event {key, in_days}`, `requires {phase}`, `cooldown_days: 999`.

- **Tappa 1 (apertura).** `requires`: `all[has_item: <item>, <fase iniziale>]` — la fase iniziale
  fa aprire l'arco quando ha senso (es. seedbank in isolation/deterioration). `cooldown_days: 999`.
  La scelta del giocatore setta `arc_<item>_stage1` (a un valore che riflette la scelta — vedi
  "Le scelte colorano l'arco") e schedula la Tappa 2: `spawn_event {key: arc_<item>_2, in_days: 4}`.
- **Tappa 2 (sviluppo).** `requires`: `flag: arc_<item>_stage1` (is: true). Arriva ~4 giorni dopo
  la 1 (forzata dallo scheduling → garantita). La scelta setta `arc_<item>_stage2` e schedula la
  Tappa 3: `spawn_event {key: arc_<item>_3, in_days: 4}`. `cooldown_days: 999`.
- **Tappa 3 (finale d'arco).** `requires`: `flag: arc_<item>_stage2`. Arriva ~4 giorni dopo la 2.
  Chiude l'arco: narrazione esito + pagamento meccanico + flag-epilogo. `cooldown_days: 999`.

L'arco si dipana in ~8-10 giorni → completabile in una run media (banda 30-60 giorni).

**Nota tecnica (no contatori):** i flag sono boolean (o string/int via `set_flag value`). La
catena usa flag distinti per tappa, NON un contatore numerico (che non esiste). "Come hai
trattato l'orto" si codifica con flag/valori distinti (es. `arc_seedbank_neglected`), non con un
conteggio.

## Le scelte colorano l'arco (vincolo #1 dell'utente: niente scelte facili)

Ogni tappa è un **vero bivio a costo chiaro** — nessuna opzione dominante, ogni scelta paga un
prezzo su un asse diverso (come le 20 carte della content-injection). Le scelte non sono
cosmetiche: **determinano l'esito dell'arco**. Esempio (orto):
- Tappa 2 "curi l'orto": spendi acqua/energia ora per un raccolto migliore dopo, OPPURE risparmi
  ora ma l'orto soffre → la Tappa 3 sarà "fiorisce" vs "appassisce".
La Tappa 3 legge i flag/valori accumulati per scegliere il suo esito (buono/cattivo).

Il guard "no free choice" (già esistente in `FillContentTest`) va esteso/riusato sui nuovi eventi
d'arco multi-scelta: ogni scelta deve avere un costo reale.

## Pagamento dell'arco (narrativo + meccanico + epilogo)

Completare un arco (raggiungere la Tappa 3 con esito positivo) dà tutti e tre:

1. **Narrativo:** la Tappa 3 racconta l'esito coerente con le scelte (l'orto fiorisce; i soccorsi
   rispondono; la verità sulla stazione precedente).
2. **Meccanico reale:** un effetto tangibile, esempi per arco:
   - **seedbank/orto completo:** un grant di food sostanzioso + (opzionale) un flag che riduce la
     pressione fame; OPPURE schedula un evento ricorrente di raccolto. Scelta concreta nel piano.
   - **comms/soccorsi:** setta `sos_sent` (che ora gatea `win_rescue`) — l'arco diventa la *via*
     narrativa al soccorso, non un flag piazzato a caso.
   - **scanner/verità:** rivela/neutralizza una minaccia (es. previene o smorza un evento-trappola
     futuro via flag), o un bonus morale/standing dal "sapere".
   - Esito NEGATIVO dell'arco (scelte trascurate): nessun bonus, e un costo (l'orto morto =
     morale; il segnale perso = morale/standing).
3. **Epilogo:** la Tappa 3 setta un flag-testimone che l'epilogo legge. Estendo
   `config('game.epilogue.witness_flags')` (già estensibile) con voci tipo:
   - `arc_garden_bloomed` → "Hai fatto fiorire un orto dove tutto moriva."
   - `arc_rescue_answered` → "Il tuo segnale ha trovato orecchie. Qualcuno è venuto."
   - `arc_truth_found` → "Hai scoperto cosa è successo a chi c'era prima. Avresti preferenza di no."

## Architettura

**Solo dati seeder + un po' di config.** Nessuna modifica al motore (tutti i meccanismi esistono).

- I 9 eventi d'arco (3×3) in un metodo dedicato `objectArcEvents()` in `ContentEventSeeder.php`
  (o un seeder separato se il file è troppo grande — decisione in piano; preferenza: metodo
  dedicato per coerenza con gli altri thread).
- I flag-epilogo nuovi in `config('game.epilogue.witness_flags')`.
- Effetti meccanici via il vocabolario esistente (`grant_item`/`resource`/`set_flag`/
  `spawn_event`/`damage_system`).
- Chiavi inglesi (`arc_seedbank_1/2/3`, ecc.), testo italiano, tono terso.

## Testing

1. **Catena sequenziale (feature).** Tappa 2 NON eligible senza `arc_<item>_stage1`; eligible con.
   Idem Tappa 3 con `stage2`. Risolvere la Tappa 1 schedula la Tappa 2 (`spawn_event` in
   `scheduled_events`); risolvere la 2 schedula la 3.
2. **No-free-choice (data).** Ogni evento d'arco multi-scelta passa il guard: ogni scelta ha un
   costo reale (riusa l'euristica di `FillContentTest`).
3. **Pagamento (feature).** Completare un arco con esito positivo: setta il flag-epilogo atteso +
   applica l'effetto meccanico (es. grant food / `sos_sent`). L'epilogo include la riga del flag.
4. **Esito negativo (feature).** Trascurare l'arco porta alla Tappa 3 "cattiva": niente bonus, un
   costo, nessun flag-positivo settato.
5. **Dati (data).** I 9 eventi esistono, schema valido (riusa il guard di `ContentTest`), chiavi
   uniche; i flag-epilogo nuovi sono in config.
6. **Selector non-stalla.** Il pool allargato non introduce stalli; gli archi sono one-shot.
7. **Sim (`sim:run --memory`, 200 run).** Gli archi compaiono e ALCUNI si completano (verifica
   che la catena si concateni in run reali); win-rate resta ~30-40%, 0 stalli, nessuna spirale.
   Suite intera verde.

> **Nota onestà:** i test provano che la catena si concatena, paga, e che l'epilogo legge il
> flag; che l'arco *emozioni* lo dici tu giocando. Il sim conferma che funziona, non che è bello.

## Vincolo di completabilità (rischio noto)

Gli archi a tappe rischiano di restare monchi (muori prima della Tappa 3). Mitigazioni:
- Ritmo a giorni (~4 tra tappe) → ~8-10 giorni totali, dentro la run media.
- Lo scheduling FORZA la tappa successiva (Selector force-pick) → una volta aperto, l'arco
  progredisce in modo garantito finché sei vivo.
- Il pagamento meccanico arriva SOLO alla Tappa 3 — accettabile: un arco interrotto dalla morte è
  narrativamente coerente (non tutto si compie). L'epilogo può comunque notare un arco *iniziato*
  ma non concluso (opzionale, decisione in piano).

## Fuori scope

- **Archi su oggetti dormienti** (logbank/reactor_cell/...): richiedono il meta-sblocco (Tier 3
  #6). Quando ci sarà, gli archi-lore più ricchi vivranno lì.
- **Bivi ramificati ampi** (più di un esito buono/cattivo per arco): questa fetta fa la catena
  sequenziale con esito buono/cattivo guidato dalle scelte; ramificazioni più ampie dopo.
- **Contatori numerici** nel motore: non servono; si usano flag distinti.
- **Più di 3 archi:** drone/rifle/altri archi sono una fetta successiva.

## Loose ends toccati

- Dà agli oggetti-griglia un motivo narrativo (oggi sono +risorsa).
- Estende l'uso degli epiloghi (flag-testimone nuovi).
- Lega l'arco comms al win_rescue (l'SOS diventa una *storia*, non un flag isolato).
- Prepara il terreno per gli archi sui dormienti quando arriverà il meta-sblocco.
