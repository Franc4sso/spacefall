# Spacefall Station — Redesign Completo
**Data:** 2026-06-06  
**Scope:** Redesign visivo + profondia narrativa + meccaniche di memoria

---

## 1. Visione

Un thriller spaziale a memoria lunga. Ogni scelta lascia traccia. I personaggi
ricordano, tradiscono, salvano. Gli oggetti sono chiavi, non bonus. La storia è un
albero vero, non una lista di eventi casuali. Il giocatore deve sentire il peso di
ogni decisione — spesso solo molto dopo averla presa.

---

## 2. Redesign Visivo (Frontend)

### 2.1 Palette — Notte Cosmica
| Token | Valore | Uso |
|---|---|---|
| `bg` | `#060b18` | Sfondo globale |
| `surface` | `#0d1b38` | Card, pannelli |
| `surface-hi` | `#122040` | Card hover, selected |
| `cyan` | `#00d4ff` | Accento primario, barre risorse |
| `orange` | `#ff8c42` | Accento secondario, warning |
| `red` | `#ff4757` | Pericolo, allarme |
| `text` | `#e8f4fd` | Testo principale |
| `text-dim` | `#5a7a9a` | Testo secondario |
| `gold` | `#ffd166` | Items, momenti speciali |

### 2.2 Tipografia
- Font principale: `Inter`, `system-ui` (leggibile, moderno)
- Font numeri/codici: monospace solo dove necessario
- Eliminare il tutto-maiuscolo criptico ovunque tranne header di sistema
- Gerarchia chiara: titolo carta 22-24px, corpo 15-16px, label 12px

### 2.3 Layout GameScreen — Dashboard Bilanciata
```
┌─────────────────────────────────────────────────────┐
│  [STARFALL STATION]              Giorno 12    [⚠]   │
├──────────────┬──────────────────────┬───────────────┤
│  RISORSE     │      CARTA           │  EQUIPAGGIO   │
│              │  ┌──────────────┐    │               │
│  ●Ossigeno   │  │ [zona art]   │    │  ◉ Ayaka      │
│  ████░░  72  │  │              │    │    ing. ██░  │
│              │  │ Titolo       │    │               │
│  ●Cibo       │  │              │    │  ◉ Marco      │
│  ███░░░  48  │  │ Corpo testo  │    │    med. █░░  │
│              │  │ narrativo    │    │               │
│  ●Energia    │  │ più grande   │    │  ✕ Lena       │
│  ██░░░░  30! │  │              │    │    persa      │
│              │  └──────────────┘    │               │
│              │                      │               │
│              │  [◄ Scelta A  ] [Scelta B ►]         │
├──────────────┴──────────────────────┴───────────────┤
│  INVENTARIO: [Kit Medico ✦] [Torcia] [Tuta EVA ✦]   │
└─────────────────────────────────────────────────────┘
```

### 2.4 Card Design
- Angoli arrotondati (16px), sfondo `surface` con bordo sottile
- Zona art superiore: gradiente CSS procedurale basato su tipo evento
  - Crisi: rosso/arancio scuro
  - Esplorazione: blu/ciano profondo  
  - Personaggio: colore legato al ruolo del personaggio
  - Silenzio: viola/indaco
- Speaker come badge colorato (non solo testo piccolo)
- Corpo testo ampio e leggibile, non compressa
- Scelte come pulsanti grandi e distinti (non mini-bottoni)
- Se la scelta richiede un item: mostra icona item + "Richiede: [nome]"

### 2.5 Inventory come pannello attivo
- Item con stella `✦` = item rilevante per la carta corrente (evidenziato)
- Hover su item mostra descrizione completa
- Quando un item sblocca una scelta, la scelta mostra l'icona item

### 2.6 Crew Panel
- Avatar circolare con iniziali colorate per ruolo
- Barra stress visiva (non solo numero)
- Stato "in crisi" (stress >85): bordo pulsante arancio/rosso
- Morto: avatar sbarrato, grigio, con epiteto ("sacrificata - giorno 8")

### 2.7 Effetti transizione
- Card entra con slide+fade dal basso (240ms)
- Risoluzione scelta: flash del colore degli effetti (cyan=positivo, red=negativo)
- Momenti drammatici (trap event, morte personaggio): breve schermata nera + testo

---

## 3. Sistema di Memoria delle Scelte (Backend)

### 3.1 Schema DB — Nuova colonna `choice_log`
```json
// runs.choice_log (JSON array)
[
  {
    "day": 4,
    "event_key": "hull_breach_warning",
    "choice_index": 1,
    "choice_label": "Ignora l'allarme",
    "tags": ["ignored_warning", "hull_risk"]
  }
]
```
Migration: aggiungere `choice_log` JSON nullable a `runs`.

### 3.2 Esposizione API
`RunState` include `choice_log` (ultimi 15 elementi). Il frontend non lo mostra
direttamente, ma è disponibile per debug e per eventuali UI future.

### 3.3 Condizioni su choice history
`ConditionEvaluator` già supporta condizioni su risorse/stato. Aggiungere:
```php
// In event conditions:
"requires_choice": "hull_breach_warning:1"   // evento X, scelta index 1
"requires_choice_tag": "ignored_warning"      // qualsiasi scelta con questo tag
"requires_not_choice": "hull_breach_warning:0" // NON aver fatto questa scelta
```

---

## 4. Meccaniche di Profondità Narrativa

### 4.1 Catene Domino
Un evento può schedulare un evento futuro:
```json
"triggers_after": { "event_key": "fuel_crisis_followup", "days": 5 }
```
L'evento `fuel_crisis_followup` appare esattamente 5 giorni dopo, cita
esplicitamente la causa, e presenta due opzioni entrambe costose.

### 4.2 Trap Events
Evento che si attiva dopo N scelte con tag negativi. Entrambe le opzioni hanno
conseguenze reali. Il testo nomina la causa:
> *"Hai ignorato tre allarmi. Ora il reattore sta cedendo e non c'è via d'uscita pulita."*
- Opzione A: salvi il reattore, perdi un personaggio  
- Opzione B: evacuate il settore, perdi 40% scafo permanente

### 4.3 Item come Chiavi Narrative
Ogni evento può avere scelte con `requires_item: "med_kit"`. Se il giocatore ha
quell'item, vede la scelta extra. Altrimenti no (e a volte non sa nemmeno cosa si
sta perdendo). L'item viene consumato o rimane, a seconda dell'evento.

### 4.4 Personaggi con Agenda Autonoma
Quando stress > 88: il personaggio ha una probabilità (20%) di prendere un'azione
autonoma nel giorno successivo. Appare come carta speciale:
> *"Ayaka, senza dirti nulla, ha riparato il condotto di ossigeno stanotte.
>  Ha perso 15 punti stress. Non sembra stare bene."*
Può essere positivo o negativo. Il giocatore non controlla.

### 4.5 Fiducia Nascosta (Hidden Trust)
Variabile `crew_trust` (0-100) non mostrata nel UI. Ogni scelta che ignora
l'equipaggio, sacrifica qualcuno, o mente la diminuisce. Quando scende sotto 20,
scatta un evento di ammutinamento. Il giocatore scopre che esisteva solo in quel
momento.

### 4.6 Informazioni Corrotte
Con morale < 25 o un personaggio speaker con stress > 80: 20% di probabilità che
il testo dell'hint di una scelta contenga informazioni sbagliate (l'effetto reale
è diverso da quello descritto). Il giocatore non ha segnali visivi — impara a
non fidarsi ciecamente quando le cose vanno male.

### 4.7 Identità del Comandante (Epiteti)
Il sistema traccia le "decisive patterns" del giocatore. Dopo 5+ scelte dello
stesso tipo, il giocatore guadagna un epiteto silenzioso che i personaggi usano:
- Sacrifica sempre risorse per morale → *"il Generoso"*
- Ignora i warning → *"l'Imprudente"*  
- Sacrifica personaggi per risorse → *"il Freddo"*
L'epiteto appare nelle carte dei personaggi e nell'ending.

### 4.8 Sistemi della Stazione
Tre sistemi con salute propria: **Propulsori**, **Vita Artificiale**, **Comunicazioni**.
Degradano con eventi specifici. Se un sistema muore, certe scelte spariscono per
sempre da carte future. Il giocatore vede lo stato nel footer.

### 4.9 Dilemmi Morali Puri
Alcune carte (rare, 1-2 per run) non hanno valore meccanico in nessuna direzione.
Solo "chi sei?". La scelta viene registrata nel choice_log e può essere citata
nell'ending.

### 4.10 Carte Silenzio
Rare carte senza scelta (1-2 per run), solo narrativa. Un momento atmosferico,
un personaggio che guarda fuori dal finestrino, un suono inspiegabile.
Non fermano il gioco — avanzano automaticamente dopo 4 secondi.

### 4.11 Memoria Inter-Run (Meta Profilo)
Il profilo già esiste. Aggiungere `epithet` e `notable_choices` (le 3 scelte
più significative dell'ultima run). Nella run successiva, eventi speciali le
citano:
> *"Si dice che il vecchio comandante abbia sacrificato il motore per salvare
>   l'equipaggio. Tu porteresti quella eredità?"*

### 4.12 Finali a Strati
L'ending non dipende solo da win/lose, ma dalla combinazione di:
- Chi è sopravvissuto
- L'epiteto guadagnato
- Scelte morali pure
- Sistemi rimasti attivi
→ 8-12 finali distinti con testo specifico, non solo 2.

### 4.13 Effetto Farfalla Mascherato
Almeno 5 eventi "innocui" che al momento sembrano triviali ma plantano tag nel
choice_log. 10+ giorni dopo, un evento riconosce esplicitamente quel tag e mostra
le conseguenze. Il collegamento deve essere sorprendente ma logico in retrospettiva.

---

## 5. Contenuto da Produrre

| Tipo | Quantità |
|---|---|
| Domino chains (evento + followup) | 8 catene |
| Trap events | 6 |
| Item-gated choices (nuove scelte su eventi esistenti) | 15 |
| Character-driven events (per personaggio) | 4 x 4 personaggi = 16 |
| Silent cards | 4 |
| Moral dilemma cards | 5 |
| Butterfly effect seeds + reveal | 5 coppie |
| Ending variants | 10 |
| Inter-run reference events | 6 |

---

## 6. Architettura Backend — Modifiche

### File da modificare
- `database/migrations/` → nuova migration `choice_log`, `crew_trust`, `systems`, `epithet`
- `app/Game/Engine/ConditionEvaluator.php` → aggiungere `requires_choice`, `requires_choice_tag`, `requires_item`
- `app/Game/Engine/EffectApplier.php` → aggiungere effetti `schedule_event`, `modify_trust`, `damage_system`, `consume_item`
- `app/Game/Engine/EventEngine.php` → logica agenda autonoma, carte silenzio, corrupted hints
- `app/Game/Engine/EndingService.php` → finali a strati basati su epiteto + sopravvissuti + sistemi
- `app/Game/Engine/ProfileSync.php` → sincronizzare epiteto e notable_choices nel profilo
- `app/Http/Controllers/RunController.php` → esporre choice_log, systems, crew_trust nell'API
- `database/seeders/ContentEventSeeder.php` → tutto il nuovo contenuto

### File da creare
- `app/Game/Engine/EpithetEngine.php` → calcola epiteto corrente dal choice_log
- `app/Game/Engine/TrustEngine.php` → gestisce crew_trust e mutiny trigger

---

## 7. Architettura Frontend — Modifiche

### File da modificare
- `src/api.ts` → aggiungere `choice_log`, `systems`, `crew_trust` ai tipi
- `src/useRun.ts` → nessuna modifica strutturale
- `src/index.css` → completo redesign palette + animazioni
- `src/components/GameScreen.tsx` → nuovo layout, sistemi nel footer
- `src/components/CardView.tsx` → zona art, item highlight, scelte grandi
- `src/components/ResourceBars.tsx` → icone, colori dinamici
- `src/components/CrewPanel.tsx` → avatar, stress bar, epiteti
- `src/components/Inventory.tsx` → highlight item rilevanti
- `src/components/StartScreen.tsx` → redesign con inter-run memory
- `src/components/GameOverScreen.tsx` → ending a strati

### File da creare
- `src/components/SystemsBar.tsx` → stato Propulsori/Vita/Comunicazioni
- `src/components/SilentCard.tsx` → carta narrativa senza scelta (auto-avanza)

---

## 8. Ordine di Implementazione

1. **Migration + schema** (choice_log, crew_trust, systems nel DB)
2. **ConditionEvaluator + EffectApplier** (nuove condizioni ed effetti)
3. **EpithetEngine + TrustEngine** (nuovi motori)
4. **EndingService** (finali a strati)
5. **EventEngine** (agenda autonoma, silent cards, corrupted hints)
6. **ProfileSync** (inter-run memory)
7. **ContentEventSeeder** (tutto il nuovo contenuto)
8. **API types aggiornati** (frontend types)
9. **CSS redesign** (palette cosmica)
10. **Componenti frontend** (da GameScreen verso l'esterno)
