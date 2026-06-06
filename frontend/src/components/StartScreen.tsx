import { useEffect, useState } from "react";
import { fetchItems, type Item } from "../api";

type Props = {
  handle: string;
  busy: boolean;
  onBegin: (items: string[]) => void;
};

// Start screen: pick the 5 items that shape the run, then drop into the station.
// Fast in (a couple of taps) — part of the flow contract (§1.5).
export function StartScreen({ handle, busy, onBegin }: Props) {
  const [items, setItems] = useState<Item[]>([]);
  const [pick, setPick] = useState(5);
  const [chosen, setChosen] = useState<string[]>([]);
  const [loadError, setLoadError] = useState(false);

  useEffect(() => {
    fetchItems(handle)
      .then((c) => {
        setItems(c.items);
        setPick(c.pick);
      })
      .catch(() => setLoadError(true));
  }, [handle]);

  function toggle(key: string) {
    setChosen((cur) =>
      cur.includes(key)
        ? cur.filter((k) => k !== key)
        : cur.length < pick
          ? [...cur, key]
          : cur,
    );
  }

  const ready = chosen.length === pick;

  return (
    <div className="mx-auto flex h-full max-w-2xl flex-col items-center justify-center gap-6 p-6">
      <header className="text-center">
        <h1 className="text-3xl tracking-[0.3em] text-phosphor">STARFALL STATION</h1>
        <p className="mt-1 text-xs tracking-widest text-phosphor-dim">
          // protocollo di sopravvivenza
        </p>
      </header>

      <p className="text-center text-sm text-phosphor/80">
        La stazione è compromessa. Scegli{" "}
        <span className="text-phosphor">{pick}</span> dotazioni prima del distacco.
      </p>

      {loadError && (
        <p className="text-sm text-alarm">Impossibile contattare la stazione.</p>
      )}

      <div className="grid max-h-[46vh] w-full grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3">
        {items.map((it) => {
          const on = chosen.includes(it.key);
          return (
            <button
              key={it.key}
              data-testid={`item-${it.key}`}
              onClick={() => toggle(it.key)}
              className={`rounded-sm border px-3 py-2 text-left transition-colors ${
                on
                  ? "border-phosphor bg-phosphor-deep text-phosphor"
                  : "border-phosphor-dim text-phosphor/70 hover:bg-phosphor-deep/50"
              }`}
            >
              <div className="text-xs">{it.name}</div>
              <div className="mt-0.5 text-[10px] leading-tight text-phosphor-dim">
                {it.description}
              </div>
            </button>
          );
        })}
      </div>

      <div className="flex items-center gap-4">
        <span className="text-xs text-phosphor-dim">
          {chosen.length}/{pick} selezionate
        </span>
        <button
          data-testid="begin"
          disabled={!ready || busy}
          onClick={() => onBegin(chosen)}
          className="rounded-sm border border-phosphor px-5 py-2 text-sm tracking-widest text-phosphor transition-colors enabled:hover:bg-phosphor enabled:hover:text-bg disabled:opacity-30"
        >
          {busy ? "AVVIO…" : "DISTACCO ›"}
        </button>
      </div>
    </div>
  );
}
