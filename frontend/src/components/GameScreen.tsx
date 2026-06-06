import { useEffect, useState } from "react";
import type { RunState } from "../api";
import { CardView } from "./CardView";
import { CrewPanel } from "./CrewPanel";
import { Inventory } from "./Inventory";
import { ResourceBars } from "./ResourceBars";

type Props = {
  run: RunState;
  busy: boolean;
  lastLog: string | null;
  onChoose: (index: number) => void;
};

// "Decay" 0..1: how close the run is to collapse. Drives the screen degrading
// (desaturate + flicker) as game over approaches (§2.2). Read from the worst
// (lowest) one-sided resource fraction.
function decayOf(run: RunState): number {
  const fracs = Object.entries(run.resources).map(([code, v]) => {
    const max = run.resource_meta[code]?.max ?? 100;
    return v / max;
  });
  const worst = Math.min(1, ...fracs);
  // decay rises as the worst resource drops below 40%.
  return Math.max(0, Math.min(1, (0.4 - worst) / 0.4));
}

export function GameScreen({ run, busy, lastLog, onChoose }: Props) {
  const decay = decayOf(run);
  const [flash, setFlash] = useState<string | null>(null);

  // Briefly surface the resolution log, then fade. Non-blocking.
  useEffect(() => {
    if (!lastLog) return;
    setFlash(lastLog);
    const t = setTimeout(() => setFlash(null), 2600);
    return () => clearTimeout(t);
  }, [lastLog]);

  return (
    <div
      className="dying flicker grid h-full grid-rows-[auto_1fr_auto] gap-2 p-3"
      style={{ ["--decay" as string]: decay }}
    >
      {/* top: day + status line */}
      <header className="flex items-center justify-between border-b border-phosphor-dim pb-2 text-xs tracking-widest text-phosphor-dim">
        <span>STARFALL STATION</span>
        <span data-testid="day" className="text-phosphor">
          GIORNO {run.day}
        </span>
      </header>

      {/* middle: resources | card | crew */}
      <div className="grid min-h-0 grid-cols-1 gap-4 sm:grid-cols-[140px_1fr_150px]">
        <aside className="hidden sm:block">
          <ResourceBars resources={run.resources} meta={run.resource_meta} />
        </aside>

        <main className="flex min-h-0 flex-col items-center justify-center overflow-hidden">
          {/* resource bars stack on top on small screens */}
          <div className="mb-3 w-full max-w-md sm:hidden">
            <ResourceBars resources={run.resources} meta={run.resource_meta} />
          </div>

          {run.card ? (
            <CardView card={run.card} busy={busy} onChoose={onChoose} />
          ) : (
            <div className="text-sm text-phosphor-dim">…</div>
          )}

          <div
            data-testid="log"
            className={`mt-3 h-5 text-center text-xs italic transition-opacity duration-300 ${
              flash ? "text-amber opacity-100" : "opacity-0"
            }`}
          >
            {flash}
          </div>
        </main>

        <aside className="hidden sm:block">
          <CrewPanel characters={run.characters} />
        </aside>
      </div>

      {/* bottom: inventory */}
      <footer className="border-t border-phosphor-dim pt-2">
        <Inventory items={run.items} />
      </footer>
    </div>
  );
}
