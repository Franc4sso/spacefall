# Spec — Relazioni d'equipaggio nel contenuto

> Stato: approvato 2026-06-09. Slice Tier 2 #4 dal `docs/superpowers/TODO.md`.
> Fonte design: brainstorming 2026-06-09 (questa sessione).

## Obiettivo

Il sistema legame/tensione/odio tra membri dell'equipaggio esiste nel motore ma è
sottoutilizzato (7 eventi modificano relazioni, 4 le leggono, e solo come "esiste *una*
coppia in banda X"). Questa fetta lo attiva: coppie che si legano o si scontrano in modo
diverso ogni run, il giocatore che le influenza, e conseguenze visibili in crisi, spedizioni
e nell'output parlato. È la leva con più ritorno sulla **varietà per run** (oggi le run
divergono per fase ma non per dinamiche umane).

## Cosa esiste già (non va rifatto)

- Modello dati: `relationships: [{a, b, value}]` su `runs`, valore clamp [-100, 100].
  Inizializzato vuoto in `RunFactory` (tutte le coppie partono neutre).
- Bande (`ConditionEvaluator::relationshipBand`): hatred(<-40), tension(<-10), neutral,
  bond(>10), devotion(>40).
- Effetto `{relationship:{a, b, delta}}` (`EffectApplier::applyRelationship`): trova/crea la
  coppia, somma delta, clamp. Match coppia **simmetrico** via `samePair` (a/b ↔ b/a).
- Condizione `{relationship:{state}}` (`evaluateRelationship`): oggi controlla solo se *una
  qualsiasi* coppia è in quella banda.
- Crew: Anna (engineer/genius), Bex (doctor/optimist), Cole (pilot/coward). Coppie possibili:
  Anna-Bex, Anna-Cole, Bex-Cole.
- `EventSchema` accetta già `relationship` come effect e come condition key.

## Architettura

### 1. Predicato per-coppia (unica estensione al motore)

Estensione **retrocompatibile** di `ConditionEvaluator::evaluateRelationship`:
- `{relationship:{state:'hatred'}}` → comportamento any-pair attuale, INVARIATO.
- `{relationship:{a:'Anna', b:'Cole', state:'hatred'}}` → controlla SOLO quella coppia in
  quella banda.

**Requisito critico (simmetria):** quando `a`/`b` sono presenti, il match deve usare la
STESSA logica simmetrica di `EffectApplier::samePair` — `{a:Anna,b:Cole}` deve matchare una
relazione registrata come `{a:Cole,b:Anna}`. NON un confronto ingenuo `a==a && b==b`. Una
relazione registrata in un ordine ma interrogata nell'altro deve scattare comunque. Estrai/
riusa una helper di confronto simmetrico condivisa tra Applier e Evaluator (o duplica la
logica con un test che la pinna in entrambi gli ordini).

Se `state` è assente con a/b presenti: fuori scope (non serve). Se la coppia non esiste
ancora in `relationships`, vale come banda `neutral` (value 0).

`EventSchema`: i campi `a/b/state` sono dentro l'oggetto `relationship` già whitelistato; se
la validazione ispeziona le sotto-chiavi, aggiungere `a/b/state` ai campi ammessi.

### 2. Cosa muove le relazioni (3 leve)

Tutte usano l'effetto `{relationship:{a,b,delta}}` esistente.

**A) Scelte del giocatore (solo dati seeder).** Crisi con triage/sacrificio aggiungono
effetti relationship agli outcome: una scelta che favorisce un membro a scapito di un altro
sposta quella coppia (es. salvi Cole a scapito di Anna → `{a:'Anna',b:'Cole',delta:-8}`).
Applicato agli eventi dove una scelta mette un membro contro/accanto a un altro.

**B) Eventi di coppia dedicati (contenuto nuovo, ~6 = 2 per coppia).** Carte che mettono due
personaggi in relazione diretta; il giocatore arbitra; l'esito sposta QUELLA coppia (delta
±10-15). Due archetipi:
- *Attrito* (litigio su razionamento/colpa): avvicini o allontani.
- *Solidarietà* (uno copre/aiuta l'altro): rinforzi o incrini.
Alcuni eventi sono gated sulla banda esistente della coppia (es. un litigio appare solo se
quella coppia è già in `tension`/`hatred`), così le dinamiche **seguono** una storia e si
auto-alimentano invece di essere scollegate. Cooldown alti per evitare ripetizione.

**C) Deriva passiva — SOLO morte di un terzo (DayProcessor).** Quando un membro muore, i
superstiti reagiscono in modo DIVERGENTE (amplifica dove la coppia già sta andando, non
livella):
- coppia superstite già in bond/devotion → +3 (il lutto unisce);
- coppia già in tension/hatred → −3 (si incolpano);
- neutrale → invariata.
Un solo punto in `DayProcessor` (nuovo metodo `applyRelationshipDrift` o dentro il flusso di
morte), applicato UNA volta per morte, soglie/delta in `config/game.php`.

> Nota: lo "stress condiviso" come trigger è stato SCARTATO in design — spingerebbe tutte le
> coppie verso il basso in tarda partita (effetto-livella) riducendo la varietà invece di
> aumentarla. Solo la morte-di-un-terzo, che è motivata e divergente.

### 3. Dove contano (conseguenze)

**Crisi (gating + testo).** Eventi che leggono il predicato per-coppia: una crisi che
coinvolge due membri ha varianti gated — litigio se quella coppia è in hatred, collaborazione
fluida se in bond — con testo ed esiti diversi. Alcune crisi nuove + varianti su esistenti.

**Spedizioni (`ExpeditionResolver`, oggi le ignora).** Il resolver consulta la relazione tra
l'expeditioner e i membri che restano: mandare via uno di una coppia in hatred → piccolo
malus (tensione a bordo) su risultato/morale; coppia in bond → piccolo bonus. Delta piccoli,
config-driven. **Guardia:** senza relazioni rilevanti (tutte neutre) il comportamento resta
identico a oggi (zero regressione).

**Reazioni / Diario (visibilità — il feedback loop).** `ReactionDeriver` impara a guardare le
coppie: quando una scelta sposta una relazione, produce una reazione che riflette la dinamica
(il `who` corretto + un marcatore strutturale "relazione spostata"); il Diario annota le
svolte (legame nato, rottura). Senza questo le relazioni restano numeri invisibili.

## Fuori scope

- **Finali/epiloghi che leggono le relazioni** → dipendono dagli Epiloghi (Tier 3 #5, non
  ancora fatti). Quando li faremo, leggeranno le coppie. Questa fetta resta autonoma.
- **Semi iniziali variabili** (run che partono con relazioni non-neutre) → le run divergono
  *durante*, non dal via. Aggiungibile dopo.
- **Deriva passiva da stress condiviso** → scartata (effetto-livella).
- Modifiche al modello dati o nuove migration (il modello basta).

## Testing

1. **Predicato per-coppia (unit, ConditionEvaluator).**
   - `{relationship:{a:'Anna',b:'Cole',state:'hatred'}}` matcha solo quella coppia in quella
     banda;
   - **simmetria:** matcha sia con relazione registrata `{a:Anna,b:Cole}` sia `{a:Cole,b:Anna}`;
   - coppia diversa / banda diversa → false; coppia inesistente → vale neutral;
   - `{relationship:{state:'hatred'}}` (senza a/b) → any-pair invariato (retrocompat).
2. **Deriva su morte di un terzo (feature, DayProcessor).**
   - morte → coppia superstite in bond → +3; in tension → −3; neutrale invariata;
   - applicata una sola volta per morte; nessuna morte → nessun drift. Soglie/delta da config.
3. **Spedizioni (unit, ExpeditionResolver).**
   - expeditioner in hatred con un presente → risultato/morale peggiore della baseline
     neutrale; in bond → migliore; **tutte neutre → identico a oggi** (zero regressione).
4. **Reazioni/Diario (feature, ReactionDeriver) — asserire sul DATO STRUTTURALE, non sulla
   prosa.** Una scelta che sposta una coppia produce una reazione con `who` corretto e un
   campo/marcatore che indica la dinamica di relazione; il diario registra la svolta. NON
   asserire su stringhe di testo italiano esatte (fragile) — verificare il comportamento
   (la dinamica emerge nell'output) via il dato strutturato.
5. **Contenuto (data, stile ToolChoiceTest/PhaseContentTest).** I ~6 eventi di coppia esistono
   con l'effetto `relationship` sulle coppie attese e il gating per-coppia dove previsto;
   `ContentTest` (schema su tutti gli eventi) resta verde → valida i predicati `a/b/state`.
6. **Sim di bilanciamento (via `sim:run --memory`).** 200 run: le relazioni si muovono (non
   restano tutte neutre), win-rate resta 30-40%, 0 stalli, nessuna spirale; le spedizioni con
   malus relazione non crollano il risultato.
7. **Regressione:** suite intera verde (oggi 202) + nuovi test.

> **Nota sulla validazione (onestà):** il simulatore è un'AI greedy che non gioca *per* le
> relazioni — il sim verde prova che il sistema GIRA e non è rotto, non che sia *divertente*.
> La vera validazione di questa fetta è il playtest umano. Su questa fetta più che sulle altre,
> conviene provarla a mano.

## Loose ends toccati

- Attiva il sistema relazioni (prima ~95% costruito, ~5% usato).
- Prepara il terreno per gli Epiloghi (Tier 3 #5), che leggeranno le coppie.
- `ExpeditionResolver` guadagna consapevolezza delle relazioni (prima le ignorava del tutto).
