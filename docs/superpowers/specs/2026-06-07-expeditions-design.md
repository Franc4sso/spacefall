# Spacefall — Spedizioni (Expeditions): Design

**Data:** 2026-06-07
**Obiettivo:** Dare *agency*: mandi un membro dell'equipaggio fuori dalla stazione; resta **via** per N giorni; torna con bottino/notizie oppure non torna. Card-driven (mantiene il loop veloce), con un triage agonizzante sul *chi mando* le cui probabilità rispondono davvero a stato/attrezzatura/durata. Risorse e storia intrecciate.

Si appoggia sul motore esistente (eventi/DSL, equipaggio vivo, Fame, scheduling) e sul sistema "presente vs vivo" introdotto qui.

---

## 1. Confine (dentro / fuori)

**Dentro:**
- Loop: carta-opportunità → scegli chi mandare → stato "in spedizione" → evento di ritorno.
- Distinzione **presente vs vivo** (chi è via è vivo ma non al tavolo).
- Calcolo probabilità dell'esito (`ExpeditionResolver`): pericolosità meta + stato + attrezzatura + durata + tratti.
- Esiti del ritorno: bottino ricco / modesto / ferito / perduto / scoperta (recluta, indizio, minaccia).
- Aggancio Fame: chi è via è una bocca in meno; torna provato in proporzione ai giorni fuori.
- Equità: "Lascia perdere" sempre disponibile → nessuna morte inevitabile.
- API + UI (l'equipaggio in spedizione è mostrato come assente).

**Fuori (eventuali fette successive):**
- Più spedizioni simultanee (una alla volta — con 3 di equipaggio, mandarne 2 svuoterebbe la stazione).
- Bivio a metà spedizione ("sono in ritardo: aiuto o li dò per persi?") — tagliato.
- Mappa/destinazioni persistenti, gestione rotte.

---

## 2. Il loop

1. **Opportunità.** Periodicamente una carta-destinazione (settore sigillato / segnale di soccorso / relitto). Indica cosa *potrebbe* esserci e la distanza. Scelte: una per ogni membro **presente e vivo** ("Manda Anna / Manda Bex / Manda Cole") + **"Lascia perdere"**.
2. **Partenza.** La scelta "Manda X" porta `expedition` (campo a livello di scelta): l'`EventEngine` segna X come via per N giorni, **tira l'esito** (RNG seminata, rivelato solo al ritorno) e **schedula** l'evento di ritorno corrispondente.
3. **Mentre è via.** X è assente: non bersaglio degli eventi di stazione, non mangia alla mensa (bocca in meno), non conta per i ruoli. La stazione è a corto di mani.
4. **Ritorno.** Al giorno previsto scatta l'evento di ritorno (forzato dallo scheduling): narra l'esito, applica bottino/ferite/morte/recluta/minaccia all'`expeditioner`, lo riporta presente e gli infligge una **botta provata** scalata sui giorni fuori.

---

## 3. Presente vs Vivo (motore)

Distinzione netta:
- **Vivo** (`alive`): conta per vita/morte e finali ("equipaggio intero"). Chi è via è vivo.
- **Presente**: vivo **e** non in spedizione. Solo i presenti interagiscono con la stazione.

Nuovo campo `away_until` (giorno di ritorno) sul personaggio. È "via" finché `giorno_corrente < away_until`. Default assente → tutti presenti → **zero impatto sul gioco esistente** (default-safe).

Dove usare **presente** invece di vivo:
- `EffectApplier`: bersagli `all`/`random`/`hungriest` e i selettori → solo presenti (chi è via non viene colpito né nutrito al tavolo).
- `ConditionEvaluator`: `has_role`, `crew_hunger` → solo presenti (l'ingegnere in spedizione non ripara, non ha fame al tavolo).
- `DayProcessor`: la pipeline Fame (rise/stress/morte per inedia, spawn del pasto) → solo presenti.

Dove restare su **vivo**:
- Finali e accounting vita/morte (chi è via è ancora vivo).
- Il selettore `expeditioner` (vedi §5) trova il membro attualmente via.

Implementazione: un helper condiviso `isPresent($c, $day)` = `alive && (away_until ?? 0) <= day`. Le funzioni di targeting/hunger filtrano con questo invece del solo `alive`.

---

## 4. Probabilità (`ExpeditionResolver`)

Servizio puro (stile `ReactionDeriver`/`EpithetEngine`). Alla partenza calcola un **punteggio di rischio** e tira l'esito (deterministico data la RNG):

Ingressi:
- **Pericolosità della meta** (`danger` della carta-opportunità, es. 1–3).
- **Stato di chi parte** (alla partenza): stress + hunger alti → peggio.
- **Attrezzatura nello zaino**: presenza di `spacesuit`/`scanner`/`drone`/`medkit` → meglio (ognuno un bonus).
- **Durata** (`days`): più lunga → peggio.
- **Tratti come spezia**: `lucky` migliora, `reckless` peggiora.

Esiti (tier): `rich`, `modest`, `wounded`, `lost`, `discovery`. Il punteggio sposta i pesi dei tier (basso rischio → più `rich`/`discovery`; alto rischio → più `wounded`/`lost`). Il resolver restituisce il tier; l'`EventEngine` schedula l'evento di ritorno corrispondente (`exp_return_<tier>`).

Così "chi mando" è una decisione vera: fresco + sazio + equipaggiato = probabilità migliori, ma è chi ti serve di più a casa.

---

## 5. Effetti, selettore, e il ritorno

**Campo di scelta `expedition: {who, days, danger}`** (a livello di scelta, come `requires_item`/`tags`). L'`EventEngine.resolveChoice`, dopo gli effetti normali:
1. imposta `away_until = day + days` sul personaggio `who`;
2. salva i parametri necessari al ritorno in flag: `away_member = who`, `away_days = days`;
3. chiama `ExpeditionResolver` → tier;
4. schedula `exp_return_<tier>` per `day + days`.

**Selettore `expeditioner`** (in `EffectApplier::resolveTarget`): il membro il cui nome è in `flags['away_member']` (l'unico via). Usato dagli eventi di ritorno per colpire chi torna.

**Effetto `end_expedition`**: riporta presente l'`expeditioner` (azzera `away_until`), pulisce i flag `away_member`/`away_days`, e applica la **botta provata** scalata: `hunger += away_days * k`, `stress += away_days * j` (k/j tarati). Tutti gli eventi di ritorno lo includono (anche "lost", che in più fa `kill: expeditioner`).

**Eventi di ritorno (contenuto), uno per tier:**
- `exp_return_rich`: +cibo consistente + un oggetto (`grant_item`) o un indizio (`set_flag`); torna sano (botta provata lieve).
- `exp_return_modest`: +cibo modesto; torna provato.
- `exp_return_wounded`: poco/niente bottino; stress alto, ferita (effetto duraturo).
- `exp_return_lost`: `kill: expeditioner`; morale/standing/fiducia giù; flag-testimone. La sedia vuota è permanente.
- `exp_return_discovery`: `recruit` (un superstite) **oppure** un indizio che apre un filo **oppure** una minaccia (`spawn_event`). Intreccia risorsa e storia.

Ogni evento di ritorno ha `speaker = expeditioner` (per le reazioni/voce) ed effetti che usano `character: expeditioner`.

---

## 6. Aggancio Fame e equità

**Fame:**
- Mentre è via, X non è nella pipeline Fame → **una bocca in meno** alla mensa (sollievo immediato, bilancia il rischio).
- Al ritorno, la botta `hunger += away_days * k` lo riporta affamato in proporzione ai giorni fuori → si lega subito al triage del cibo (potrebbe servire sfamarlo d'urgenza).

**Equità (BalanceTest verde):**
- La carta-opportunità ha **sempre** "Lascia perdere" (nessun effetto letale) → esiste sempre una scelta che evita ogni rischio. La morte "lost" è un esito di un rischio **scelto**.
- Le scelte "Manda X" portano un **hint rischioso** ("molto pericoloso"), mentre "Lascia perdere" è neutro: così la policy cauta del simulatore (`GreedySurvivalPolicy`, sceglie per hint) preferisce **non mandare**. Le morti da spedizione sono quindi *opt-in* e non compaiono nelle run del simulatore → il `FairnessProbe` (che esamina solo le morti-da-scelta del greedy) non le segnala. La RandomPolicy invece le esercita, garantendo che il contenuto sia raggiungibile.
- Le spedizioni alimentano l'economia (cibo/oggetti/reclute) ma non sono mai obbligatorie per vincere.
- Le destinazioni compaiono come opportunità pesate (non forzate), con cooldown, così non saturano il loop.

---

## 7. API e Frontend

- **API**: `present()` aggiunge a ciascun personaggio `away_until` (o un booleano `away` derivato da `away_until > day`), così il client sa chi è fuori.
- **CrewPanel**: un membro in spedizione è mostrato come **assente** (avatar attenuato + etichetta «in spedizione · rientro g.N»), distinto da "morto". Niente barre stress/fame mentre è via.
- Le carte (opportunità e ritorno) usano i componenti esistenti (`CardView`): nessuno schermo nuovo.

---

## 8. Cosa NON facciamo (YAGNI)
- Niente spedizioni multiple simultanee, niente mappa/rotte persistenti, niente bivio a metà.
- Niente nuovo sistema di persistenza: `away_until` vive nell'array `characters`; i parametri di ritorno in `flags`.
- Niente UI gestionale: tutto è carte (opportunità + ritorno).

---

## 9. Criteri di successo
1. Una carta-opportunità lascia scegliere **chi** mandare (solo presenti) o lasciar perdere.
2. Chi parte sparisce dal tavolo: non viene colpito dagli eventi, non mangia, non conta per i ruoli — e la UI lo mostra «in spedizione».
3. Al giorno previsto il ritorno scatta **affidabilmente** (scheduling) e applica un esito coerente col rischio.
4. Le probabilità dell'esito rispondono in modo misurabile a stato/attrezzatura/durata/pericolosità (verificabile con un test del resolver).
5. Chi torna è più affamato/provato quanto più è stato fuori; mentre era via era una bocca in meno.
6. "Lascia perdere" è sempre disponibile; nessuna morte inevitabile (BalanceTest verde).
7. `php artisan test` verde (incluso un nuovo `ExpeditionTest`/`ExpeditionResolverTest`); build TypeScript pulita.
