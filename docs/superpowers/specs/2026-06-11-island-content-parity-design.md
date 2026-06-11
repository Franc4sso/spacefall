# Parità di contenuto isola — sottoprogetto 1 di 3

**Data:** 2026-06-11
**Stato:** approvato (brainstorming), pronto per piano d'implementazione
**Contesto:** primo di tre sottoprogetti per rendere l'isola il gioco principale. Gli altri due (grafica cinematic, gancio meccanico) avranno il loro ciclo spec→plan dopo.

## Obiettivo

Portare il tema **isola** alla **parità di sistemi narrativi** con il tema spazio: ogni sistema
narrativo che lo spazio possiede, l'isola lo avrà, **dimensionato al suo cast di 3 sopravvissuti**
(Nadia/Bruno/Carla) — non lo stesso numero di eventi. Risultato coerente e completo, senza
riempimenti forzati.

## Principio invariante

È **tutto contenuto** (seeder + config), **zero motore**. Verificato sui dati reali:
- Archi-oggetto: pattern `set_flag` + `spawn_event` + `requires` — identico alla catena soccorso già in produzione.
- Archi-coppia: usano condizioni dati esistenti — `['relationship' => ['a','b','state'=>'bond']]`, `['alive'=>'Nome']` — ed effetti `relationship`/`modify_standing`/`modify_trust` già supportati dal motore.
- Spedizioni: `ExpeditionResolver` è già tema-agnostico (`$this->theme->for($state->theme)->get('relationships.expedition_risk')`).

Separazione lingua invariata: chiavi/flag in **inglese**, testo di gioco in **italiano**.

## Stato di partenza (verificato sul DB reale, non sul report Explore che era errato)

Isola: 68 eventi seedati, giocabile, catena soccorso completa, vittoria "Soccorso" funzionante.
Spazio: 172 eventi. Gap per categoria (prefisso chiave):

| Sistema | Spazio | Isola ora | Target isola (cast 3) |
|---|---|---|---|
| Archi coppia (`pair_`) | 6 | 0 | 6 (3 coppie ×2 toni) |
| Reazioni incrociate (`cross_`) | 3 | 0 | 3 |
| Archi individuali | 12 | ~5 `survivor_*` | ~9 (3 per sopravvissuto) |
| Archi-oggetto (`arc_`) | 9 | 0 | 9 (3 oggetti ×3 stadi) |
| Spedizioni (`exp_`) | 7 | 0 | ~6 |
| Fill-atmosfera (`fc_`/`filler`) | ~22 | 4 | ~18 |
| Profondità per-fase (`iso_/det_/rec_`) | 21 | 5 | ~16 |

Stima: **~55-65 eventi nuovi** (parità di sistemi, non di numero).

## Decisioni (locked in brainstorming)

- **Parità di sistemi**, non di numero. Cast isola resta a 3.
- **Costruzione a strati per impatto**, non 7 sistemi in parti uguali: prima la voce dei personaggi, poi l'anima dell'isola, poi le relazioni, poi la profondità di sistema.
- **Spazio congelato**: non si tocca; l'isola è il focus. (Default-isola e nascondere lo spazio sono temi degli altri due sottoprogetti.)

## Architettura — strati di lavoro

Ogni strato è un blocco consegnabile e testabile, e corrisponde all'ordine in cui un giocatore
*sente* l'isola crescere. Tutti i nuovi eventi vivono in
`backend/database/seeders/IslandEventSeeder.php`, organizzati in metodi per strato, seguendo le
convenzioni esistenti del file (`ev()`, `one()`, `gamble()`). Ogni evento `theme => 'island'`.

### Strato 1 — La voce dei sopravvissuti (fondamenta)

Archi individuali a 3 beat per ciascuno dei 3 (~9 eventi), scritti per **caratterizzare** prima di
qualsiasi relazione. Assorbe i ~5 `survivor_*` generici esistenti dentro archi nominali.
- **Nadia** (engineer, genius): risolve, ma le sue soluzioni rischiano gli altri.
- **Bruno** (doctor, optimist): cura e tiene su il morale, ma nega quanto è grave.
- **Carla** (pilot, coward): cede sotto pressione; arco di possibile redenzione.

Metodo: `survivorVoiceArcs()`. **Output: 3 personaggi con una voce.**

### Strato 2 — L'anima dell'isola (dove l'isola è forte)

- **Fill-atmosfera (~18):** notti nella giungla, mare vuoto, suoni, piccoli gesti, l'impronta che
  non è tua. Basso impatto meccanico, alto colore. Metodo `atmosphereFillers()`.
- **Archi-oggetto (9):** catene a 3 stadi ancorate a oggetti isola, pattern `arc_seedbank`:
  - `radio` → soccorso alternativo (chiamare aiuto, batteria che muore, risposta o silenzio).
  - `seedbank` → orto (adatta l'arco seedbank dello spazio 1:1 all'isola).
  - `logbook` (diario del relitto) → semina del **mistero** (gancio leggero verso il futuro
    sottoprogetto "mistero"; nessun sistema mistero nuovo qui, solo eventi che lo evocano).
  Metodo `itemArcs()`.

**Output: l'isola ha atmosfera e oggetti che raccontano.**

### Strato 3 — Le relazioni (ora che i personaggi vivono)

Costruite **sopra** lo Strato 1, riferiscono le voci ormai stabilite.
- **Archi coppia (6):** 3 coppie (Nadia-Bruno, Nadia-Carla, Bruno-Carla) ×2 toni
  (tensione/unità). Gated su soglia relazione (`['relationship'=>['a','b','state'=>'bond']]`) e
  `alive`, come i `pair_*` dello spazio. Metodo `pairArcs()`.
- **Reazioni incrociate (3):** favorire sistematicamente uno fa reagire un altro; gated sui flag di
  favore accumulati. Metodo `crossReactions()`.

**Output: dinamiche di gruppo credibili.**

### Strato 4 — Profondità e respiro di sistema

- **Per-fase (~16):** eventi specifici di Naufragio (`iso_`), Deterioramento (`det_`), Resa dei
  conti (`rec_`). Metodo `phaseDepth()`.
- **Spedizioni (~6):** esplorazione **giungla** via `ExpeditionResolver` (già tema-agnostico).
  Metodo `expeditions()`. **Tarata con prudenza per il cast di 3:** perdere un sopravvissuto pesa
  più che nello spazio (resterebbero in 2) — soglie di rischio più gentili e spedizioni che
  feriscono più spesso di quanto uccidano. Verificare via sim che non svuotino le run.

**Output: profondità d'atto e l'esplorazione come rischio/ricompensa.**

## Config (`config/themes/island.php`)

Adattata **per strato**, non in blocco. Possibili aggiunte quando uno strato le richiede:
- Soglie relazione / eventuali `recruit_names` mancanti / frasi-morte aggiuntive.
- Righe epilogo (`witness_flags`, outcome lines) per i nuovi flag d'arco, così l'epilogo riconosce
  ciò che il giocatore ha vissuto.

## Testing

**Per ogni strato, quattro verifiche:**
1. **Esistenza:** gli eventi dello strato sono seedati `theme => 'island'`, le chiavi attese esistono.
2. **Raggiungibilità:** ogni catena/arco è raggiungibile — i flag che gate-ano gli stadi successivi
   sono settati da qualche scelta. Coperto dal guardiano flag esteso (sotto).
3. **Niente stallo:** `sim:run --theme=island --policy=greedy_survival` non stalla e mantiene la
   distribuzione sana (~30% win, morti distribuite su più risorse). **Un solo processo sim alla
   volta** (lanciare sim concorrenti sullo stesso `database.sqlite` lo corrompe).
4. **Niente regressioni:** i 309 test esistenti restano verdi; lo spazio non cambia.

**Guardiano flag (estensione necessaria):** `tests/Feature/FlagReachabilityTest.php` oggi scansiona
solo `themes.space.*` (hardcoded). Va **esteso a `themes.island.*`** (endings, epilogue witness_flags,
outcome lines) così che ogni flag d'arco isola referenziato sia anche scritto da un evento — evita i
flag orfani aspirazionali. Questa è l'unica modifica fuori dal seeder/config, ed è codice di test.

## Out of scope (questo sottoprogetto)

- **Grafica / frontend:** è il sottoprogetto 2 (grafica cinematic isola).
- **Gancio meccanico isola** (fuoco-segnale come tensione, giungla come sistema nuovo, sistema
  mistero vero): è il sottoprogetto 3. Qui il `logbook` *evoca* il mistero come contenuto, non
  introduce un sistema mistero.
- **Quarto sopravvissuto / default-isola / nascondere lo spazio:** non in questo blocco.
- **Pareggiare il conteggio esatto a 172:** esplicitamente no — parità di sistemi.

## Rischi / note

- **Spedizioni col cast di 3:** il rischio è run troppo fragili. Mitigazione: tarare via sim,
  soglie gentili, ferire>uccidere. Da validare prima di chiudere lo Strato 4.
- **Voce dei personaggi:** lo Strato 1 esiste apposta perché gli archi relazionali (Strato 3) su
  personaggi non caratterizzati sarebbero generici. Non invertire l'ordine.
- **`ExpeditionResolver` tema-agnostico:** verificato sì; se emergesse un cablaggio spazio residuo
  in fase di Strato 4, è un fix engine isolato e piccolo, da segnalare.
- **FlagReachabilityTest hardcoded su space:** estenderlo a island è prerequisito per la verifica di
  raggiungibilità di ogni strato.
