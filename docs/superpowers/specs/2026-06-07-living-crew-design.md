# Spacefall — Equipaggio Vivo: Design

**Data:** 2026-06-07
**Obiettivo:** Trasformare i tre personaggi (Anna, Bex, Cole) da comparse statiche a presenze vive, con fili narrativi ricorrenti, memoria parlata e reazioni visibili. Rendere le scelte dei dilemmi veri (mai giusto vs sbagliato). Far percepire al giocatore che l'equipaggio reagisce a ciò che fa.

Questo design estende il lavoro già completato (choice_log, condizioni `chosen`/`chosen_tag`, effetti `modify_trust`/`grant_item`/`consume_item`, TrustEngine, EpithetEngine, redesign UI cosmico). **Non** reintroduce sistemi già esistenti; li sfrutta.

---

## 1. Principio guida: Pressione & Fili (non "atti")

I personaggi non hanno archi prefissati a 3 atti. Accumulano **pressione** da fonti multiple, e quando la pressione è alta i loro eventi diventano *più probabili* (non garantiti) tramite i `weight_modifiers` già supportati dal motore. La varietà tra run nasce dal **volume** di piccoli eventi interconnessi e dalla loro selezione pesata, non da trigger rigidi.

Il "non schematico" si ottiene con quattro mosse:

1. **Memoria parlata** — quando un personaggio reagisce, *dice il perché*, nominando la causa: «Hai lasciato morire qualcuno nel settore 7. Non lo scordo.» Il callback è esplicito.
2. **Fili ricorrenti, non momenti isolati** — ogni personaggio ha un *filo* di eventi che si richiamano a vicenda ed escalano fino a un pagamento che nomina la causa.
3. **Intreccio** — i personaggi si commentano a vicenda. Bex reagisce a cosa ha fatto Anna. Il mondo sembra connesso.
4. **Standing leggibile** — il giocatore *vede* dove sta con ciascuno, tramite reazioni visibili e Diario, non un numero astratto.

### 1.1 Stato di relazione per personaggio

Introduciamo un valore di **standing** per ciascun membro del roster, conservato in `run.flags` con chiave `standing_<nome_lowercase>` (es. `standing_anna`), intero in `[-100, 100]`, default `0`.

- Non è un nuovo sistema di persistenza: riusa `flags` (già `array` cast su Run) e i pattern `set_flag`/condizioni `flag`.
- Viene mosso da un nuovo effetto `modify_standing` (vedi §4).
- Soglie leggibili: `≤ -40` = "ostile", `-39..-15` = "freddo", `-14..14` = "neutro", `15..39` = "fiducia", `≥ 40` = "legame".

Lo standing **non** è mostrato come numero. Determina il *tono* (quale ramo di hint/testo) e abilita/disabilita i volti dei fili.

### 1.2 Pressione (derivata, non memorizzata)

La "pressione" su un personaggio non è un nuovo campo: è una **combinazione** valutata al volo dal `ConditionEvaluator` dentro i `requires`/`weight_modifiers` di un evento, usando segnali già esistenti:

- stress del personaggio (`character` state, già tracciato);
- flag-testimone (`bex_saw_death`, `cole_caused_death`, `anna_overruled`, ecc., impostati da `set_flag`);
- come l'hai trattato (`standing_<nome>`);
- stato della stazione (resource/system);
- rapporti (`relationship`).

---

## 2. I tre fili

Ogni personaggio ha **4 volti**. Sono eventi separati, ciascuno gated da una combinazione *diversa* di pressione, e pesati con `weight_modifiers` perché diventino probabili (non certi) quando la pressione sale. Di norma **uno solo** emerge per run; i `cooldown_days` alti e i flag di "filo già speso" (`anna_thread_done`, ecc.) impediscono ripetizioni.

Ogni volto ha:
- un **innesco** (`requires`) — combinazione di pressione;
- **memoria parlata** nel `body` — nomina la causa;
- **esito ambiguo** — `gamble` (esiti pesati) e/o `spawn_event` (conseguenza differita);
- **reazioni** sugli avatar (vedi §3).

### 2.1 Anna (ingegnere, genius) — la competenza che non chiede permesso

- **Lo fa comunque** — sistemi che cedono + Anna sotto stress. Ha già iniziato una riparazione rischiosa quando apri la card; scegli solo *come reagire*, non *se*. (ribaltamento, uso parco)
- **Si spegne** — stress altissimo + l'hai scavalcata (`anna_overruled`) o sfruttata troppo. Smette di lavorare: imposta `anna_withdrawn`, che *rimuove la sua opzione tecnica* dagli eventi di sistema finché non la recuperi.
- **La scommessa** — scafo/energia critici + possiedi un oggetto tecnico (`welder`/`toolkit`/`fabricator`/`manual`). Propone di puntare tutto: consuma l'oggetto, oscillazione enorme (gamble).
- **Il salvataggio silenzioso** — standing alto + situazione non disperata. In una crisi tardiva salva un membro che sarebbe morto (previene un `kill` schedulato).

### 2.2 Bex (medico, optimist) — la coscienza, quella che conta il prezzo

- **La verità scomoda** — hai fatto scelte fredde (`chosen_tag: sacrifice_crew` o `il_freddo`) o hai nascosto qualcosa (`log_falsified`). Ti contesta davanti a tutti: `-morale`/`-crew_trust`, ma *prenderti la responsabilità* può ribaltarlo.
- **Il crollo** — stress altissimo + una morte avvenuta (`bex_saw_death`). Non riesce più a operare: imposta `bex_broken`, gli eventi medici perdono la sua opzione.
- **La diagnosi** — standing alto + possiedi `medkit` o `scanner`. Intercetta una malattia prima che esploda (annulla uno spawn negativo futuro).
- **Il suo sacrificio** — tardi + situazione disperata + standing alto. Rischia la vita per salvare qualcuno (gamble: può morire).

### 2.3 Cole (pilota, coward) — il sopravvissuto, un occhio sull'uscita

- **La via d'uscita** — metà partita. Trova una possibile fuga. Indagarla apre una rotta (`cole_found_exit`); ignorarla lo fa risentire (`standing_cole -`, `cole_resentful`).
- **La fuga** — stress alto + `cole_resentful` + esiste un oggetto di sopravvivenza (`spacesuit`/`reactor_cell`/`rations`). Prepara di nascosto la fuga. Scoperta = caos; oppure se ne va davvero (lo perdi + un oggetto via `consume_item`).
- **Il momento di coraggio** — standing alto + finale disperato. Manovra folle che cambia il finale (imposta `cole_heroics`, abilita un finale dedicato).
- **Il prezzo della paura** — la sua paura ha causato una morte (`cole_caused_death`). Evento di colpa che ne sblocca la redenzione o il crollo.

### 2.4 Intreccio

Almeno **3 eventi** in cui un personaggio commenta un altro, gated sui flag-testimone/standing:
- Bex reagisce se Anna ha agito da sola (`anna_acted_alone`).
- Cole accusa te se Bex ti ha contestato pubblicamente (`bex_confronted`).
- Anna difende o critica la tua gestione di Cole (`cole_resentful`).

---

## 3. Reazioni visibili (UI)

Far *vedere* che l'equipaggio reagisce, su due livelli leggibili a colpo d'occhio.

### 3.1 Reazioni sugli avatar

Dopo ogni scelta, gli avatar dell'equipaggio con un'opinione reagiscono:
- l'avatar **pulsa** in un colore: ciano = approva, rosso = rabbia, oro = complicato/ambiguo;
- compare una **riga breve** accanto all'avatar per ~3 secondi: «Non dovevi.» — Bex.

**Dati, non casuale.** Il backend, risolvendo una scelta, restituisce un array `reactions`:

```json
"reactions": [
  { "who": "Bex", "tone": "anger", "line": "Non dovevi farlo." }
]
```

Origine delle reazioni (in ordine di priorità):
1. **Esplicite** — l'outcome scelto può dichiarare `reactions` nel suo payload (autorate a mano per i momenti forti).
2. **Derivate** — se assenti, l'engine ne deriva di default dai tag/effetti dell'outcome:
   - tag `sacrifice_crew` / `il_freddo` → Bex `anger`;
   - tag `generous` / `honest` → Bex `approve`;
   - effetto che danneggia un sistema → Anna `anger` (se viva);
   - effetto `kill` → tutti i vivi `anger`, +`standing` negativo.

Le reazioni muovono anche lo `standing` del reagente (effetto collaterale leggibile: chi si arrabbia ti perde fiducia).

### 3.2 Il Diario

Pannello compatto (apribile da un'icona nell'header) che mostra le ultime scelte e la loro ricaduta, derivato dal `choice_log` già esposto dall'API:

> Giorno 7 — Hai tagliato le razioni dei più deboli. *Bex non era d'accordo.*

La riga di ricaduta viene dalla reazione registrata insieme alla scelta. Nessun nuovo endpoint: estende il `choice_log` con un campo opzionale `reaction_summary`.

---

## 4. Modifiche al motore (backend)

Minimali e in linea con i pattern esistenti.

### 4.1 Nuovo effetto `modify_standing`

```php
['modify_standing' => ['who' => 'Anna', 'delta' => -15]]
```
- Legge/scrive `flags["standing_anna"]`, clamp `[-100, 100]`.
- Aggiunto a `EffectApplier` e a `EventSchema::EFFECT_KEYS`.

### 4.2 Nuova condizione `standing`

```php
['standing' => ['who' => 'Cole', 'op' => '>=', 'value' => 40]]
```
- Valutata da `ConditionEvaluator` leggendo `flags["standing_cole"]` (default 0).
- Aggiunta a `EventSchema::CONDITION_KEYS`.

### 4.3 Reazioni nel payload di risoluzione

- `EventEngine::resolveChoice()` raccoglie le `reactions` (esplicite dall'outcome o derivate) e:
  - applica gli `modify_standing` impliciti;
  - allega `reactions` alla `resolution` restituita dall'API;
  - salva un `reaction_summary` sintetico nell'entry del `choice_log`.
- `EventSchema` riconosce `reactions` come campo opzionale dell'outcome (no break dei contenuti esistenti).

### 4.4 Volto "Si spegne"/"Crollo" come gate, non come hard-code

`anna_withdrawn`/`bex_broken` sono flag di run. Gli eventi tecnici/medici esistenti aggiungono una condizione `requires` alla *loro opzione di personaggio* (es. l'opzione "bypass di Anna" richiede `not flag anna_withdrawn`). Nessuna logica in codice: tutto nei dati.

### 4.5 Correzioni bug contenuti (punto 2 dell'utente)

- `doctor_exhausted`, `patient_lost`: "Marco" → riferimenti al **medico** (Bex) o frasi role-based senza nome inventato.
- `silent_window`: "Ayaka" → Anna/Bex/Cole o frase generica.
- `trap_hull_critical`: `eva_suit` → `spacesuit` (chiave reale), sia in `requires_item` sia in `has_item`.

---

## 5. Bivii in valute diverse (scelte ardue)

Una manciata di eventi-dilemma dove **entrambe le opzioni costano**, in valute non confrontabili, e almeno una ha un colpo di coda differito. Rispettano l'equità (nessuna morte istantanea inevitabile: i probe di `BalanceTest` devono restare verdi).

Esempi canonici da implementare:
- **L'ultima cella d'ossigeno** — salvare Anna (intrappolata) o un ferito che Bex sta curando. Due persone note, costi permanenti, standing opposti.
- **Il razionamento** — taglio equo (tutti più deboli, morale giù) vs taglio ai fragili (funzionale, ma rischio per un membro). Bex reagisce.
- **La trasmissione** — SOS (forse soccorso, ma riveli la posizione → spawn minaccia) vs dati di ricerca (senso della missione, ma nessun soccorso).
- **Chi tiene il pannello** — vai tu (rischi il comando) o mandi un membro (potrebbe non tornare, e tutti ti hanno visto scegliere).

Ogni opzione muove `standing`/`crew_trust` e lascia una reazione parlata.

---

## 6. Replayability

La varietà nasce da:
- **Loadout → quali fili sono possibili**: la Scommessa di Anna richiede un oggetto tecnico, la Diagnosi di Bex richiede medkit/scanner, la Fuga di Cole richiede un oggetto di sopravvivenza. Equipaggiamenti diversi sbloccano storie diverse.
- **Selezione pesata**: tra i volti "vivi" in una run, la RNG seminata sceglie — due run con stato simile mostrano volti diversi.
- **Intreccio dipendente dallo stato**: gli eventi di commento incrociato dipendono da cosa è successo, non da un copione.
- **Memoria di profilo**: i flag-testimone possono lasciare tracce cross-run (già supportato via `scope: profile`).

---

## 7. Cosa NON facciamo (YAGNI)

- Niente nuovo sistema di persistenza: standing e flag-testimone vivono in `flags`.
- Niente editor di dialoghi o sistema di localizzazione: testo inline italiano come già fatto.
- Niente IA/generazione: tutto autorato e deterministico.
- Niente refactor non correlati al di fuori dei tocchi necessari a engine/schema/UI.

---

## 8. Criteri di successo

1. Giocando 3 run con loadout diversi, i fili dei personaggi che emergono sono **diversi**.
2. Dopo una scelta "fredda", un avatar reagisce **visibilmente** e con una **riga nominata**.
3. Il Diario mostra cosa hai scelto e la ricaduta, leggibile.
4. Almeno 4 bivii in cui un tester non sa dire quale sia "la scelta giusta".
5. Nessun nome di personaggio fuori dal roster compare più.
6. `php artisan test` verde (inclusi `BalanceTest` e `ContentTest`); build TypeScript pulita.
