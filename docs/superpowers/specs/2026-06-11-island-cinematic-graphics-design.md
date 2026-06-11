# Grafica cinematic isola — sotto-progetto 2 di 3

**Data:** 2026-06-11
**Stato:** approvato (brainstorming), pronto per piano d'implementazione
**Contesto:** secondo dei tre sotto-progetti per rendere l'isola il gioco principale (1° = parità contenuto, FATTO; 3° = gancio meccanico, da fare). Questo dà all'isola la sua identità VISIVA: cinematic, illustrata, calda — dove lo spazio è freddo/CRT.

## Obiettivo

Dare al tema **isola** un look cinematic e illustrato — palette dorato+giungla, carte full-bleed con illustrazioni AI, HUD minimale "arte regina" — **senza toccare il look dello spazio**, che resta col suo tema CRT/cyan. Il frontend diventa tematico come lo è già il backend.

## Decisioni (locked in brainstorming)

- **Tema visivo scoped via `data-theme`.** Lo space è il default e non cambia; l'isola vive sotto `.theme-island`.
- **Palette dorato + giungla.** Caldo dominante (sabbia/ambra/tramonto) + verde-giungla per profondità. Mare verde-azzurro, rosso-terra per il pericolo.
- **Carta full-bleed, arte regina.** L'illustrazione È la carta; testo+scelte galleggiano in basso su un velo scuro. HUD minimale intorno (risorse sopra, crew+sistemi sotto).
- **Layout via ramo esplicito nel componente** (non CSS acrobatico): `CardView` rende full-bleed per island, art-top per space. Due layout leggibili.
- **Illustrazioni per beat-chiave (~25 img).** 6 categorie base + ~19 momenti epici. File statici + mappa `islandArt(key)`.
- **Style-guide dettagliata + ~25 prompt pronti** nel piano — il cuore della qualità (coerenza tra le immagini).
- **Fallback graceful:** asset mancante → gradiente di categoria. Il gioco non si rompe mai per un'immagine assente.

Separazione lingua invariata: chiavi/identificatori inglese, testo italiano.

## Architettura

### Tema visivo scoped

Il frontend ha la palette space cablata in `@theme` (`frontend/src/index.css`, ~287 righe): `--color-cyan`, sfondi navy, 6 classi gradiente `card-art-*`. Il cinematic isola **affianca** lo space, scoped.

**Meccanismo:** il container radice della UI di gioco riceve `data-theme="island"` quando `run.theme === 'island'` (default assente = space). Tutto il CSS island vive sotto `[data-theme="island"]` / `.theme-island`. Una sola sorgente di verità: `run.theme` → attributo → CSS giusto.

```css
[data-theme="island"] {
  --color-bg: #1a2520;     /* verde-notte */
  --color-accent: #c98a3a; /* ambra (sostituisce cyan come accent) */
  --color-sea: #3a7a6a; --color-sand: #e8b85a; --color-danger: #d4503a;
  /* ... risorse, speaker tag, ecc. */
}
/* lo space resta com'è: nessuna regola modificata */
```

### Prerequisito backend: esporre `run.theme`

`RunController::present()` NON include `theme` nel payload, e il tipo `RunState` (frontend) non ha `theme`. Senza, il frontend non sa il tema di una run in corso (es. dopo reload/`fetchRun`). **Aggiungere `'theme' => $run->theme` a `present()`** e `theme: string` al tipo `RunState`. È l'unica modifica backend (una riga + un campo tipo).

### CardView: ramo full-bleed

`frontend/src/components/CardView.tsx` oggi ha la zona-arte + swipe + layout art-top. Aggiungere un ramo:
- **island → full-bleed:** illustrazione di sfondo a piena carta (`background-image` da `islandArt(card.key)`), velo `linear-gradient(0deg,#000e,transparent)` in basso, su cui galleggiano titolo + body + scelte (posizionati absolute). Speaker tag in alto, ambra.
- **space → art-top:** layout attuale invariato.
- **Swipe invariato** per entrambi (meccanica Reigns: trascini la carta).
Il ramo è esplicito e leggibile (no `position` condizionali CSS-driven).

### islandArt: la mappa

Nuovo `frontend/src/islandArt.ts`:
```ts
export function islandArt(key: string): string | null  // ritorna path o null (→ fallback gradiente)
```
Logica:
1. **Override beat-chiave** (controllati per primi): mappa `{ 'rescue_4_launch': 'art/island/lifeboat.webp', ... }` per i momenti epici.
2. **Fallback categoria:** regex su key con keyword ISLAND (`rescue|signal|sea`→soccorso; `jungle|exp_|giungla`→esplorazione; `crisis|storm|fire|water_crisis`→crisi; nadia|bruno|carla|survivor→personaggio; filler|night|sea→silenzio; dilemma|pair|cross→dilemma) → illustrazione base della categoria.
3. **Sconosciuta** → `null` → `CardView` usa il gradiente `card-art-<cat>` island come sfondo.

### HUD arte-regina (GameScreen)

`GameScreen.tsx` già compone `ResourceBars`, `CrewPanel`, `SystemsBar`, `CardView`. Per island, lo scoped CSS + minimi aggiustamenti di disposizione danno il layout "arte regina":
- **Sopra la carta:** giorno + `ResourceBars` (5 barre sottili colorate con la palette island).
- **Sotto:** `CrewPanel` (3 ritrattini) + `SystemsBar` (3 puntini sistemi).
La struttura componenti non cambia; il look è guidato dal CSS scoped + dalla palette. (Lo space mantiene il suo arrangiamento.)

## Asset

- **Cartella:** `frontend/public/art/island/` con ~25 `.webp`.
- **Formato:** `.webp`, verticale ~3:4 (~800×1100), mobile-first full-bleed.
- **~25 immagini:** 6 categorie base (`cat-rescue`, `cat-jungle`, `cat-crisis`, `cat-character`, `cat-silence`, `cat-dilemma`) + ~19 beat-chiave (catena soccorso: scoperta scialuppa, riparazione, rifornimento, due posti; finali: soccorso/colonia/sacrificio/lone/crew_intact + morti; picchi: la giungla che inghiotte, il segnale acceso, l'impronta, il diario del relitto, ecc.).
- **Style-guide + prompt:** definiti nel piano. Stile/luce/rapporto/palette UNIFICATI (stesso "set"), con negative prompts per evitare deriva. Generati dall'utente, messi in cartella; il codice li cabla.

## Testing

- **`islandArt()` (Vitest):** override vince sulla categoria; categoria corretta per keyword; key sconosciuta → null. Test puro key→path.
- **`CardView` (Vitest + Testing Library):** rende il ramo full-bleed quando theme=island (l'elemento ha lo stile background-image / la struttura full-bleed), art-top quando space. Test di ramo.
- **Fallback:** una key island senza asset/override → CardView usa il gradiente di categoria (nessun crash, nessun `background-image` rotto).
- **Niente regressioni:** i 9 test frontend esistenti restano verdi (default space = layout attuale). Il backend: i test esistenti restano verdi dopo l'aggiunta di `theme` al payload (campo additivo).
- **Niente test sulle immagini** (sono asset; il fallback garantisce robustezza se mancano).

## Out of scope

- **Gancio meccanico** (fuoco-segnale, giungla come sistema, mistero): sotto-progetto 3.
- **Ridisegno del look SPACE:** esplicitamente no — lo space resta CRT/cyan.
- **Animazioni elaborate oltre lo swipe esistente** (parallax, video): YAGNI per v1; eventuali micro-animazioni (velo, entrata carta) sono un di più, non un requisito.
- **Generare le immagini al posto dell'utente:** il piano fornisce i prompt; la generazione è dell'utente.

## Rischi / note

- **Coerenza delle illustrazioni** è IL rischio: l'arte-regina amplifica le incoerenze. Mitigazione: style-guide dettagliata + prompt pronti + negative prompts. È la sezione su cui il piano investe di più.
- **`run.theme` nel payload** è prerequisito: senza, il tema non sopravvive a un reload. Va fatto per primo.
- **Fallback graceful** permette di sviluppare/testare il codice PRIMA che tutte le immagini esistano — il piano può procedere coi gradienti, gli asset si aggiungono mano a mano.
- **Full-bleed e leggibilità:** il velo scuro in basso deve garantire il contrasto del testo su QUALSIASI immagine; la style-guide impone un'area inferiore più scura/semplice nelle illustrazioni per non competere col testo.
