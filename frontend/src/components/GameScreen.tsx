import { useEffect, useState } from "react";
import type { RunState, Reaction } from "../api";
import { CardView } from "./CardView";
import { CrewPanel } from "./CrewPanel";
import { Inventory } from "./Inventory";
import { ResourceBars } from "./ResourceBars";
import { SystemsBar } from "./SystemsBar";

type Props = {
  run: RunState;
  busy: boolean;
  lastLog: string | null;
  reactions: Reaction[];
  onChoose: (index: number) => void;
  onAdvance: () => void;
};

export function GameScreen({ run, busy, lastLog, reactions, onChoose, onAdvance }: Props) {
  const [flash, setFlash] = useState<{ text: string; good: boolean } | null>(null);

  useEffect(() => {
    if (!lastLog) return;
    const bad = lastLog.toLowerCase().includes("perso") ||
                lastLog.toLowerCase().includes("dann") ||
                lastLog.toLowerCase().includes("croll") ||
                lastLog.toLowerCase().includes("morto");
    setFlash({ text: lastLog, good: !bad });
    const t = setTimeout(() => setFlash(null), 3000);
    return () => clearTimeout(t);
  }, [lastLog]);

  // Items required by the current card's choices
  const relevantItems = run.card?.choices
    .flatMap(c => c.requires_item ? [c.requires_item] : []) ?? [];

  return (
    <div style={{
      display: "grid",
      gridTemplateRows: "48px 1fr 88px",
      height: "100%",
      background: "var(--color-bg)",
      overflow: "hidden",
    }}>
      {/* Header */}
      <header style={{
        display: "flex", alignItems: "center", justifyContent: "space-between",
        padding: "0 20px",
        borderBottom: "1px solid var(--color-border)",
        background: "var(--color-surface)",
        flexShrink: 0,
      }}>
        <span style={{
          fontFamily: "var(--font-mono)", fontSize: 11,
          color: "var(--color-text-muted)", letterSpacing: "0.18em",
        }}>
          STARFALL STATION
        </span>
        <span data-testid="day" style={{
          fontFamily: "var(--font-mono)", fontSize: 13,
          color: "var(--color-cyan)", fontWeight: 700,
        }}>
          GIORNO {run.day}
        </span>
      </header>

      {/* Main body: 3 columns */}
      <div style={{
        display: "grid",
        gridTemplateColumns: "160px 1fr 175px",
        gap: 16, padding: 16,
        minHeight: 0, overflow: "hidden",
      }}>
        {/* Left: Resources */}
        <aside style={{ overflow: "hidden" }}>
          <ResourceBars resources={run.resources} meta={run.resource_meta} />
        </aside>

        {/* Center: Card + log flash */}
        <main style={{
          display: "flex", flexDirection: "column",
          alignItems: "center", justifyContent: "center",
          minHeight: 0, overflow: "hidden", gap: 10,
        }}>
          {run.card ? (
            <CardView
              card={run.card}
              busy={busy}
              onChoose={onChoose}
              onAdvance={onAdvance}
              relevantItems={relevantItems}
            />
          ) : (
            <div style={{ color: "var(--color-text-muted)" }}>…</div>
          )}

          {/* Log flash */}
          <div data-testid="log" style={{
            height: 20, fontSize: 13, fontStyle: "italic", textAlign: "center",
            color: flash?.good ? "var(--color-cyan)" : "var(--color-orange)",
            opacity: flash ? 1 : 0,
            transition: "opacity 400ms ease",
          }}>
            {flash?.text}
          </div>
        </main>

        {/* Right: Crew */}
        <aside style={{ overflow: "hidden" }}>
          <CrewPanel characters={run.characters} epithet={run.epithet} reactions={reactions} />
        </aside>
      </div>

      {/* Footer: inventory + systems */}
      <footer style={{
        borderTop: "1px solid var(--color-border)",
        background: "var(--color-surface)",
        display: "flex", flexDirection: "column",
        flexShrink: 0,
      }}>
        <Inventory items={run.items} relevantItems={relevantItems} />
        <SystemsBar systems={run.systems} crewTrust={run.crew_trust} />
      </footer>
    </div>
  );
}
