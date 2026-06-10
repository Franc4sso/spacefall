# Spec — Coerenza della morte (3 bug dal playtest)

> Stato: approvato 2026-06-10. Bug-fix con design, dal playtest dell'utente.
> Fonte: diagnosi 2026-06-10 (questa sessione) + brainstorming.

## I tre bug (dal playtest)

Il giocatore è arrivato a un finale `lone_survivor` ("Hai salvato la stazione. Non hai salvato
nessuno.") con TUTTI e tre i membri dell'equipaggio morti di fame (giorni 19-20), e ha
segnalato tre problemi, tutti confermati dalla diagnosi:

1. **Finale incoerente.** `lone_survivor` (catch-all win, `oxygen>0 && day>25`) scatta sia con
   superstiti sia con l'equipaggio azzerato. Non esiste un finale per "tutto l'equipaggio morto",
   né un modo per il motore di contare i vivi. Vincere con 3 morti su 3 è una contraddizione.
2. **Morti invisibili.** Tre falle: (a) la fame avvisa UNA volta sola (banda singola a hunger≥30,
   non ripetente) poi sale silenziosa fino a 100→morte; (b) la carta `death_notice` è ANONIMA
   (non dice chi/come) e su morti simultanee ne compare una sola (dedup per key); (c) i dati ci
   sono (hunger per personaggio nel payload) ma il gioco non li racconta.
3. **(Il peggiore) I morti continuano a "parlare".** Il motore filtra i morti quasi ovunque
   (reazioni, targeting effetti, `has_role`), MA non nell'eleggibilità degli eventi: un evento con
   `speaker: Anna` scatta anche se Anna è morta, e il corpo la nomina. 40+ eventi a rischio.
   Manca un gate automatico "lo speaker dev'essere vivo".

## Sez. 1 — Primitivo conta-vivi (fondamenta per #1)

Nuova condizione DSL in `ConditionEvaluator`: `{living_crew: {op, value}}` — conta i `characters`
con `alive === true` (escludendo i via-spedizione? no: away-ma-vivi contano come vivi; solo
`alive` conta) e applica `compare()` come gli altri predicati numerici. Aggiunta a
`EventSchema::CONDITION_KEYS`. Riusabile in futuro (es. eventi gated su "rimasti in pochi").

## Sez. 2 — Finale "Equipaggio perduto" (#1)

Nuovo ending di tipo **lose** in `config('game.endings')`, inserito **prima di tutti i win**
(l'ordine = priorità; deve pre-emptare `lone_survivor`/`crew_intact`/ecc.):

```
'key' => 'crew_lost', 'type' => 'lose',
'name' => 'SOLO',
'text' => 'Sei rimasto solo. La stazione respira ancora. Tu, dentro, un po' meno.',
'when' => ['living_crew' => ['op' => '==', 'value' => 0]],
```

- **Nessuna soglia-giorno** → scatta **appena muore l'ultimo** membro. `EndingService::check` gira
  dopo ogni risoluzione di scelta e dopo ogni avanzamento giorno, quindi la morte dell'ultimo
  (sia da evento sia da fame) fa terminare la run subito. Risolve anche i "giorni-fantasma"
  (niente più giorni giocati da soli con un equipaggio morto).
- Va PRIMA delle morti-da-risorsa? Decisione: metterlo subito dopo le morti-da-risorsa lethal e
  PRIMA dei win e di `mutiny_end` non serve — basta che sia prima dei WIN. Posizione: nel blocco,
  dopo le 6 morti-da-risorsa e `mutiny_end`, prima di `win_escape`. Così una morte-da-risorsa
  simultanea (improbabile) ha la sua narrazione, ma l'equipaggio azzerato pre-empta ogni win.
- `lone_survivor` RESTA invariato (win per "vivo a fine corsa") — ma ora non scatta più con
  l'equipaggio morto perché `crew_lost` viene prima e termina la run al momento dell'azzeramento.

## Sez. 3 — Gate automatico speaker-vivo (#3)

Una regola sola nel `Selector` (l'eleggibilità degli eventi): un evento è **ineleggibile** se ha
uno `speaker` non-null E quel personaggio (per nome) NON è vivo. Implementazione:
- Nel punto dove il Selector filtra il pool per `requires`/cooldown, aggiungere un controllo:
  se `$event->speaker` è non-null, l'evento passa solo se esiste un character con
  `name === speaker && alive === true`.
- Eventi `speaker: null` (narratore, marcatori di fase, `death_notice`) NON sono mai esclusi da
  questa regola.
- **Eccezione scheduled:** gli eventi forzati (scheduled by key) bypassano già `requires`; il gate
  speaker-vivo deve applicarsi ANCHE a loro? Decisione: SÌ per coerenza (un marcatore scheduled
  con speaker morto sarebbe assurdo), MA i `death_notice`/marcatori sono `speaker: null` quindi
  non toccati; e un arc/thread scheduled il cui speaker è morto è esattamente ciò che vogliamo
  saltare. Quindi il gate si applica uniformemente. (Se questo dovesse lasciare un buco nel pool —
  improbabile, i filler sono `speaker: null` — il fallback last-resort del Selector regge.)
- Copre tutti i 40+ eventi con speaker, presenti e futuri, senza ri-tag.

La diagnosi conferma che reazioni (`ReactionDeriver`), targeting effetti (`EffectApplier`),
`has_role`/`trait_present`/`crew_hunger` GIÀ filtrano i morti — quindi questo gate chiude l'unico
buco rimasto (l'eleggibilità per speaker).

## Sez. 4 — Morte visibile (#2)

### 4a. Escalation fame con avvisi che nominano
- `config('game.hunger.spawn_bands')` oggi ha UNA banda (≥30→food_ration). Aggiungere bande
  multiple ai livelli alti (es. ≥60, ≥80) che spawnano un evento-avviso `hunger_warning` (nuovo,
  `speaker: null`) il cui testo nomina il membro più affamato ("Anna è scheletrica. Senza cibo,
  presto, non ce la farà.").
- Il bug attuale (banda singola, `hunger_band` non re-innescabile sopra band 1) si corregge con le
  bande multiple: ogni salita di banda re-innesca il proprio avviso. La logica `$newBand > old` in
  `applyHunger` già supporta più bande — basta popolarle in config + l'evento avviso.
- L'evento `hunger_warning` ha `speaker: null` (non rischia il gate #3) e nomina il personaggio via
  testo/effetto — preferibilmente generico ("qualcuno sta deperendo") O, se il motore espone il
  più affamato, nominato. Decisione in piano: testo generico è più semplice e robusto; nominare
  richiede di passare il nome al rendering. Preferenza: avviso che nomina, se fattibile a basso
  costo (il `death_notice` già legge il death_log, stesso pattern per il più affamato).

### 4b. Carta "In memoria" personalizzata (chi + come + quando)
- `death_notice` oggi è anonima e generica. Renderla personalizzata: legge l'ultima voce (o le
  voci del giorno) del `death_log` e compone "Anna. Giorno 19. La fame ha avuto la meglio."
  (causa→frase: starvation→"La fame ha avuto la meglio", event→"Una scelta è costata cara",
  expedition→"La spedizione non è tornata"). Riusa il `cause_phrases` dell'epilogo o un mapping
  dedicato.
- **Una per morte:** oggi DayProcessor appende UN solo `death_notice` e il consume-by-key collassa
  i duplicati. Fix: schedulare un `death_notice` per OGNI morte (es. con un indice/giorno univoco
  nella chiave schedulata, o una carta che cicla le morti non-ancora-mostrate del death_log). La
  carta marca quali voci ha già mostrato (flag o un puntatore) per non ripeterle.
  Decisione in piano: l'approccio più semplice robusto è una carta `death_notice` che, quando si
  presenta, mostra la PROSSIMA morte non ancora annunciata (un campo `announced` sulla voce del
  death_log, o un contatore), e si ri-schedula se ne restano altre.

### 4c. Frontend: indicatore fame per membro
- I dati ci sono (`hunger` per personaggio nel payload `RunController::present`). Aggiungere un
  indicatore visibile (barra/colore) per membro, così il giocatore vede la fame salire. Tocca il
  componente equipaggio del frontend.

## Sez. 5 — Testing

1. **living_crew (unit, ConditionEvaluator).** Conta i vivi; `{op:'==',value:0}` true solo con 0
   vivi; `>=`/`<` corretti. Aggiunto a CONDITION_KEYS (eventi che lo usano validano).
2. **Finale crew_lost (feature).** Tutti i character morti → `EndingService::check` setta
   `ending_key=crew_lost`, type lose, e pre-empta lone_survivor (anche a oxygen>0 day>25). Con ≥1
   vivo, crew_lost NON scatta. Scatta appena l'ultimo muore (anche prima del giorno 25).
3. **Gate speaker-vivo (feature, Selector).** Un evento con speaker='Anna' non è eleggibile se
   Anna è morta; lo è se viva; un evento speaker=null è sempre eleggibile a parità di requires.
   Il pool non va in stallo (filler speaker-null coprono).
4. **Escalation fame (feature).** Salire oltre le nuove bande re-innesca gli avvisi
   (`hunger_warning` schedulato); l'avviso ha speaker null (non auto-escluso dal gate #3).
5. **death_notice personalizzata (feature).** Nomina il caduto + causa dal death_log; N morti nello
   stesso giorno → N annunci (non collassati a uno).
6. **Frontend (vitest).** L'indicatore fame rende il valore per membro dal payload.
7. **Sim (200, --memory).** Nessuna regressione su win-rate/stalli; il finale crew_lost compare
   nelle run dove l'equipaggio muore (prima era lone_survivor). Distribuzione finali sana.
8. **Regressione:** suite intera verde (oggi 250) + i nuovi test.

> **Nota onestà:** i test provano che il finale è coerente, i morti spariscono dagli eventi, e la
> fame è annunciata; che l'esperienza-morte ora *abbia senso* lo confermi tu al playtest.

## Fuori scope

- Riscrittura ampia dei testi-morte oltre death_notice + hunger_warning.
- Un sistema di "ultime parole" per-personaggio alla morte (bello ma fetta a sé).
- Bilanciamento della fame stessa (daily_rise/starve_at) — qui solo la VISIBILITÀ, non la
  difficoltà; se il playtest dice che si muore troppo di fame, è una taratura successiva.

## Loose ends chiusi

- Il finale ora riflette l'esito reale (equipaggio perduto = sconfitta, non vittoria).
- I morti spariscono dal gioco nel momento in cui muoiono (eventi + run-end).
- La morte è un arco visibile (avvisi → annuncio personalizzato), non un fatto compiuto
  nell'epilogo.
