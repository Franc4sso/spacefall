# Pool di eventi per Atto/Fase — Design

> Spec del 2026-06-11. Feature Tier 2 #2 da `docs/superpowers/TODO.md`.
> Rende "giorno 30 ≠ giorno 3" riempiendo le tre fasi con pool di eventi dedicati
> a posta crescente.

---

## 1. Problema

La struttura ad Atti esiste già e funziona (vedi memoria [[phase-act-engine-exists]]):
`PhaseResolver` calcola la fase da giorno + pressione-risorse + floor monotòno;
gli eventi possono auto-gatarsi con `requires:{phase:'X'}`; il `Selector` filtra
già. **Ma** solo ~8 eventi sono phase-gated: il grosso del pool è phase-agnostico,
quindi la run non *cambia carattere* col tempo. Giorno 30 pesca le stesse carte
del giorno 3.

## 2. Soluzione

Aggiungere **15 eventi nuovi** (5 per fase) a `ContentEventSeeder.php`, ciascuno
gated `requires:{phase:'X'}`. La differenza tra le fasi è la **posta crescente**:
la curva di tensione (costi, irreversibilità, speranza residua) sale con l'atto.

### 2.1 Niente engine nuovo

Tutto il meccanismo è già live (memoria [[phase-act-engine-exists]]):
`ConditionEvaluator` valuta `phase`; `Selector::isEligible()` filtra via
`requires`. Questa feature è **solo contenuto** — 15 eventi-dato nel seeder + un
test-guardiano. Coerente con [[narrative-consequences-engine-exists]].

### 2.2 Convenzioni esistenti da seguire

Gli 8 eventi phase-gated attuali usano prefissi `iso_` / `det_` / `rec_` e questo
tono (`iso_signal` "Un segnale", `det_chain_fault` "Un guasto tira l'altro",
`rec_unrecoverable` "Quello che non torna"). I 15 nuovi seguono gli stessi
prefissi e registro. Costruiti con gli helper del seeder (`$this->ev([...])`,
`$this->one(...)`, `$this->gamble(...)`).

## 3. Le tre fasi — identità per posta

| Fase | Prefisso | Da giorno | Carattere | Profilo costi |
|---|---|---|---|---|
| Isolamento | `iso_` | 1 | Speranza intatta, adattamento, primi screzi, scoperta. | Bassi, **reversibili**. |
| Deterioramento | `det_` | 10 | L'accumulo: guasti a catena, scorte agli sgoccioli, logorio psicologico. | Medio-alti, **strascichi** (flag/spawn futuri). |
| Resa dei conti | `rec_` | 21 | Decisioni irreversibili: chi salvare, cosa sacrificare, verità a galla. | Estremi, **definitivi** (morti, flag-finale). |

## 4. I 15 eventi

Numeri indicativi (leva di tuning, da tarare a playtest come fame/spedizioni).
Tutti `base_weight` ~6-10, `cooldown_days` ~5-8. Le scelte mostrate sono lo
scheletro; il testo finale (body/log, tono asciutto) si rifinisce in implementazione.

### Isolamento (`iso_`) — costi bassi, reversibili, speranza

1. **`iso_inventory`** — *Fare l'inventario.* Conti cosa è rimasto. Scelta:
   ottimizzare ora (piccolo guadagno risorsa) vs rimandare (nulla). Posta minima.
2. **`iso_first_friction`** — *Il primo screzio.* Due dell'equipaggio si pestano i
   piedi. Media (−2 morale) vs lascia correre (un personaggio +stress lieve).
   Reversibile.
3. **`iso_routine`** — *Una routine.* Imporre turni rigidi (+morale piccolo, un
   personaggio +stress) vs lasciare liberi (−morale piccolo, coesione). Tono: si
   prova a vivere, non solo sopravvivere.
4. **`iso_old_terminal`** — *Un terminale acceso.* Trovi un vecchio log della
   stazione. Leggerlo (set_flag `knows_the_past` se non già, piccolo −morale) vs
   spegnerlo (nulla). Aggancia il filo-lettura esistente.
5. **`iso_ration_habit`** — *Le prime razioni.* Stabilisci la norma alimentare.
   Generoso ora (+morale, −food) vs prudente (+food cuscinetto, −morale lieve).
   Sceglie la curva futura, costo basso.

### Deterioramento (`det_`) — accumulo, scorte agli sgoccioli, strascichi

6. **`det_compound_failure`** — *Tre cose insieme.* Più sistemi cedono nello stesso
   giorno. Tampona uno (scegli quale: power/hull/oxygen −medio, gli altri
   peggiorano) — non puoi salvarli tutti. Posta media-alta.
7. **`det_dwindling_stores`** — *Il fondo del magazzino.* Le scorte sono quasi
   finite. Razionare duro (stress all +, −food rallenta) vs aprire tutto adesso
   (+food ora, spawn futuro `det_*` di carenza). Strascico.
8. **`det_cracks_showing`** — *Qualcuno cede.* Logorio psicologico su un
   personaggio (highest_stress). Fermarsi a sostenerlo (−tempo/risorsa, stress −)
   vs spingere oltre (stress ++, rischio rottura futura via spawn).
9. **`det_rumor`** — *Una voce.* Inizia a girare un sospetto/risentimento.
   Affrontarlo apertamente (modify_trust ±, −morale ora) vs ignorarlo (set_flag
   tensione, spawn possibile escalation in `rec_`). Strascico verso l'atto finale.
10. **`det_make_do`** — *Arrangiarsi.* Una riparazione di fortuna. Improvvisare
    (gamble: regge / peggiora, posta media) vs non toccare (degrado lento certo).

### Resa dei conti (`rec_`) — irreversibile, estremo, definitivo

11. **`rec_who_eats`** — *Chi mangia.* Non c'è cibo per tutti. Scelta dura su chi
    privare (un personaggio rischia, +flag) — niente via indolore. Estremo.
12. **`rec_the_truth`** — *La verità.* Un segreto della run viene a galla (gated o
    no su flag esistenti tipo `knows_the_past`/`ate_alone`). Confessare
    (morale −, trust ±, flag-finale) vs seppellire (peso che resta). Definitivo.
13. **`rec_last_repair`** — *L'ultima riparazione.* Una scelta tecnica decisiva che
    indirizza il finale: tieni in vita la stazione (costo estremo risorsa) vs
    accetta la fine di un sistema (perdita irreversibile). Posta massima.
14. **`rec_who_stays`** — *Chi resta.* Eco/variante del sacrificio: qualcuno deve
    restare indietro. Set_flag decisivo, possibile `kill`. Irreversibile.
15. **`rec_reckoning_vote`** — *La conta.* L'equipaggio ti chiede dove state
    andando (resistere / arrendersi / tentare il tutto-per-tutto). Indirizza il
    tono del finale via flag, nessun esito gratuito.

> Nota su `rec_` con `kill`/flag-finale: verificare gli effetti reali disponibili
> (`kill`, `set_flag`) e i flag già letti da finali/epilogo prima di scriverli, per
> non creare flag-orfani (il guardiano `FlagReachabilityTest` esiste e va
> rispettato — vedi §6).

## 5. Bilanciamento — la curva di posta

Regola trasversale, da tarare a playtest:

- **Isolamento:** delta-risorsa piccoli (≈ ±2…±6), nessun `kill`, nessun
  flag-finale, esiti reversibili. Si può sbagliare e recuperare.
- **Deterioramento:** delta medi (≈ ±6…±14), strascichi via `spawn_event`/flag,
  gambo (gamble) ammesso. Gli errori iniziano a costare nel futuro.
- **Resa dei conti:** delta estremi, `kill`/flag-finale ammessi, esiti definitivi.
  Le scelte chiudono porte.

I numeri esatti per evento si fissano in fase di piano leggendo eventi simili
esistenti, e si tarano col simulatore/playtest.

## 6. Confini e non-obiettivi

- **NO modifiche all'engine.** PhaseResolver/ConditionEvaluator/Selector già fanno
  tutto. Se serve toccarli, fermarsi: assunzione sbagliata.
- **NO modifiche alle soglie di fase** (`day_bands`, pressure-band in `game.php`).
  Fuori scope; sono già tarate.
- **NO nuovi flag orfani.** Ogni `set_flag` introdotto dev'essere letto da una
  carta/finale/epilogo, altrimenti `FlagReachabilityTest` fallisce. Riusare i flag
  esistenti dove possibile (`knows_the_past`, `ate_alone`, ecc.).
- **NO ritocco al pool base** (non-phase-gated). I 15 nuovi si aggiungono; non si
  riassegnano gli esistenti (quello era l'approccio "scaglione", scartato).
- **15 eventi, non di più.** 5 per fase. Espandibile in futuro, non ora (YAGNI).

## 7. Testing

Pattern dei test esistenti (asserzioni seed-time + un caso end-to-end):

1. **Presenza e gating** — i 15 nuovi key esistono, ognuno ha `requires.phase`
   col valore atteso coerente col prefisso (`iso_*`→isolation, `det_*`→
   deterioration, `rec_*`→reckoning). Test data-driven su una mappa key→phase.
2. **Conteggio per fase** — esattamente 5 nuovi eventi per fase (oltre agli 8
   preesistenti). Guardia contro dimenticanze/duplicati.
3. **Posta crescente (smoke)** — nessun evento `iso_*` contiene `kill` o un
   flag-finale; almeno un `rec_*` ha un esito definitivo (`kill` o flag-finale).
   Asserzione strutturale leggera, non bilanciamento fine.
4. **Spawn end-to-end** — con la fase forzata a `deterioration`, il `Selector`
   include almeno un `det_*` tra gli eligibili e nessun `iso_*`/`rec_*` esclusivo
   (un caso rappresentativo, riusando il modo in cui gli altri Feature test
   costruiscono lo stato e forzano la fase — vedi `PhaseResolver`/`RunState`).
5. **FlagReachability** — la suite esistente `FlagReachabilityTest` resta verde
   (nessun flag-orfano introdotto dai `rec_*`).

Suite intera (`php artisan test`) verde + `tsc --noEmit` pulito a fine lavoro.

## 8. Stima

~6-8 task: 3 task-contenuto (un blocco di 5 eventi per fase) → test
presenza/conteggio/posta → test spawn end-to-end → verifica flag-reachability →
taratura numeri → suite. Tutto in `ContentEventSeeder.php` e nei test.
