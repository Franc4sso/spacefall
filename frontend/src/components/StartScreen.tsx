import { useEffect, useState } from "react";
import { fetchItems, type Item } from "../api";

type Props = {
  handle: string;
  busy: boolean;
  onBegin: (items: string[]) => void;
};

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
    <div style={{
      height: "100%",
      display: "flex",
      flexDirection: "column",
      alignItems: "center",
      justifyContent: "center",
      gap: 24,
      padding: 24,
      maxWidth: 640,
      margin: "0 auto",
    }}>
      <header style={{ textAlign: "center" }}>
        <h1 style={{
          margin: 0,
          fontSize: 28,
          fontFamily: "var(--font-mono)",
          letterSpacing: "0.3em",
          color: "var(--color-cyan)",
          textShadow: "0 0 20px var(--color-cyan-glow)",
        }}>
          STARFALL STATION
        </h1>
        <p style={{
          margin: "6px 0 0",
          fontSize: 11,
          fontFamily: "var(--font-mono)",
          letterSpacing: "0.2em",
          color: "var(--color-text-muted)",
        }}>
          // protocollo di sopravvivenza
        </p>
      </header>

      <p style={{ margin: 0, fontSize: 13, color: "rgba(232,244,253,0.7)", textAlign: "center" }}>
        La stazione è compromessa. Scegli{" "}
        <span style={{ color: "var(--color-text)", fontWeight: 600 }}>{pick}</span>{" "}
        dotazioni prima del distacco.
      </p>

      {loadError && (
        <p style={{ margin: 0, fontSize: 13, color: "var(--color-red)" }}>
          Impossibile contattare la stazione.
        </p>
      )}

      <div style={{
        display: "grid",
        gridTemplateColumns: "repeat(2, 1fr)",
        gap: 8,
        width: "100%",
        maxHeight: "46vh",
        overflowY: "auto",
      }}>
        {items.map((it) => {
          const on = chosen.includes(it.key);
          return (
            <button
              key={it.key}
              data-testid={`item-${it.key}`}
              onClick={() => toggle(it.key)}
              style={{
                padding: "10px 14px",
                textAlign: "left",
                borderRadius: 10,
                border: `1px solid ${on ? "var(--color-cyan-dim)" : "var(--color-border)"}`,
                background: on ? "rgba(0,212,255,0.08)" : "var(--color-surface)",
                color: on ? "var(--color-cyan)" : "rgba(232,244,253,0.65)",
                cursor: "pointer",
                transition: "border-color 150ms ease, background 150ms ease, color 150ms ease",
                fontFamily: "var(--font-ui)",
              }}
            >
              <div style={{ fontSize: 13, fontWeight: 600 }}>{it.name}</div>
              <div style={{ marginTop: 3, fontSize: 11, lineHeight: 1.4, color: on ? "rgba(0,212,255,0.7)" : "var(--color-text-dim)" }}>
                {it.description}
              </div>
            </button>
          );
        })}
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 16 }}>
        <span style={{ fontSize: 12, fontFamily: "var(--font-mono)", color: "var(--color-text-dim)" }}>
          {chosen.length}/{pick} selezionate
        </span>
        <button
          data-testid="begin"
          disabled={!ready || busy}
          onClick={() => onBegin(chosen)}
          style={{
            padding: "9px 24px",
            borderRadius: 10,
            border: "1px solid var(--color-cyan-dim)",
            background: ready && !busy ? "rgba(0,212,255,0.1)" : "transparent",
            color: ready && !busy ? "var(--color-cyan)" : "var(--color-text-muted)",
            fontSize: 13,
            fontFamily: "var(--font-mono)",
            letterSpacing: "0.15em",
            cursor: ready && !busy ? "pointer" : "not-allowed",
            opacity: !ready || busy ? 0.4 : 1,
            transition: "background 150ms ease, color 150ms ease, opacity 150ms ease",
          }}
        >
          {busy ? "AVVIO…" : "DISTACCO ›"}
        </button>
      </div>
    </div>
  );
}
