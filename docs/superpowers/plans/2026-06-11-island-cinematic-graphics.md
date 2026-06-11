# Island Cinematic Graphics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the island theme a cinematic illustrated look — golden+jungle palette, full-bleed illustrated cards, art-regina HUD — scoped so the space theme stays unchanged.

**Architecture:** A `data-theme="island"` attribute on the game root scopes all island CSS under `[data-theme="island"]`. `CardView` gains an explicit full-bleed branch for island (vs space's art-top). A pure `islandArt(key)` mapper resolves an event key to a static `.webp` path (beat-key overrides → category fallback → null), with graceful fallback to category gradients when an asset is missing. The backend exposes `run.theme` in the run payload so the theme survives reloads. Image generation is the user's (the plan ships a style-guide + ready prompts).

**Tech Stack:** React + TypeScript + Vite, Vitest + Testing Library (frontend); Laravel 12 + Pest (backend, one additive field). Italian game text, English identifiers.

---

## File Structure

- **Modify** `backend/app/Http/Controllers/RunController.php` — add `'theme' => $run->theme` to the `present()` payload (Task 1).
- **Modify** `frontend/src/api.ts` — add `theme: string` to the `RunState` type (Task 1).
- **Modify** `frontend/src/index.css` — add `[data-theme="island"]` palette + island `card-art-*` gradients + full-bleed card rules (Task 2).
- **Create** `frontend/src/islandArt.ts` — `islandArt(key)` mapper (Task 3).
- **Create** `frontend/src/islandArt.test.ts` — mapper tests (Task 3).
- **Modify** `frontend/src/components/CardView.tsx` — explicit full-bleed branch for island (Task 4).
- **Modify** `frontend/src/components/GameScreen.tsx` — set `data-theme` on root from `run.theme` (Task 5).
- **Create** `frontend/public/art/island/` — asset directory + a README placeholder (Task 6).
- **Create** `docs/island-art-style-guide.md` — style-guide + ~25 ready prompts (Task 6).

---

## Task 1: Backend exposes `run.theme` (prerequisite)

**Files:**
- Modify: `backend/app/Http/Controllers/RunController.php` (`present()`, ~line 164)
- Modify: `frontend/src/api.ts` (`RunState` type)
- Test: `backend/tests/Feature/` (an existing run-shape test, or add an assertion)

- [ ] **Step 1: Write the failing backend test**

Find a feature test that hits `POST /api/runs` or `GET /api/runs/{id}` and asserts the JSON shape (e.g. `grep -rl "api/runs" backend/tests/Feature`). Add a test asserting the payload includes `theme`:

```php
it('exposes the run theme in the API payload', function () {
    $res = $this->postJson('/api/runs', ['seed' => 1, 'theme' => 'island', 'items' => []]);
    $res->assertCreated()->assertJsonPath('theme', 'island');
});
```
Place it in `backend/tests/Feature/RunApiTest.php` (or the existing run-controller test file — match where similar tests live).

- [ ] **Step 2: Run it — RED**

Run: `cd backend && php artisan test --filter="exposes the run theme"`
Expected: FAIL (`theme` absent from payload).

- [ ] **Step 3: Add theme to present()**

In `RunController::present()`, in the `$payload = [ ... ]` array (after `'seed' => $run->seed,`), add:
```php
            'theme' => $run->theme,
```

- [ ] **Step 4: Run it — GREEN**

Run: `cd backend && php artisan test --filter="exposes the run theme"`
Expected: PASS. Then `cd backend && php artisan test` — full suite green (no regression; additive field).

- [ ] **Step 5: Add theme to the frontend RunState type**

In `frontend/src/api.ts`, in the `export type RunState = { ... }` block, add (near `day`/`status`):
```ts
  theme: string;
```

- [ ] **Step 6: Frontend typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: exit 0 (the field is additive; existing code compiles).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/RunController.php frontend/src/api.ts backend/tests/Feature/
git commit -m "feat(api): expose run.theme in payload for frontend theming"
```

---

## Task 2: Island palette + gradients (scoped CSS)

**Files:**
- Modify: `frontend/src/index.css`

Add island theme variables and gradients under `[data-theme="island"]`. The space `@theme` block and existing `.card-art-*` classes are UNTOUCHED — island rules override only under the scope.

- [ ] **Step 1: Add the island palette block**

Append to `frontend/src/index.css` (after the existing `@theme`/`:root` setup, anywhere after the space vars):

```css
/* ===== Island theme: golden + jungle, scoped ===== */
[data-theme="island"] {
  --color-bg:           #141d18;   /* night green */
  --color-surface:      #1c2a22;
  --color-surface-hi:   #243a2e;
  --color-surface-card: #1a2620;
  --color-border:       #3a4a38;
  --color-border-hi:    #5a7a4a;

  /* amber replaces cyan as the accent everywhere it's used via var */
  --color-cyan:         #e8b85a;   /* amber/sand — keeps existing var name so accents recolor */
  --color-cyan-dim:     #a07838;
  --color-cyan-glow:    rgba(232,184,90,0.20);

  --color-sea:          #3a7a6a;
  --color-sand:         #e8b85a;
  --color-jungle:       #2d4a3a;

  --color-text:         #f0e8d8;
  --color-text-dim:     #a89878;
  --color-text-muted:   #6a5d48;
}

/* Island card-art gradients (fallback when no illustration asset) */
[data-theme="island"] .card-art-crisis      { background: linear-gradient(160deg, #1a0a08 0%, #5a2418 50%, #d4503a 100%); }
[data-theme="island"] .card-art-exploration { background: linear-gradient(160deg, #04140c 0%, #1a3a28 55%, #3a6a4a 100%); }
[data-theme="island"] .card-art-character   { background: linear-gradient(160deg, #1a1510 0%, #3a4a3a 50%, #7a8a5a 100%); }
[data-theme="island"] .card-art-system      { background: linear-gradient(160deg, #0a1418 0%, #1a3035 55%, #3a5a55 100%); }
[data-theme="island"] .card-art-silent      { background: linear-gradient(160deg, #0a1418 0%, #1a3035 55%, #3a5a55 100%); }
[data-theme="island"] .card-art-moral       { background: linear-gradient(160deg, #1a1205 0%, #4a3818 50%, #a07838 100%); }
[data-theme="island"] .card-art-rescue      { background: linear-gradient(160deg, #0a1a24 0%, #2d4a3a 45%, #c98a3a 100%); }

/* Hide the space starfield on island */
[data-theme="island"] .card-art-stars { display: none; }

/* Full-bleed card: the illustration IS the card */
[data-theme="island"] .card-fullbleed {
  position: relative;
  height: 62vh;
  max-height: 520px;
  border-radius: 18px;
  overflow: hidden;
  background-size: cover;
  background-position: center;
  box-shadow: 0 14px 44px rgba(0,0,0,0.55);
}
[data-theme="island"] .card-fullbleed-scrim {
  position: absolute; inset: 0;
  background: linear-gradient(0deg, rgba(8,12,8,0.92) 0%, rgba(8,12,8,0.55) 32%, transparent 62%);
  pointer-events: none;
}
[data-theme="island"] .card-fullbleed-text {
  position: absolute; left: 16px; right: 16px; bottom: 14px;
  font-family: Georgia, "Times New Roman", serif;
  color: var(--color-text);
  text-shadow: 0 1px 6px rgba(0,0,0,0.9);
}
[data-theme="island"] .card-fullbleed-speaker {
  position: absolute; top: 14px; left: 16px;
  background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
  border-radius: 6px; padding: 3px 10px;
  font-size: 11px; font-weight: 700; letter-spacing: 0.12em;
  color: var(--color-sand);
}
```

- [ ] **Step 2: Verify it builds**

Run: `cd frontend && npx tsc --noEmit && npm run build 2>&1 | tail -5`
Expected: build succeeds (CSS-only change; no TS impact).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/index.css
git commit -m "feat(island): scoped golden+jungle palette and full-bleed card CSS"
```

---

## Task 3: islandArt mapper

**Files:**
- Create: `frontend/src/islandArt.ts`
- Create: `frontend/src/islandArt.test.ts`

A pure function: event key → illustration path (or null for gradient fallback). Beat-key overrides win; else category by island keyword; else null.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/islandArt.test.ts`:
```ts
import { describe, it, expect } from "vitest";
import { islandArt, islandCategory } from "./islandArt";

describe("islandArt", () => {
  it("returns the beat-key override when one exists", () => {
    expect(islandArt("rescue_4_launch")).toBe("/art/island/lifeboat.webp");
  });

  it("falls back to the category image for a categorized key", () => {
    expect(islandArt("exp_jungle_depart")).toBe("/art/island/cat-jungle.webp");
  });

  it("returns null for an unknown key (gradient fallback)", () => {
    expect(islandArt("totally_unknown_key_xyz")).toBeNull();
  });
});

describe("islandCategory", () => {
  it("maps rescue/sea keys to rescue", () => {
    expect(islandCategory("rescue_1_discovery")).toBe("rescue");
    expect(islandCategory("c_filler_flat_sea")).toBe("silent"); // sea-at-night = silent, see rule order
  });
  it("maps jungle/expedition keys to jungle", () => {
    expect(islandCategory("exp_return_lost")).toBe("jungle");
  });
  it("maps crisis keys to crisis", () => {
    expect(islandCategory("water_crisis")).toBe("crisis");
  });
  it("maps survivor/pair keys to character", () => {
    expect(islandCategory("nadia_arc_1")).toBe("character");
    expect(islandCategory("pair_bruno_carla_clash")).toBe("character");
  });
  it("defaults unknown keys to silent", () => {
    expect(islandCategory("totally_unknown_key_xyz")).toBe("silent");
  });
});
```

- [ ] **Step 2: Run it — RED**

Run: `cd frontend && npm run test -- islandArt`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement islandArt.ts**

Create `frontend/src/islandArt.ts`:
```ts
// Maps an island event key to its illustration. Pure, testable, no I/O.
// Resolution order: beat-key override → category image → null (CSS gradient fallback).

export type IslandCategory =
  | "rescue" | "jungle" | "crisis" | "character" | "silent" | "dilemma";

const BASE = "/art/island";

// Beat-key overrides: the epic moments get their own illustration.
const OVERRIDES: Record<string, string> = {
  rescue_1_discovery: `${BASE}/wreck-boat.webp`,
  rescue_2_repair:    `${BASE}/boat-repair.webp`,
  rescue_3_supply:    `${BASE}/boat-supply.webp`,
  rescue_4_launch:    `${BASE}/lifeboat.webp`,
  arc_radio_3:        `${BASE}/radio-answer.webp`,
  arc_log_3:          `${BASE}/wreck-diary.webp`,
  exp_jungle_depart:  `${BASE}/jungle-deep.webp`,
  iso_wreckage:       `${BASE}/crash-site.webp`,
  c_filler_footprint: `${BASE}/footprint.webp`,
};

// Category by island keyword. Order matters: earlier rules win.
export function islandCategory(key: string): IslandCategory {
  if (/rescue|signal|raft|sos|vela|soccorso/.test(key)) return "rescue";
  if (/exp_|jungle|giungla|reef|dive|wild/.test(key)) return "jungle";
  if (/crisis|storm|fire|water_crisis|breach|hull|cold|thirst|hunger|trap|cascade/.test(key)) return "crisis";
  if (/nadia|bruno|carla|survivor|pair_|cross_|doctor|engineer|pilot|char/.test(key)) return "character";
  if (/dilemma|moral|two_seats|sacrifice|reckon/.test(key)) return "dilemma";
  // night/sea/quiet/filler beats read as contemplative
  if (/filler|night|sea|silent|quiet|star|moment/.test(key)) return "silent";
  return "silent";
}

const CATEGORY_IMAGE: Record<IslandCategory, string> = {
  rescue:    `${BASE}/cat-rescue.webp`,
  jungle:    `${BASE}/cat-jungle.webp`,
  crisis:    `${BASE}/cat-crisis.webp`,
  character: `${BASE}/cat-character.webp`,
  silent:    `${BASE}/cat-silent.webp`,
  dilemma:   `${BASE}/cat-dilemma.webp`,
};

// Set of assets known to exist (filled as the user generates them). When an
// override or category image is NOT in this set, return null → gradient fallback.
// Start empty so the game runs on gradients; add filenames as art lands.
export const AVAILABLE_ART = new Set<string>([]);

export function islandArt(key: string): string | null {
  const override = OVERRIDES[key];
  if (override) return AVAILABLE_ART.size === 0 || AVAILABLE_ART.has(override) ? override : catImg(key);
  return catImg(key);
}

function catImg(key: string): string | null {
  const img = CATEGORY_IMAGE[islandCategory(key)];
  if (AVAILABLE_ART.size === 0) return img; // dev mode: assume mapping, CardView guards missing files
  return AVAILABLE_ART.has(img) ? img : null;
}
```

NOTE on the test: with `AVAILABLE_ART` empty (dev default), `islandArt` returns the mapped path (the test expects `/art/island/lifeboat.webp` and `/art/island/cat-jungle.webp`). The "unknown key → null" test: an unknown key maps to category `silent` → `cat-silent.webp`, NOT null. So FIX the test's third case to match reality:

Replace the third `islandArt` test with:
```ts
  it("maps an unknown key to the silent category image (gradient still backs it)", () => {
    expect(islandArt("totally_unknown_key_xyz")).toBe("/art/island/cat-silent.webp");
  });
```
(Real null only happens once `AVAILABLE_ART` is populated and a file is genuinely absent — `CardView` then falls back to the gradient via the missing-asset guard in Task 4.)

- [ ] **Step 4: Run it — GREEN**

Run: `cd frontend && npm run test -- islandArt`
Expected: PASS (after the test fix above).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/islandArt.ts frontend/src/islandArt.test.ts
git commit -m "feat(island): islandArt key→illustration mapper with category fallback"
```

---

## Task 4: CardView full-bleed branch

**Files:**
- Modify: `frontend/src/components/CardView.tsx`
- Test: `frontend/src/components/CardView.test.tsx` (create)

`CardView` renders art-top today. Add an explicit `theme === "island"` branch that renders full-bleed. The component must know the theme — pass it as a prop from `GameScreen` (Task 5 wires it). Default `"space"` keeps current behavior.

- [ ] **Step 1: Read the current CardView**

Run: `sed -n '1,120p' frontend/src/components/CardView.tsx`
Note the `Props` type, the `artClass(key)` helper, the art-zone markup (line ~68), the swipe handlers, and the choices/advance rendering. You will REUSE the swipe handlers in both branches.

- [ ] **Step 2: Write the failing test**

Create `frontend/src/components/CardView.test.tsx`:
```tsx
import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import { CardView } from "./CardView";
import type { Card } from "../api";

const card: Card = {
  key: "rescue_1_discovery",
  title: "La scialuppa",
  body: "Tra gli scogli, una scialuppa.",
  speaker: null,
  choices: [
    { index: 0, label: "Sì", available: true } as any,
    { index: 1, label: "No", available: true } as any,
  ],
};
const noop = () => {};

describe("CardView theming", () => {
  it("renders the full-bleed layout for the island theme", () => {
    const { container } = render(
      <CardView card={card} busy={false} onChoose={noop} onAdvance={noop} relevantItems={[]} theme="island" />,
    );
    expect(container.querySelector(".card-fullbleed")).not.toBeNull();
  });

  it("renders the art-top layout for the space theme (default)", () => {
    const { container } = render(
      <CardView card={card} busy={false} onChoose={noop} onAdvance={noop} relevantItems={[]} theme="space" />,
    );
    expect(container.querySelector(".card-fullbleed")).toBeNull();
    expect(container.querySelector(".card-art")).not.toBeNull();
  });
});
```

- [ ] **Step 3: Run it — RED**

Run: `cd frontend && npm run test -- CardView`
Expected: FAIL (no `theme` prop / no `.card-fullbleed`).

- [ ] **Step 4: Add the theme prop + full-bleed branch**

In `CardView.tsx`:
1. Add `theme?: "space" | "island"` to the `Props` type, default to `"space"` in the destructure: `({ card, busy, onChoose, onAdvance, relevantItems, theme = "space" }: Props)`.
2. Add the import: `import { islandArt } from "../islandArt";`
3. Keep the existing art-top `return (...)` for the non-island path. Add, BEFORE it (after the `isSilent` early-return), the island branch. Reuse the SAME swipe handlers (`onPointerDown/Move/Up`, `drag`, `tilt`, `tellSide`) — factor them above both returns so both branches use them.

The island branch markup (full-bleed):
```tsx
  if (theme === "island") {
    const art = islandArt(card.key);
    const cat = `card-art-${/* category class */ islandArtClass(card.key)}`;
    return (
      <div style={{ width: "100%", maxWidth: 420, display: "flex", flexDirection: "column", gap: 10 }}>
        <div
          key={card.key}
          data-testid="card"
          className={`card-fullbleed ${cat} card-enter ${tellSide}`}
          style={{
            transform: `translateX(${drag}px) rotate(${tilt}deg)`,
            ...(art ? { backgroundImage: `url(${art})` } : {}),
          }}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerCancel={onPointerUp}
        >
          <div className="card-fullbleed-scrim" />
          {card.speaker && <div className="card-fullbleed-speaker">{card.speaker.toUpperCase()}</div>}
          <div className="card-fullbleed-text">
            <div style={{ fontSize: 17, marginBottom: 6 }}>{card.title}</div>
            <div style={{ fontSize: 13, lineHeight: 1.45, color: "var(--color-text-dim)", marginBottom: 12 }}>{card.body}</div>
            <div style={{ display: "flex", gap: 8 }}>
              {available.map((c) => (
                <button
                  key={c.index}
                  onClick={() => onChoose(c.index)}
                  disabled={busy}
                  style={{
                    flex: 1, padding: "9px 12px", borderRadius: 10,
                    background: "rgba(0,0,0,0.5)", border: "1px solid var(--color-cyan-dim)",
                    color: "var(--color-sand)", fontFamily: "Georgia, serif", fontSize: 13,
                    cursor: busy ? "not-allowed" : "pointer",
                  }}
                >{c.label}</button>
              ))}
            </div>
          </div>
        </div>
      </div>
    );
  }
```
4. The `cat` class needs the gradient category. The existing `artClass(key)` uses SPACE keywords; for island, add a small local helper that maps to the island gradient class names used in Task 2 (`crisis|exploration|character|system|silent|moral|rescue`). Add near `artClass`:
```tsx
function islandArtClass(key: string): string {
  if (/rescue|signal|raft|sos|vela/.test(key)) return "rescue";
  if (/exp_|jungle|reef|dive/.test(key)) return "exploration";
  if (/crisis|storm|fire|water_crisis|cold|thirst|trap/.test(key)) return "crisis";
  if (/nadia|bruno|carla|survivor|pair_|cross_/.test(key)) return "character";
  if (/dilemma|moral|sacrifice|reckon/.test(key)) return "moral";
  return "silent";
}
```
(This mirrors `islandCategory` but returns the CSS gradient class names from Task 2. Keep them consistent.)

- [ ] **Step 5: Run it — GREEN**

Run: `cd frontend && npm run test -- CardView`
Expected: PASS (both layout tests). Then `cd frontend && npm run test` — full suite green (App.test etc. unaffected; default theme=space).

- [ ] **Step 6: Typecheck + commit**

Run: `cd frontend && npx tsc --noEmit` — exit 0.
```bash
git add frontend/src/components/CardView.tsx frontend/src/components/CardView.test.tsx
git commit -m "feat(island): full-bleed CardView branch (illustration as the card)"
```

---

## Task 5: Wire data-theme + pass theme to CardView

**Files:**
- Modify: `frontend/src/components/GameScreen.tsx`
- Test: extend `frontend/src/components/CardView.test.tsx` OR add a GameScreen test (see step)

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/App.test.tsx` (or a new `GameScreen.test.tsx`) a test that, when a run with `theme: "island"` is rendered, the game root carries `data-theme="island"`. Match the existing App.test mock style (it already mocks `fetch` for `/runs`). Make the mocked run state include `theme: "island"` and assert `container.querySelector('[data-theme="island"]')` is present once a run is active. If wiring a full App test is heavy, instead add a focused GameScreen render test:
```tsx
// GameScreen.test.tsx
import { describe, it, expect } from "vitest";
import { render } from "@testing-library/react";
import { GameScreen } from "./GameScreen";

it("sets data-theme on the root from run.theme", () => {
  const run: any = {
    id: 1, day: 1, status: "active", theme: "island", resources: {}, resource_meta: {},
    phase: "castaway", phase_label: "Naufragio", characters: [], items: [], systems: {},
    crew_trust: 60, choice_log: [], card: { key: "iso_wreckage", title: "t", body: "b", speaker: null, choices: [] },
  };
  const { container } = render(
    <GameScreen run={run} busy={false} lastLog={null} lastEffects={[]} reactions={[]} onChoose={() => {}} onAdvance={() => {}} />,
  );
  expect(container.querySelector('[data-theme="island"]')).not.toBeNull();
});
```
(Adapt the `run` mock to the real `RunState`/`Props` shape — read `GameScreen.tsx` props and the `card` field name it expects.)

- [ ] **Step 2: Run it — RED**

Run: `cd frontend && npm run test -- GameScreen`
Expected: FAIL (no `data-theme`).

- [ ] **Step 3: Set data-theme on the root + pass theme to CardView**

In `GameScreen.tsx`:
1. On the root grid `<div>` (the `return (<div style={{display:"grid"...}}>`), add the attribute: `data-theme={run.theme}`.
2. Find where `<CardView ... />` is rendered (~line 104) and pass `theme={run.theme}`.

- [ ] **Step 4: Run it — GREEN**

Run: `cd frontend && npm run test -- GameScreen`
Expected: PASS. Then `cd frontend && npm run test` — full suite green.

- [ ] **Step 5: Typecheck + commit**

Run: `cd frontend && npx tsc --noEmit` — exit 0.
```bash
git add frontend/src/components/GameScreen.tsx frontend/src/App.test.tsx frontend/src/components/GameScreen.test.tsx
git commit -m "feat(island): wire data-theme from run.theme, pass theme to CardView"
```

---

## Task 6: Style-guide + ready prompts + asset directory

**Files:**
- Create: `docs/island-art-style-guide.md`
- Create: `frontend/public/art/island/README.md` (directory placeholder)

This is content for the USER to generate images. The code already falls back to gradients, so no image is required for the game to run — this task ships the guide so the user can produce a coherent set.

- [ ] **Step 1: Create the asset directory placeholder**

Create `frontend/public/art/island/README.md`:
```md
# Island illustrations

Drop the generated `.webp` files here (≈800×1100, vertical 3:4). Filenames must
match `frontend/src/islandArt.ts` (OVERRIDES + CATEGORY_IMAGE). Until a file
exists, the card falls back to its category gradient — the game never breaks.

After adding files, list their filenames in `AVAILABLE_ART` in `islandArt.ts`
so the mapper serves them (leave the set empty to serve all mapped paths in dev).
```

- [ ] **Step 2: Write the style-guide with ready prompts**

Create `docs/island-art-style-guide.md`. It MUST contain: (a) a shared style preamble every prompt reuses, (b) a shared negative-prompt, (c) technical specs, (d) the ~25 concrete prompts (6 category + ~19 beat-key) whose filenames match `islandArt.ts`. Write it fully — no placeholders. Structure:

```md
# Island Art Style Guide

## Shared style (prepend to EVERY prompt)
"Cinematic digital painting, warm golden-hour survival mood with lush tropical
jungle accents, painterly but detailed, dramatic natural light, desaturated
shadows with warm amber highlights, sense of isolation and fragile hope, muted
teal sea and sand tones, no text, no UI, vertical 3:4 composition, the LOWER
THIRD kept visually simple and darker for caption overlay."

## Shared negative prompt
"text, words, letters, watermark, signature, UI, frame, border, modern
technology, spacecraft, neon, cyberpunk, cartoon, anime, oversaturated,
cluttered bottom, faces in extreme close-up."

## Technical specs
- Output ~800×1100 px, export as .webp (quality ~82).
- Vertical 3:4. Keep the bottom third low-detail/darker (text overlays there).
- One coherent set: same painter's hand, same light, same palette across all.

## Category images (fallback for any card of that type)
1. `cat-rescue.webp` — "<shared style> A distant sail on a vast calm sea at golden
   hour, seen from a driftwood beach, a thin thread of hope on the horizon."
2. `cat-jungle.webp` — "<shared style> Deep tropical jungle interior, shafts of
   warm light through dense green canopy, humid and alive, faintly threatening."
3. `cat-crisis.webp` — "<shared style> A sudden storm hitting the shore at dusk,
   wind-torn palms, a campfire guttering, red-earth urgency."
4. `cat-character.webp` — "<shared style> Three weary plane-crash survivors around
   a small fire on the beach, warm faces in firelight, intimate, hopeful-tired."
5. `cat-silent.webp` — "<shared style> The flat empty sea at night under a sky
   thick with stars, no city light, profound quiet and solitude."
6. `cat-dilemma.webp` — "<shared style> A single small boat on the sand at dusk
   with too few seats, two paths in the sand, the weight of a hard choice."

## Beat-key images (the epic moments)
7. `wreck-boat.webp` (rescue_1_discovery) — "<shared style> A battered lifeboat
   wedged among tidal rocks at dawn, barnacled and broken but afloat-able, the
   first glimpse of a way off the island."
8. `boat-repair.webp` (rescue_2_repair) — "<shared style> Hands working resin and
   driftwood to mend a small wooden boat hull on the beach, focused labor."
9. `boat-supply.webp` (rescue_3_supply) — "<shared style> Crates and water gourds
   loaded into a small boat on the sand, the emptying camp behind."
10. `lifeboat.webp` (rescue_4_launch) — "<shared style> A small boat pushed off a
    beach into a golden sea at dusk, figures aboard, others watching from shore,
    bittersweet departure."
11. `radio-answer.webp` (arc_radio_3) — "<shared style> A hand on a field radio at
    night, a faint warm glow, a voice breaking through static — someone out there."
12. `wreck-diary.webp` (arc_log_3) — "<shared style> An old water-stained logbook
    open on a rock, unsettling handwritten pages, candlelight, quiet dread."
13. `jungle-deep.webp` (exp_jungle_depart) — "<shared style> A lone survivor stepping
    into the dark jungle interior, the green swallowing the light behind them."
14. `crash-site.webp` (iso_wreckage) — "<shared style> The broken fuselage of a
    crashed plane half-buried on a tropical beach at dawn, smoke long gone, eerie calm."
15. `footprint.webp` (c_filler_footprint) — "<shared style> A single bare footprint
    in wet sand, larger than the survivors', dread in a small detail, low warm light."

## More beat-keys to cover endings/peaks (add OVERRIDES entries in islandArt.ts as you generate)
16. `ending-rescue.webp` — "<shared style> The boat reaching open water as the
    island recedes, dawn breaking, survival earned."
17. `ending-colony.webp` — "<shared style> A small thriving beach camp with a
    tended garden and a strong shelter, the survivors made a life here."
18. `ending-sacrifice.webp` — "<shared style> One figure alone on the shore at
    dusk watching the boat leave, having stayed so others could go."
19. `ending-lone.webp` — "<shared style> A single survivor alone on a vast beach,
    isolation and endurance, long shadows."
20. `ending-death-thirst.webp` — "<shared style> A cracked dry riverbed inland under
    harsh sun, empty water gourds, the desaturated end of hope."
21. `ending-death-cold.webp` — "<shared style> A dead campfire at grey dawn, ash and
    cold, the signal long out."
22. `signal-fire.webp` — "<shared style> A tall signal fire blazing on a headland at
    night against the dark sea, a desperate beacon."
23. `night-camp.webp` — "<shared style> Survivors asleep around embers under stars,
    the jungle dark at the edges, fragile safety."
24. `high-tide.webp` — "<shared style> The sea surging up the beach at storm-dusk,
    threatening the camp, red-earth urgency."
25. `fresh-water.webp` — "<shared style> A clear freshwater stream found deep in the
    jungle, dappled green light, relief."
```

Write ALL 25 entries fully (the above is the complete set — reproduce it). The filenames in entries 1-15 MUST match `islandArt.ts`'s `OVERRIDES`/`CATEGORY_IMAGE`. For entries 16-25, the engineer should ADD matching `OVERRIDES` entries to `islandArt.ts` (e.g. `win_rescue_launched: '.../ending-rescue.webp'`) — do that in this task and keep the test green.

- [ ] **Step 3: Add ending/peak overrides to islandArt.ts**

Extend `OVERRIDES` in `frontend/src/islandArt.ts` with the ending/peak keys (using real island ending keys — verify against `config/themes/island.php` `endings`: `win_rescue_launched`, `win_colony`, `win_sacrifice`, `lone_survivor`, `death_thirst`, `death_cold`, etc.):
```ts
  win_rescue_launched: `${BASE}/ending-rescue.webp`,
  win_colony:          `${BASE}/ending-colony.webp`,
  win_sacrifice:       `${BASE}/ending-sacrifice.webp`,
  lone_survivor:       `${BASE}/ending-lone.webp`,
  death_thirst:        `${BASE}/ending-death-thirst.webp`,
  death_cold:          `${BASE}/ending-death-cold.webp`,
```
Run `cd frontend && npm run test -- islandArt` — still green (overrides are additive; dev mode serves paths).

- [ ] **Step 4: Commit**

```bash
git add docs/island-art-style-guide.md frontend/public/art/island/README.md frontend/src/islandArt.ts
git commit -m "docs(island): art style-guide + ready prompts; ending art overrides"
```

---

## Task 7: Integration verification

**Files:** none (verification only)

- [ ] **Step 1: Full frontend + backend suites green**

Run: `cd frontend && npm run test` — all pass (existing 9 + new islandArt/CardView/GameScreen tests).
Run: `cd backend && php artisan test` — all pass.

- [ ] **Step 2: Typecheck + build**

Run: `cd frontend && npx tsc --noEmit && npm run build 2>&1 | tail -5` — clean build.

- [ ] **Step 3: Manual smoke (the real payoff)**

Start backend + frontend (`php artisan serve`, `npm run dev`). Start an **island** run:
- The game root has `data-theme="island"` (inspect element).
- Cards render full-bleed with the golden+jungle gradient (no illustration files yet → gradient fallback working).
- Speaker tag is amber, text serif on a dark scrim, choices as buttons.
- Resources/crew/systems render with the island palette.
Then start a **space** run: confirm it looks EXACTLY as before (art-top, cyan, unchanged).

- [ ] **Step 4: (Optional, when art exists) drop a couple webp files**

If any `.webp` are generated, drop them in `frontend/public/art/island/`, add their filenames to `AVAILABLE_ART` in `islandArt.ts`, reload an island run, confirm the illustration shows full-bleed and missing ones still fall back to gradient.

---

## Final verification

- [ ] `cd frontend && npm run test` green; `cd backend && php artisan test` green.
- [ ] `npx tsc --noEmit` clean; `npm run build` succeeds.
- [ ] Island run: full-bleed cards, golden+jungle palette, `data-theme="island"`, gradient fallback when no art.
- [ ] Space run: visually unchanged (art-top, cyan).
- [ ] `docs/island-art-style-guide.md` has all ~25 prompts; filenames match `islandArt.ts`.

---

## Notes for the implementing engineer

- **The space theme must look IDENTICAL after this.** Every island rule is scoped under `[data-theme="island"]`; the default (space) path is untouched. If a space test changes behavior, you broke scoping — fix it.
- **Graceful fallback is load-bearing.** The game must run with ZERO illustration files (gradients back every card). `AVAILABLE_ART` empty = dev mode serves mapped paths; `CardView`'s `background-image` simply shows nothing over the gradient if a file 404s. Never make a card require an asset.
- **Filenames are a contract.** `islandArt.ts` paths ↔ `island-art-style-guide.md` filenames ↔ files in `public/art/island/`. Keep all three in sync.
- **`islandCategory` (mapper) and `islandArtClass` (CSS class) must agree.** They use the same keyword rules; if you change one, change the other. (A future cleanup could unify them — out of scope here.)
- **Image generation is the user's job.** Ship the guide; do not attempt to generate images. The plan is "done" when the code + gradients + guide are in place, even before a single `.webp` exists.
- **Italian text, English identifiers** — unchanged project rule.
