# Spacefall — La Fame (Hunger): Design

**Data:** 2026-06-07
**Obiettivo:** Trasformare il cibo da risorsa passiva in **pressione di sopravvivenza quotidiana** con scelte dure (razionare, triage su chi mangia, sacrificare un compagno), espressa attraverso il loop a carte esistente ("carte più profonde", non un gestionale). La fame è uno **stato ambientale sempre visibile**; le carte emergono solo ai momenti di pressione, mai come prompt quotidiano identico.

Questa è la **prima fetta** di una più ampia evoluzione survival. Si appoggia sul motore a eventi/DSL esistente e sul sistema "equipaggio vivo" già costruito (standing, reazioni, flag-testimone, crew_trust/ammutinamento).

---

## 1. Confine (cosa è dentro e cosa fuori)

**Dentro:**
- Stato di **fame per personaggio** (nuovo), visibile sugli avatar.
- Tick giornaliero della fame nel `DayProcessor`; morte per inedia lenta e visibile.
- Modello "marea": fame ambientale; le carte arrivano a **gradini di pressione**.
- Famiglie di carte: **opportunità** (fonti di cibo), **razionamento/triage**, **sacrificio**.
- Fonti di cibo legate agli **oggetti-cibo** (seedbank, rifle, drone, rations) con limiti/cooldown/rischi.
- Aggancio al sistema equipaggio vivo (standing, reazioni, flag-testimone, crew_trust).
- Oscillazioni (manna/contaminazione) nel dominio cibo.
- Bilanciamento tarato col simulatore.
- Frontend: visualizzazione della fame sugli avatar.
- Gancio fame → stress → crollo → ammutinamento (autosufficiente).

**Fuori (fette successive, spec dedicate):**
- Spedizioni (mandare un personaggio fuori, catene di ritorno).
- Oggetti-strumento su *tutte* le crisi (solo gli oggetti-cibo hanno hook qui).
- Archi narrativi degli oggetti.
- Gancio fame → resa in spedizione (aspetta la fetta Spedizioni).

---

## 2. Il modello: due livelli

**Cibo** (`food`, risorsa esistente, 0–100) = **la dispensa**, la scorta che spendi.

**Fame** (`hunger`, nuovo campo *per personaggio*, 0–100) = quanto è affamato ciascuno. 0 = sazio, 100 = morente di fame. È il volto della sopravvivenza, mostrato sugli avatar.

Relazione: ogni giorno la fame di ogni vivo **sale** di una quantità base. Mangiare (carte del pasto/opportunità) **spende `food`** e **abbassa `hunger`** dei sazi. Non mangiare preserva `food` ma lascia salire `hunger`.

Per evitare doppio conteggio, il consumo di `food` si sposta in gran parte sulle **decisioni** (i pasti) anziché sul drain passivo: il `daily` di `food` in `config/game.php` viene ridotto (valore tarato al §9), così la dispensa cala soprattutto quando *scegli* di sfamare.

### 2.1 Spirale della fame (morte lenta e visibile)
- `hunger` 0–39: normale.
- `hunger` 40–69: **affamato** — penalità di stress giornaliera (ha lo stomaco vuoto, è teso).
- `hunger` 70–99: **allo stremo** — stress pesante, debole; visibilmente smunto.
- `hunger` 100: **muore di inedia** (gestito nel `DayProcessor`, non un game-over a tradimento: il giocatore ha visto la spirale salire per giorni e poteva combatterla).

Sfamare bene qualcuno gli abbassa molto `hunger` (lo puoi tirare via dall'orlo) → la fame **non fa valanga incontrollabile**.

---

## 3. La marea: la fame è stato, non una carta ricorrente

Principio anti-ripetizione (il cardine del design): **nei giorni tranquilli non c'è nessuna carta del pasto.** La fame si gestisce con lo sguardo (avatar + barra cibo). Le carte emergono solo a **gradini di pressione**, e ogni gradino è una *famiglia* di carte diversa:

| Gradino | Stato | Famiglia di carte |
|---|---|---|
| **Sazi / cibo ok** | tutti `hunger` < 40 e `food` alto | nessuna carta (rara silent card "un pasto vero stasera") |
| **Cibo cala / occasione** | `food` in calo o un oggetto-fonte pronto | **Opportunità**: raccogli, caccia, drone, cache trovata |
| **Cibo basso, fame su** | `food` basso e qualcuno `hunger` ≥ 40 | **Razionamento/Triage**: mezza razione o *chi mangia* |
| **Critico** | qualcuno `hunger` ≥ 70 e `food` ~0 | **Sacrificio / crisi disperata** (rara, pesante) |

La cadenza si ottiene **interamente con i dati esistenti**: ogni carta-famiglia ha un `requires` sul gradino (soglie di `food`/`hunger`) e `weight_modifiers` che la rendono più probabile man mano che la pressione sale. Nessuna modifica al motore per la cadenza.

Si sale e si scende sulla scala in base al gioco: il buon giocatore resta in alto (occasioni), chi fallisce scivola verso triage e sacrificio. **La difficoltà la regola la scala, non un numero singolo.**

---

## 4. Il triage: "chi mangia" (cuore drammatico)

Quando il cibo non basta per tutti, la carta non chiede "quanto mangiano tutti" ma **chi**. Resta una scelta da **due tap** (mai un menù di allocazione — si preserva il ritmo Reigns):

> **Razioni insufficienti** — *non c'è abbastanza per tutti stanotte*
> - **Sfama [il più utile ora]** (es. l'ingegnere: ti serve a riparare) → quel personaggio `hunger`↓, gli altri restano affamati
> - **Sfama [il più fragile]** (es. il ferito/affamato) → scelta umana, ma chi lavora si indebolisce

Il "chi" è **selezionato dinamicamente** dallo stato (chi è più affamato, chi ha un ruolo critico, chi è più vicino al crollo), così la carta nomina persone diverse a ogni occorrenza. Le scelte usano selettori di personaggio già esistenti (`highest_stress`, by-name, e un nuovo `hungriest`, §7).

---

## 5. La macchina del cibo (oggetti-fonte)

Le fonti di cibo sono **opportunità guidate dagli oggetti**, ognuna con un limite che impedisce lo spam:

- **seedbank (orto)** — `Raccolgo dall'orto`: +cibo consistente, ma **cooldown lungo** (ricresce). La fonte rinnovabile-base.
- **rifle (fucile)** — `Vado a caccia`: +cibo, ma **rischio** (gamble: ferita/stress o nulla).
- **drone** — `Mando il drone a recuperare`: +cibo, ma **rischio di perdere il drone** (consume_item su esito sfortunato).
- **rations (razioni)** — `Apro le scorte d'emergenza`: +cibo **grosso e immediato**, ma **consuma l'oggetto** (una tantum).

Con un buon kit puoi reggere **senza atrocità** — il sacrificio è il prezzo del *fallimento*, non un binario. Senza oggetti-cibo, sopravvivi a fatica con razionamento/triage e prima o poi affronti il sacrificio. Questo mantiene la promessa "gli oggetti contano" *dentro* la fame.

---

## 6. Oscillazioni (rompere la monotonia)

Il cibo non è una discesa monotona: eventi di **manna** e **mazzata** creano picchi di sollievo e panico.
- **Manna**: una cache trovata (+cibo), un raccolto abbondante, un avanzo inatteso.
- **Mazzata**: cibo contaminato (riusa/estende `c_mold`), un topo nelle scorte, una perdita refrigerazione (−cibo o −oggetto).

Gated su stato (es. la mazzata morde di più quando sei già basso) per drammaticità.

---

## 7. Modifiche al motore (backend)

Minimali, in linea coi pattern esistenti.

### 7.1 Campo `hunger` per personaggio
- `RunFactory::roster()` inizializza `'hunger' => 0` su ogni membro.
- `RunState`/`applyTo` già passano `characters` opachi: nessuna modifica (il campo viaggia dentro l'array character).

### 7.2 Effetto: estendere `character` per `hunger`
`EffectApplier::applyCharacter()` già gestisce `stress`; estenderlo per gestire anche `hunger` (stesso clamp 0–100, stessi selettori, incluso `'all'`):
```php
['character' => 'all', 'hunger' => -40]        // sfama tutti
['character' => 'hungriest', 'hunger' => -50]  // sfama il più affamato
['character' => 'all', 'hunger' => 8]          // (uso interno: tick giornaliero)
```

### 7.3 Selettore `hungriest`
In `EffectApplier::resolveTarget()` aggiungere `'hungriest'` (massimo `hunger` tra i vivi), per il triage.

### 7.4 Condizione: fame dell'equipaggio
Nuova condizione per gating delle carte sui gradini, es.:
```php
['crew_hunger' => ['op' => '>=', 'value' => 40]]   // qualcuno ha hunger >= 40
```
Valutata da `ConditionEvaluator`: vero se **almeno un vivo** soddisfa il confronto sul proprio `hunger`. Aggiunta a `EventSchema::CONDITION_KEYS`.

### 7.5 DayProcessor: tappa fame
Nuova tappa nella pipeline giornaliera (dopo hardship, prima dell'avanzo giorno):
1. ogni vivo: `hunger += HUNGER_DAILY_RISE` (clamp 100).
2. penalità da fame: chi `hunger` ≥ soglia-affamato guadagna stress (config-driven, come `hardship`).
3. chi raggiunge `hunger` 100 **muore** (alive=false) con un flag-testimone (`died_of_hunger`) che alimenta morale/standing/ammutinamento.
Tutti i numeri (rise, soglie, stress) sono in `config/game.php` (sezione `hunger`), **tarati al §9**.

### 7.6 API: esporre `hunger`
`RunController::present()` aggiunge `hunger` alla mappa dei personaggi (come già per `stress`/`standing`).

### 7.7 Coesione con l'equipaggio vivo
Le scelte fredde sul cibo portano `tags`/`reactions` già supportati: triage contro qualcuno → reazione/standing negativo del trascurato; sacrificio → `kill` + crollo standing di tutti + `set_flag` testimone (`bex_saw_death`, ecc.) + `modify_trust` negativo (rischio ammutinamento via TrustEngine). Nessun nuovo sistema: riusa quello esistente.

---

## 8. Frontend

- **api.ts**: `Character` aggiunge `hunger: number`.
- **CrewPanel**: l'avatar mostra la fame come stato visibile — progressivo **desaturare/incavare** (filtro grayscale + leggero shift) man mano che `hunger` sale, e un piccolo indicatore (es. icona/contorno) alle soglie affamato/stremo. Mai un numero crudo in primo piano (coerente con stress/standing): la fame si *vede*.
- Le carte di fame usano i componenti esistenti (CardView): nessun nuovo schermo. Il triage è una normale carta a scelte.

---

## 9. Bilanciamento (i due paletti dell'utente)

Principi:
- **Non troppo distruttiva**: sfamare bene recupera davvero; saltare un pasto fa male ma non uccide subito; la spirale è lenta e visibile.
- **Non inutilmente facile**: fonti con limiti/cooldown/rischio; senza oggetti-cibo servono scelte dure; con oggetti reggi *scegliendo*, non in automatico.

Tuning **col simulatore esistente** (`Simulator`/`SimRun`, lo stesso di `BalanceTest`), non a numeri inventati. Obiettivi misurabili da raggiungere variando i valori in `config/game.php`:
- Una run "greedy" **senza** oggetti-cibo: sopravvive ~10–18 giorni di sola fame prima che il triage/sacrificio diventi inevitabile (non muore al giorno 3, non è gratis).
- Una run con una buona "macchina del cibo" (es. seedbank + 1 altra fonte): **può** sostenersi oltre i giorni-soglia delle vittorie **senza** atrocità, ma solo giocando bene.
- Il sacrificio compare in una minoranza di run (è un esito di fallimento, non la norma).
- `BalanceTest` resta verde: nessuna morte **da scelta** inevitabile (le morti per inedia sono da *stato accumulato visibile*, non da una singola scelta a trappola).

Un nuovo test di simulazione mirato (es. `HungerBalanceTest`) verifica le soglie sopra su un campione di seed.

---

## 10. Cosa NON facciamo (YAGNI)
- Niente simulazione nutrizionale/caloria per nutriente.
- Niente schermo gestionale o allocazione per-personaggio via UI (il triage è una scelta a due tap).
- Niente nuovo sistema di persistenza: `hunger` vive nell'array `characters`, i flag in `flags`.
- Niente spedizioni/archi-oggetto/oggetti-su-tutte-le-crisi (fette successive).

---

## 11. Criteri di successo
1. Giocando, la fame si **vede** sugli avatar e cresce nel tempo; nei giorni tranquilli **non** appare una carta del pasto.
2. Quando il cibo cala, emergono carte **diverse** per gradino (opportunità → razionamento/triage → sacrificio), non un'unica carta ripetuta.
3. Il triage nomina personaggi **diversi** secondo lo stato; sfamare qualcuno lo recupera visibilmente.
4. Una scelta fredda sul cibo produce una **reazione nominata** e muove lo standing; il sacrificio ha conseguenze pesanti e ricordate.
5. Con un buon kit-cibo si sopravvive senza sacrifici; senza, si finisce al triage/sacrificio — verificato dal simulatore.
6. `php artisan test` verde (incluso `BalanceTest` e il nuovo `HungerBalanceTest`); build TypeScript pulita.
