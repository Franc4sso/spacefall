# Island theme — multi-tema (spazio + isola)

**Data:** 2026-06-11
**Stato:** approvato (brainstorming), pronto per piano d'implementazione

## Obiettivo

Aggiungere un secondo tema giocabile — **isola** (ispirato a *LOST*: sei un sopravvissuto a
un disastro aereo) — allo stesso motore di Starfall Station, senza duplicare il motore.
Il gioco esistente diventa il tema **spazio**; il nuovo tema **isola** riusa l'intero motore
con dati diversi. L'utente sceglie il tema all'avvio di ogni nuova partita.

## Principio guida

Il motore è già **data-driven e tema-agnostico**: risorse, sistemi, item, finali sono chiavi-stringa
in `config('game.*')`, gli eventi sono righe DB. Nessun termine d'ambientazione ("oxygen", "hull") è
cablato nella logica. Quindi multi-tema = **rendere il tema un parametro esplicito** che seleziona
quale blocco-dati il motore usa. La logica del motore (selezione eventi, effetti, condizioni, finali,
epilogo, escape/rescue-chain, item-gating) resta **invariata**: riceve solo dati diversi.

Separazione lingua invariata: chiavi/flag/identificatori in **inglese**, testo di gioco in **italiano**.

## Decisioni (locked in brainstorming)

- **Multi-tema, stesso codice.** Un engine serve entrambi i temi.
- **Cartella per tema.** I dati di config vivono in `config/themes/{space,island}.php`.
- **Chiavi rinominate per tema.** Ogni tema definisce le proprie chiavi risorse/sistemi (l'engine resta key-agnostic).
- **Migrare spazio + nuovo isola.** Il contenuto spazio esistente viene spostato nel tema `space` (meccanico); `island` è contenuto nuovo. Entrambi giocabili.
- **Scelta tema all'avvio nuova run.** `Run.theme` fissato alla creazione.
- **Risoluzione config via `ThemeConfig` service esplicito** (non override globale in-memory), per sicurezza nel simulatore batch.
- **Identità isola = base pulita + catena salvataggio** (reskin del pattern escape-chain). Nessun nuovo sistema engine in v1.

## Architettura — tre seam

Il motore legge i dati da tre fonti. Ognuna diventa tema-aware:

### Seam 1 — Config (`config('game.X')` → `ThemeConfig`)

`config('game.X')` è letto in ~13 file engine/seeder. Diventa risoluzione tema-aware.

**Nuovo service** `app/Game/ThemeConfig.php`:

```php
final class ThemeConfig {
    public function for(string $theme): self;        // valida theme ∈ {space, island}, ritorna istanza legata al tema
    public function get(string $key, mixed $default = null): mixed;  // legge config("themes.{$theme}.{$key}")
}
```

**File config:**
- `config/themes/space.php` ← contenuto attuale di `config/game.php` (spostato 1:1, meccanico).
- `config/themes/island.php` ← nuovo, stessa struttura, chiavi e valori isola.
- `config/game.php` resta solo per costanti **veramente globali e non-tema**, se presenti
  (es. `items_pick` se identico fra temi); altrimenti viene rimosso. Da valutare per chiave in fase di piano.

**Refactor call-site** (~13), via TDD uno alla volta:
- Dove `$run` è in scope: `$themeConfig->for($run->theme)->get('X')`.
- Dove non c'è run (`AppServiceProvider`, `MetaController`): il tema arriva da parametro richiesta,
  oppure si itera su tutti i temi (es. endpoint meta che elenca item per la schermata di setup).

### Seam 2 — Eventi (righe DB) tema-scoped

**Migration su `events`:**
- `+ theme` (string, `default 'space'` per i dati esistenti).
- Drop `unique(key)`; add `unique(['theme','key'])`. Entrambi i temi possono riusare chiavi come
  `death_notice`, `recruit`, ecc.

**EventEngine** (4 punti: `Event::all()` a riga ~66, e tre `Event::where('key', ...)`):
ogni query filtra `->where('theme', $run->theme)`. Una riga ciascuna.

**Seeders:**
- I seeder esistenti (`ContentEventSeeder`, `EventSeeder`, `FillContentEventSeeder`) taggano tutti gli
  eventi `theme => 'space'` (migrazione contenuto esistente, meccanica).
- Nuovo `IslandEventSeeder` (con eventuali split per dimensione) per gli eventi isola.

### Seam 3 — Run carry theme + propagazione

**Migration su `runs`:** `+ theme` (string, `default 'space'`). Aggiungere a `$fillable`.

**Propagazione:**
- `RunFactory::create($seed, $items, $profile, string $theme = 'space')` — inizializza risorse,
  sistemi, item, roster dal `ThemeConfig->for($theme)`.
- `RunController::store` — valida `theme ∈ {space, island}` dal body, lo passa a `create`.
- `Simulator` — accetta `--theme=` (default `space`); `RunFactory::create` riceve il tema, così
  `sim:run` bilancia ogni tema separatamente.

**Frontend:** schermata "Nuova partita" → scelta **Spazio / Isola** → invia `theme` nel POST `/runs`.
L'endpoint meta che fornisce gli item selezionabili deve restituire gli item del tema scelto.

## Mappa contenuto isola

### Risorse (5 slot, mappati 1:1 sui correnti)

| space | island | daily | two-sided | note |
|---|---|---|---|---|
| oxygen | `water` | -3 | no | acqua dolce, scarseggia |
| food | `food` | -1 | no | caccia / raccolta |
| power | `fire` | -3 | no | il falò acceso adesso; lo ravvivi con legna |
| hull | `shelter` | -1 | no | riparo da giungla / tempeste |
| morale | `morale` | -2 | sì | invariato |

### Sistemi (efficienza [0,100], degradano e penalizzano una risorsa)

| space | island | penalizza |
|---|---|---|
| life_support | `water_still` | `water` |
| power_grid | `signal_fire` | `fire` |
| hull_integrity | `shelter_frame` | `shelter` |

**Nota fuoco (decisione di design):** `fire` (risorsa) = il falò che cala e va ravvivato.
`signal_fire` (sistema) = quanto sei visibile dall'esterno; alimenta la **catena salvataggio**.
Sono due cose distinte, non un doppione.

### Catena salvataggio (reskin di escape-chain)

Riusa il pattern `escapeArc` già rodato — flag-chain a stadi che culmina in un finale "soccorso".
Chiavi proprie del tema isola (non riusare le chiavi space `escape_*` / `win_escape`):

- Stadi flag: scoperta segnale → costruzione/alimentazione segnale → zattera/avvistamento → soccorso lanciato.
- Finale gated su flag terminale (equivalente isola di `escape_launched`).
- Witness flags + `*_outcome_lines` per l'epilogo "Come siete stati salvati", sullo stesso schema di
  `escape_outcome_lines`.

Dettaglio chiavi/stadi/testo definito in fase di seeding del tema isola (contenuto, non engine).

## Testing & bilanciamento

- **TDD** su: `ThemeConfig` (validazione tema + risoluzione chiave); ogni call-site convertito;
  event-scoping (run `island` non vede eventi `space` e viceversa); `RunFactory` per-tema
  (risorse/sistemi/item corretti dal tema).
- I **271 test esistenti** restano verdi → diventano implicitamente "tema space" (il default `'space'`
  preserva il comportamento).
- Bilanciamento isola via `php artisan sim:run --theme=island --count=5000 --policy=greedy_survival`,
  tarato modificando **solo dati** (`config/themes/island.php` + seeder isola), mai il motore.

## Out of scope (v1)

- Nuovi sistemi engine specifici dell'isola (giungla/esplorazione come sistema nuovo, mistero-meccanica):
  l'esplorazione esistente (`ExpeditionResolver`) si riusa via dati; identità extra rimandata.
- Sblocco isola via meta-progressione (la scelta è libera all'avvio, nessun gate).
- Più di due temi (l'architettura scala a N, ma v1 ne consegna due).

## Rischi / note

- **Unicità eventi:** dimenticare lo scope `(theme, key)` farebbe collidere chiavi condivise fra temi. Coperto da migration + test di scoping.
- **Call-site senza run in scope:** `AppServiceProvider`/`MetaController` vanno gestiti caso per caso in fase di piano (parametro richiesta vs iterazione su temi).
- **Default `'space'` ovunque** (colonne + firme metodo) è ciò che mantiene verdi i test esistenti: va applicato con cura.
