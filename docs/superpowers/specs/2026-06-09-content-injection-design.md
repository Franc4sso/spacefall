# Spec — Iniezione di contenuto (primo lotto, ~20 carte)

> Stato: approvato 2026-06-09. Cura la ripetizione percepita ("sempre le stesse scelte").
> Fonte design: brainstorming 2026-06-09 (questa sessione).

## Obiettivo

Ridurre la ripetizione percepita aggiungendo ~20 nuove carte-evento curate che riempiono i
buchi tematici del gioco. È il PRIMO di due lotti: si gioca, si raccoglie il feedback del
playtest umano, poi un secondo lotto mirato. Volume scelto deliberatamente piccolo (20, non 40)
per privilegiare la qualità e poter pivotare dopo il playtest.

## Vincoli (in ordine di priorità)

### 1. Ogni bivio è un VERO dilemma a costo chiaro (vincolo #1, sopra tutto)

Richiesta esplicita dell'utente: niente decisioni facili. Regole concrete:

- **Nessuna opzione dominante.** Per ogni carta multi-opzione, non deve esistere una scelta che
  una persona razionale prenderebbe sempre. Se esiste, la carta è bocciata.
- **Ogni opzione paga un prezzo reale su un asse diverso.** Il giocatore sceglie QUALE prezzo
  pagare, non SE pagarlo. Assi: risorsa (oxygen/power/food/hull/morale) vs equipaggio
  (stress/hunger) vs relazione di coppia vs sistema vs rischio ritardato (spawn/flag).
- **Trade-off tra assi non confrontabili**, non "tanto vs poco". Buono: "−15 power OPPURE
  incrina Anna-Cole". Cattivo: "−15 power OPPURE −20 power".
- **Le opzioni 'sicure' hanno un costo reale**: consumano un oggetto, piantano un flag che morde
  dopo, o spawnano una conseguenza. Nessun outcome puramente positivo senza contropartita.
- **Costo chiaro, non incerto.** Decisione di design: i dilemmi sono EQUI e LEGGIBILI — il
  giocatore capisce cosa sacrifica. Niente incertezza nascosta/gamble in questo lotto (evita il
  "perdo senza colpa chiara" che sembra ingiusto). Eccezione: vedi le carte atmosfera sotto.
- **Limite del controllo automatico (onestà):** un test può garantire che *esista* un costo su
  ogni opzione, NON che il dilemma sia *interessante*. "−5 power vs −5 food" passa il test ma è
  piatto. Quella qualità dipende dalla scrittura e dalla review carta-per-carta (vedi Testing).

### 2. Gate larghi (cura anche il pool comune)

La ripetizione nasce dal pool comune stretto: oggi ~92 dei ~118 eventi sono gated, il pool
effettivo è ~30 eventi + 6 filler. Per curare davvero la ripetizione, le nuove carte usano il
**gate più largo che ha senso** — una soglia risorsa/sistema/giorno, o nessun gate — invece di
oggetti/flag rari. Così riempiono i buchi tematici E gonfiano il nucleo che si vede ogni run.

### 3. Diversità strutturale (anti-noia tra le carte stesse)

40 (o 20) carte tutte "un sistema si rompe, A o B" spostano la noia, non la curano. Quote minime
sul mix strutturale tra le ~20:
- **≥6** dilemmi a due assi (risorsa vs equipaggio/relazione);
- **≥3** trade-off a tre opzioni (tre prezzi diversi);
- **≥3** con costo ritardato (`spawn_event` o `set_flag` che morde più avanti);
- **≥3** che muovono una relazione di coppia (`relationship` effect);
- **≥3** che toccano fasi/sistemi diversi (gating `phase`/`system` vario).
(Le quote possono sovrapporsi: una carta può contare in più categorie.)

## Distribuzione (~20 carte)

| Categoria | N | Buco che riempie |
|-----------|---|------------------|
| Comms | 3 | 0 eventi comms dedicati oggi |
| Propulsione | 3 | 0 eventi propulsione dedicati (solo fuel-cascade) |
| Dilemmi morali | 3 | pochi dilemmi senza risposta giusta |
| Crisi sistema/risorsa nuove | 4 | gonfia il pool comune (gate larghi) |
| Atmosfera / silent | 3 | worldbuilding sottile (unica eccezione al "deve essere bivio") |
| Rationing / fame | 2 | solo 2 eventi rationing oggi |
| Hull | 2 | 1 evento hull oggi |

Le 3 carte **atmosfera/silent** sono a scelta singola (momenti narrativi, come i `silentEvents`
esistenti) — sono l'UNICA eccezione dichiarata al vincolo #1; tutto il resto è un vero bivio.

## Architettura

**Nuovo seeder dedicato** `database/seeders/FillContentEventSeeder.php`, registrato in
`DatabaseSeeder` accanto a `EventSeeder` e `ContentEventSeeder`. Motivo: `ContentEventSeeder` è
già grande (~112 eventi, ~30 metodi); il lotto nuovo va isolato per restare leggibile e
testabile separatamente.

- Stessi helper (copiati o riusati): `ev([...])`, `one(label,effects,log,hint?,requires?)`,
  `gamble(...)` (gamble usato con parsimonia — il lotto è "costo chiaro", non incerto).
- Stesso schema/validazione: ogni evento passa `EventSchema::validate` al seed.
- Chiavi inglesi; `title`/`body`/`log`/`label` in italiano; tono terso, seconda persona, cupo
  (coerente col corpus: frasi brevi, immagini concrete, niente prosa fiorita).
- Effetti/condizioni: solo vocabolario esistente (resource, character, damage_system,
  set_flag, spawn_event, relationship, modify_standing/trust, consume_item; gate resource/day/
  phase/phase_index/system/has_role/relationship).

## Testing

1. **Test "no free choice" (il guardiano del vincolo #1).** Per ogni evento del nuovo seeder
   con ≥2 choice (escluse le carte atmosfera a scelta singola): asserisce che OGNI choice abbia
   almeno un effetto-costo (un effetto che peggiora qualcosa — resource delta<0, character
   stress>0/hunger>0, damage_system, kill, consume_item, relationship delta<0, modify_trust<0,
   modify_standing<0, o uno spawn_event/set_flag di conseguenza). Una choice fatta di soli
   benefici fa fallire il test. Euristica, non prova di "interessante" — ma blocca la
   scelta-gratis.
2. **Test diversità strutturale.** Asserisce le quote minime della Sez. 3 (≥6/≥3/≥3/≥3/≥3)
   scansionando gli eventi del nuovo seeder.
3. **Test dati.** Conteggio per categoria ≈ distribuzione; ogni evento valido contro lo schema
   (riusa il guard di `ContentTest`); chiavi uniche.
4. **Selector non-stalla.** La suite esistente copre il pool allargato; confermare 0 stalli.
5. **Sim di bilanciamento** (`sim:run --memory`, 200 run, entrambi i loadout): win-rate resta
   ~30-40%, 0 stalli, nessuna spirale precoce. Le nuove crisi a costo reale non devono crollare
   la sopravvivenza.
6. **Review carta-per-carta (umana/agente):** un reviewer dedicato legge OGNI carta e boccia
   quelle con un'opzione dominante o un dilemma piatto ("una persona razionale sceglierebbe
   sempre la stessa? il bivio fa ragionare?"). Questo copre ciò che il test #1 non può.
7. **Regressione:** suite intera verde (oggi 218) + nuovi test.

> **Nota di validazione (onestà):** il sim prova che le carte girano e non rompono il
> bilanciamento; NON prova che "facciano ragionare" o divertano. Quella è la validazione del
> playtest umano. Su questa fetta, il giudizio finale è del giocatore.

## Fuori scope

- **Secondo lotto (~20 altre carte):** dopo il playtest del primo, mirato su ciò che il feedback
  rivela.
- **Archi narrativi oggetti** (Tier 2 #3) ed **epiloghi** (Tier 3 #5): fette separate.
- **Nuovi effetti/condizioni nel motore:** non servono; si usa il vocabolario esistente.
- **Incertezza nascosta/gamble pesanti:** esclusi per scelta (dilemmi a costo chiaro).

## Loose ends toccati

- Riempie i buchi tematici peggiori (comms/propulsione a 0 dedicati).
- Sfrutta i sistemi già costruiti (fasi, relazioni, oggetti) come moltiplicatori del contenuto.
