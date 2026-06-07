import type { ChoiceLogEntry } from "../api";

type Props = {
  log: ChoiceLogEntry[];
  open: boolean;
  onClose: () => void;
};

export function Diario({ log, open, onClose }: Props) {
  if (!open) return null;

  const entries = [...log].reverse();

  return (
    <div
      onClick={onClose}
      style={{
        position: "absolute", inset: 0, zIndex: 40,
        background: "rgba(3,6,14,0.7)", backdropFilter: "blur(2px)",
        display: "flex", justifyContent: "center", alignItems: "flex-start",
        paddingTop: 60,
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: "min(520px, 92vw)", maxHeight: "70vh", overflowY: "auto",
          background: "var(--color-surface-card)", border: "1px solid var(--color-border)",
          borderRadius: 14, padding: "18px 20px",
          boxShadow: "0 16px 48px rgba(0,0,0,0.6)",
        }}
      >
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 14 }}>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, letterSpacing: "0.18em", color: "var(--color-cyan)" }}>
            DIARIO DI BORDO
          </span>
          <button onClick={onClose} style={{
            background: "transparent", border: "none", color: "var(--color-text-dim)",
            fontSize: 18, cursor: "pointer", lineHeight: 1,
          }}>×</button>
        </div>

        {entries.length === 0 ? (
          <div style={{ color: "var(--color-text-muted)", fontSize: 13, fontStyle: "italic" }}>
            Ancora nessuna decisione registrata.
          </div>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            {entries.map((e, i) => (
              <div key={`${e.day}-${e.event_key}-${i}`} style={{ borderLeft: "2px solid var(--color-border-hi)", paddingLeft: 12 }}>
                <div style={{ fontSize: 10, fontFamily: "var(--font-mono)", color: "var(--color-text-muted)" }}>
                  GIORNO {e.day}
                </div>
                <div style={{ fontSize: 13, color: "var(--color-text)", marginTop: 2 }}>
                  {e.choice_label}
                </div>
                {e.reaction_summary && (
                  <div style={{ fontSize: 12, fontStyle: "italic", color: "var(--color-orange)", marginTop: 3 }}>
                    {e.reaction_summary}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
