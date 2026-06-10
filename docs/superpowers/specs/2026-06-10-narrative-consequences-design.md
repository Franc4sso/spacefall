# Spec — Conseguenze narrative in-run (le scelte raccontano la run)

> Stato: design approvato 2026-06-10. Estende il "Sistema di conseguenze trasversale".
> Fonte design: brainstorming 2026-06-10 (questa sessione).
> Si innesta su, e NON duplica, la spec `2026-06-09-epilogues-and-deaths-design.md` (già implementata).

## Obiettivo del giocatore

"Vorrei che le scelte fatte durante la run la condizionino davvero, non solo togliendo o
aumentando le statistiche, ma facendo uscire carte che si sbloccano solo se fai determinate
cose. Vorrei che ogni run raccontasse una storia."

## Diagnosi (verificata sul codice, non assunta)

Il MOTORE per le conseguenze narrative **esiste già ed è testato**. `ConditionEvaluator`
supporta come gate: `flag`, `chosen` ("event_key:index"), `chosen_tag`, `not_chosen`,
`relationship`, `alive`, ecc. `EffectApplier` supporta `set_flag` (scrive un flag) e
`spawn_event` (`{key, in_days}` → accoda una carta futura in `scheduled_events` con
`fire_on_day = day + in_days`). Le scelte registrano tutto nel `choice_log`.

Lo **Strato finale (epiloghi)** è già costruito (spec 2026-06-09):
- `EpilogueComposer` (puro, data-driven) compone l'epilogo a sezioni leggendo
  `config('game.epilogue.witness_flags')` — un dizionario `flag => frase`. **Collegare un flag
  all'epilogo = aggiungere una riga di config, zero codice.**
- 11 flag-testimone sono già letti in epilogo: `cannibalism`, `ate_alone`,
  `made_the_sacrifice`, `sos_sent`, `mutiny_occurred`, `log_falsified`,
  `vented_the_technician`, `lost_on_expedition`, `arc_garden_bloomed`, `arc_rescue_answered`,
  `arc_truth_found`.
- Alcuni finali leggono già le azioni: `win_rescue`←`sos_sent`, `mutiny_end`←`mutiny_occurred`.
- `death_log` + `death_notice` (annuncio di morte in-run) già esistono.

**Il vero buco residuo:** le scelte pagano **solo alla fine** (epilogo). DURANTE la run il
mondo non reagisce — **non esiste una sola carta-eco in-run** che dica "tre giorni fa hai fatto
X, ecco la conseguenza". È questo che fa sentire la run come "una storia che si srotola" invece
di "un riassunto finale". La spec 2026-06-09 metteva esplicitamente le carte in-run e i finali
nuovi *fuori scope* ("fetta successiva"). Questa è quella fetta.

## Cosa NON si tocca (già fatto, verificato)

- `EpilogueComposer` e i suoi test (`EpilogueComposerTest`, `EpilogueTest`) — invariati. Si
  estendono solo i DATI (`witness_flags`), che i test reggono (asserzioni mirate, non conteggi).
- `death_log` / `death_notice` / finali legati esistenti — invariati.
- Il MOTORE (`ConditionEvaluator`, `EffectApplier`, scheduling) — invariato. Lavoro 100%
  contenuto + config.

## Principio di progetto

**Zero motore nuovo. Solo contenuto + config + un test.** File toccati:
`database/seeders/ContentEventSeeder.php` (carte-eco e archi), `config/game.php`
(`witness_flags`, eventuali finali nuovi), un nuovo test di raggiungibilità. I 263 test
esistenti restano la rete di sicurezza.

## I quattro strati

### Strato 1 — Carte-eco in-run (il cuore del lavoro)

Per i flag con peso morale vivo, una **carta-conseguenza** che si presenta **3–6 giorni dopo**
la scelta, accodata via `spawn_event` al momento della scelta originale, e gated su
`requires: {flag: X}` come seconda rete (cintura+bretelle: lo spawn la programma, il requires
garantisce che appaia solo se il flag è davvero acceso).

**Regola di scrittura (requisito, non opzionale):** ogni eco **richiama esplicitamente** la
scelta originale nel testo, così causa→effetto è leggibile. Non "una crisi di fiducia"
generico, ma "Tre giorni fa hai sigillato il portello con Vela ancora fuori. Stamattina la sua
cuccetta è ancora fatta." Senza questo richiamo l'eco è meccanica, non storia.

**Flag prioritari** (orfani residui NON già pagati altrove, con una scena ovvia):
- `knows_the_past` → un'eco-vantaggio: riconosci una trappola che uccise i predecessori e la
  eviti (paga il "ho letto i log" con un beneficio concreto, non solo una frase).
- `left_someone` → il rimorso torna: un secondo contatto radio, o un membro che chiede di
  quello che hai lasciato. (Nota: esiste già `c_left_someone_ghost`; verificare in fase di
  audit se basta o va rafforzato — non duplicare.)
- `log_falsified` → la verità riemerge: qualcuno trova il registro vero.
- Atti di Cole (`cole_heroics`, `cole_found_exit`, `cole_left`, `cole_resentful`,
  `cole_caused_death`) → vedi Strato 2 (confluiscono in un arco).
- Altri orfani con scena (`illness_caught`, `tended_crops`, `sensors_warned`, `made_promise`)
  → eco breve dove sensata; dove non c'è scena ovvia, pagano solo in epilogo (Strato 3).

Target: **~12–15 carte-eco**. Prevalentemente narrative (morale / relazioni / piccolo
vantaggio), swing risorse contenuti per non perturbare il bilanciamento.

### Strato 2 — Catene profonde, bilanciate per innesco

4 archi a tappe (tappa N+1 gated sul flag della tappa N), **bilanciati per frequenza
d'innesco** così il lavoro non finisce in contenuto che il playtest non incontra:

- **≥2 archi da scelte COMUNI** (si vedono in quasi ogni run):
  - *Cole, eroe o disertore*: i flag Cole confluiscono in un arco dove il rapporto con Cole ha
    un esito leggibile (alleato / risentito / perso), con effetti in spedizione/crisi.
  - *Il tecnico* — estende l'esistente `vented_the_technician → technician_ghost` con una terza
    tappa di resa dei conti. (Verificare la catena esistente prima di aggiungere.)
- **≤2 archi da stati RARI** (il loro valore è esistere solo nelle run estreme):
  - *La spirale della fame*: `ate_alone` → carta dove l'egoismo si normalizza → in crisi
    estrema sblocca `cannibalism` con peso → contribuisce all'epilogo / finale dedicato.
  - *Il segnale*: estende l'arco comms verso `sos_sent` e il possibile soccorso.

### Strato 3 — Estensione epiloghi (piccola, solo config)

- Aggiungere righe `witness_flags` in `config/game.php` per gli orfani residui che meritano una
  frase finale ma non una carta (`knows_the_past`, `left_someone`, `cole_*`, `made_promise`,
  `illness_caught`, …). Una riga ciascuno. Zero codice.
- **Opzionale (fase isolata):** 1–2 finali-interi nuovi in `config('game.endings')` dove un
  percorso lo merita davvero — es. "Il prezzo della fame" se `cannibalism` + `ate_alone`,
  ordinato PRIMA dei win generici ma DOPO i lose letali. Solo se il sim conferma che non
  cannibalizza la distribuzione finali e che `lone_survivor` resta il fallback garantito.

### Strato 4 — Test di raggiungibilità (l'invariante)

Un test che, caricato il contenuto seedato, verifica: **ogni flag scritto da un `set_flag`
(run-scope) è letto da almeno un `requires` di evento/choice OPPURE da un ending `when` OPPURE
da `epilogue.witness_flags`.** Una "scelta nel vuoto" diventa così un fallimento automatico,
per sempre — anche per il contenuto futuro.

- Esclusioni esplicite (whitelist nel test): flag di servizio non-narrativi (`expedition_active`,
  `__scheduled_only`, `__never`, `standing_*`, `crew_trust`) e flag profile-scope.
- Il test estrae i flag scritti scorrendo gli eventi seedati (effetti `set_flag`) e i flag letti
  scorrendo `requires` (ricorsivo su `all/any/not`), `config.endings[].when`, e
  `config.epilogue.witness_flags`.

## Coerenza & sicurezza

- **Morti:** ogni eco che nomina un personaggio usa il gate `alive` (commit c447dcc) o un
  `relationship` (che ora richiede entrambi vivi). Un'eco non evoca un morto.
- **Bilanciamento:** eco prevalentemente narrative; quelle con risorse passano dal `BalanceTest`
  e dal `sim:run`. `cooldown_days` alto e `base_weight` moderato sulle eco → arricchiscono senza
  inondare il mazzo.
- **No doppioni:** prima di scrivere un'eco/arco, verificare che non esista già una carta che
  legge quel flag (es. `c_left_someone_ghost`, `technician_ghost`). Rafforzare, non duplicare.

## Testing

1. **Raggiungibilità (Strato 4, il guardiano):** nessun flag run-scope scritto resta non letto
   (carta O ending O witness_flags), modulo whitelist. Vale anche per il contenuto futuro.
2. **Eco in-run (feature):** risolta la scelta che setta `flag X`, `scheduled_events` contiene
   la carta-eco con `fire_on_day` corretto; avanzando ai giorni, la carta-eco è eleggibile solo
   con `flag X` settato (e non senza). Per ≥3 eco rappresentative.
3. **Catene (feature):** per ogni arco, la tappa N+1 NON è eleggibile senza il flag della tappa
   N; lo è con. Il finale dell'arco ramifica sul flag d'attenzione (es. curato vs no).
4. **Epilogo esteso (unit):** per ogni nuovo `witness_flag`, flag settato → riga presente nella
   sezione "Le tue scelte"; assente se non settato. (Riusa il pattern di `EpilogueComposerTest`.)
5. **Finali nuovi, se presenti (feature + sim):** il finale dedicato scatta SOLO col percorso
   richiesto; `lone_survivor` resta fallback; `sim:run --memory` (≥200 run) mostra distribuzione
   finali sana (nessun collasso) e 0 stalli.
6. **Suite intera verde** (oggi 263 test) + TS pulito.

> **Nota onestà.** I test provano che le carte-eco SI SBLOCCANO dalle scelte giuste e che nessuna
> scelta cade nel vuoto. Che le eco *commuovano* e che il richiamo causa→effetto si *senta* lo
> dici tu al playtest — è una qualità di scrittura per-carta, non verificabile in automatico.
> Due confini onesti accettati consapevolmente: (a) le 2 catene da stati rari si vedranno
> raramente nel tuo playtest manuale (sono per le run estreme; le copre il sim/test); (b) la
> regola "richiamo esplicito" la garantisce la review umana carta per carta, non un test.

## Fuori scope

- Modifiche al motore (`ConditionEvaluator`, `EffectApplier`, scheduling) — non servono.
- Rifacimento dell'`EpilogueComposer` — già fatto; si estendono solo i dati.
- Frontend — questa fetta è interamente backend/contenuto. Le eco appaiono come normali carte.
- Meta-progressione cross-run (Tier 3 #6) e risveglio degli oggetti bloccati — fetta separata.
- Onboarding/polish sensoriale (Tier 4).

## Loose ends chiusi

- I flag-testimone orfani residui trovano finalmente un lettore (carta in-run e/o
  witness_flags).
- La run acquista un tessuto narrativo IN CORSO, non solo un riassunto finale.
- Il test di raggiungibilità impedisce che future scelte-nel-vuoto si reintroducano.
