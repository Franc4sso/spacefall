# Spec — Vittorie meritate (la Fuga come catena, epilogo che racconta, fix "?")

> Stato: design approvato 2026-06-10. Nasce dal playtest: una win scattata al giorno 12
> senza scelte, epilogo che non spiega il COME, superstite "?" senza nome.
> Fonte: brainstorming 2026-06-10 (questa sessione). Si appoggia al motore flag/spawn già
> collaudato (vedi spec `2026-06-10-narrative-consequences-design.md`).

## Problema (verificato sul codice)

1. **`win_escape` è una vittoria-regalo.** `config/game.php` la gata su
   `all[has_item spacesuit, day>=12, power>=40]`. La tuta EVA è nella griglia iniziale; power≥40
   è banale a inizio run. Possiedi un oggetto, aspetti 12 giorni, vinci — nessuna scelta
   difficile. È il primo win nell'ordine → scatta sempre per primo ("le win sono sempre al
   giorno 12").
2. **L'epilogo non racconta COME hai vinto.** `EpilogueComposer` ha una sezione "Le tue scelte"
   che legge solo `epilogue.witness_flags`; se la vittoria non passava da scelte-testimone, è
   muta. I superstiti mostrano "Tira avanti" generico (la `survivorLine` differenzia solo su
   standing ≤-25 / ≥25 e stress ≥70).
3. **Il superstite "?".** L'effetto `recruit` (`EffectApplier.php:85`) aggiunge un personaggio
   con `role` ma SENZA `name`. Reclutando un sopravvissuto (`c_breakdown_recruit` "Vado a
   prenderlo"; evento 1619 "Falli entrare"), entra nel roster anonimo → l'epilogo
   (`EpilogueComposer.php:51`, `$name = $c['name'] ?? '?'`) stampa "?: vivo. Tira avanti.".

## Principio

Zero motore nuovo dove possibile: la catena della Fuga è contenuto (carte in seeder) + config
(ending). Due eccezioni mirate al motore, piccole e localizzate: il fix `recruit` (assegna un
nome) e la nuova sezione epilogo (metodo nel composer puro). I 271 test esistenti restano rete
di sicurezza, più il test-guardiano di raggiungibilità flag.

## Decisioni di design (prese in brainstorming)

- **Ampiezza:** UNA win verticale completa (la Fuga) come modello giocabile; le altre win
  generiche solo IRRIGIDITE (soglie + un flag-chiave), non ridisegnate. **Debito esplicito:**
  le altre win restano "numeriche" finché non avranno la loro catena in fette successive.
- **Posti sul modulo: 2 fissi.** Con 3-4 membri vivi, lasciare indietro qualcuno è garantito —
  il dilemma "chi resta" c'è sempre.
- **Epilogo: sezione separata "Come avete vinto"**, dopo l'Esito, con le tappe-chiave + giorni.

## I quattro pezzi

### 1. La Fuga come catena di scelte-chiave (contenuto + config)

La tuta EVA smette di essere "la vittoria": diventa il pre-requisito per uscire a lavorare sul
modulo. Nuova catena di carte (nel seeder, formato `$this->ev`/`$this->one` + `spawn_event` +
`set_flag`, come le catene esistenti), gated `has_item spacesuit`:

- **Tappa 1 — La scoperta.** Una carta rivela il modulo di fuga danneggiato. Scelta: impegnarsi
  a ripararlo (apre la catena, `set_flag escape_found`) o ignorarlo. Gate: `has_item spacesuit`,
  giorno minimo medio (es. ≥8) così non parte subito.
- **Tappa 2 — La riparazione (costosa, gated su escape_found).** Una o due tappe che chiedono
  un sacrificio reale di risorse (power/parti) e tempo. Completarle → `set_flag escape_repaired`.
  Scelta secca: spendere ora per la fuga futura, o tenere le risorse per sopravvivere oggi.
- **Tappa 3 — Il carburante (gated su escape_repaired).** Un sacrificio netto: svuoti una
  riserva o rischi una spedizione pericolosa. Successo → `set_flag escape_fueled`.
- **Tappa 4 — Chi parte (gated su escape_fueled).** Carta-decisione finale: il modulo ha 2
  posti, l'equipaggio vivo è di più. Scegli chi sale. Effetti: `set_flag escape_launched`,
  registra CHI parte e CHI resta (via flag per-personaggio, es. `left_behind_<nome>` o un campo
  letto dall'epilogo). Questo flag è il sigillo della vittoria.

**Nuovo `win_escape`:** `when = all[flag escape_launched is true, day>=15]`. (Niente più
has_item/power come gate primario: la catena LI ha già richiesti lungo il percorso.) Il giorno
minimo resta solo come anti-degenere. La vittoria scatta perché hai COMPLETATO la catena e
preso la decisione finale, non perché possiedi un oggetto.

### 2. L'epilogo racconta la vittoria (motore: nuova sezione nel composer puro)

`EpilogueComposer::compose` guadagna una sezione **"Come avete vinto"**, emessa solo quando la
run è una WIN con una catena tracciabile. Ricostruisce le tappe da dati già presenti:
`choice_log` (ha `{day, event_key, choice_label}`) e i flag della catena.

- La mappatura tappa→frase è DATA-DRIVEN in config (`game.epilogue.victory_beats`): un dizionario
  `flag_o_event_key => template`, dove il template può includere il giorno preso dal choice_log.
  Es. `escape_repaired => 'Hai rimesso in sesto il modulo, giorno {day}.'`,
  `escape_fueled => 'Hai bruciato le riserve per il carburante, giorno {day}.'`.
- La decisione finale "chi parte / chi resta" produce righe esplicite: *"Anna e Bex sono salite.
  Cole è rimasto."* (lette dai flag per-personaggio della tappa 4).
- La sezione è separata, dopo "Esito", prima di "Caduti". Vuota → omessa (come le altre sezioni).

Niente prosa generata a blocco unico: righe brevi e secche dai template, testabili.

### 3. Fix del superstite "?" (motore: piccolo, localizzato)

L'effetto `recruit` in `EffectApplier` assegna un nome al reclutato, pescato da una lista config
`game.recruit_names` (nomi-sopravvissuti, es. Vela, Renn, Mira, Sol...). Pesca deterministica
rispetto allo stato (per non rompere la riproducibilità seed): es. il primo nome non già in uso
nel roster, o indicizzato sul conteggio dei reclutati. Mai più un personaggio senza nome né in
epilogo né nelle carte.

### 4. Irrigidire le altre win (config, leggero)

Per togliere subito la facilità generale senza ridisegnare:
- `win_research`: alza `day>=22` → più alto, e mantieni `research_complete` (già un'azione).
- `cold_victory`, `crew_intact`: già `day>25`; aggiungi dove sensato un gate che provi
  un'azione chiave, o alza una soglia risorsa.
- `lone_survivor` (catch-all): resta il fallback garantito, ma `day>25` → alza leggermente
  (es. >28) così non è la via di fuga facile a fine corsa.
- `win_escape` è coperto dal pezzo 1 (non più qui).
> Vincolo: NESSUN win deve diventare irraggiungibile; `lone_survivor` resta sempre il fallback.

## Testing

1. **win_escape non più gratis (feature):** a parità di tuta+power, `win_escape` NON scatta
   senza `escape_launched`; scatta con `escape_launched` + day≥15.
2. **Catena raggiungibile (feature):** esiste una sequenza di scelte che porta da `escape_found`
   → `escape_repaired` → `escape_fueled` → `escape_launched` (le tappe N+1 gated sul flag N).
3. **recruit assegna un nome (feature/unit):** dopo un effetto `recruit`, il nuovo personaggio
   ha un `name` non vuoto e diverso da "?"; due reclutamenti → due nomi distinti.
4. **Epilogo "Come avete vinto" (unit):** dato un RunState con i flag della catena e un
   choice_log con i giorni, la sezione esiste, contiene le tappe nei giorni corretti, e le righe
   "chi parte/chi resta"; assente per una win senza catena (es. lone_survivor puro).
5. **Superstiti senza "?" (unit):** un roster che include un reclutato ora mostra il suo nome.
6. **Guardiano raggiungibilità flag:** resta VERDE (i nuovi flag escape_* sono letti dall'ending
   e/o dalle tappe successive e/o dall'epilogo).
7. **Sim (`sim:run --count=300 --memory`):** 0 stalli; `lone_survivor` ancora raggiungibile;
   win-rate complessivo si abbassa (voluto) ma NON a zero; nessun collasso anomalo. Riporta la
   distribuzione prima/dopo.
8. **Suite intera verde** (oggi 271) + TS pulito.

> **Nota onestà.** I test provano che la Fuga richiede la catena, che l'epilogo ricostruisce le
> tappe dai fatti, che i reclutati hanno un nome. Due confini accettati: (a) la qualità
> *narrativa* della sezione "Come avete vinto" (che sia storia, non log tecnico) la giudichi tu
> al playtest; (b) "irrigidire le altre win" è un cerotto provvisorio — restano win numeriche
> finché non avranno la loro catena in fette future.

## Fuori scope

- Ridisegnare le altre 8 win come catene (fette successive — debito dichiarato).
- Posti-modulo variabili (scelto: 2 fissi).
- Epilogo in prosa cucita a blocco unico (scelto: sezione a righe).
- Rifacimento del sistema di reclutamento oltre l'assegnazione del nome.

## Loose ends chiusi

- La vittoria più comune non è più un regalo: richiede una catena di scelte costose.
- L'epilogo spiega il COME della vittoria, non solo l'esito.
- Il bug "?" del reclutato anonimo è chiuso.
