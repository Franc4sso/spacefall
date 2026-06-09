import type { Ending } from "../api";

type Props = {
  ending: Ending;
  day: number;
  onRestart: () => void;
};

export function GameOverScreen({ ending, day, onRestart }: Props) {
  const win = ending?.type === "win";
  const accent = win ? "var(--color-cyan)" : "var(--color-red)";
  const accentGlow = win ? "var(--color-cyan-glow)" : "var(--color-red-glow)";

  return (
    <div className="jolt" style={{
      height: "100%",
      display: "flex",
      flexDirection: "column",
      alignItems: "center",
      justifyContent: "center",
      gap: 20,
      padding: 32,
      textAlign: "center",
      maxWidth: 480,
      margin: "0 auto",
    }}>
      <div style={{
        fontSize: 11,
        fontFamily: "var(--font-mono)",
        letterSpacing: "0.3em",
        color: accent,
        opacity: 0.8,
      }}>
        {win ? "// SEGNALE TRASMESSO" : "// SEGNALE PERSO"}
      </div>

      <h1
        className="glitch"
        data-testid="ending-name"
        style={{
          margin: 0,
          fontSize: 36,
          fontFamily: "var(--font-mono)",
          letterSpacing: "0.15em",
          color: accent,
          textShadow: `0 0 30px ${accentGlow}`,
        }}
      >
        {ending?.name ?? "FINE"}
      </h1>

      <p style={{
        margin: 0,
        maxWidth: 380,
        fontSize: 14,
        lineHeight: 1.7,
        color: "rgba(232,244,253,0.75)",
      }}>
        {ending?.text}
      </p>

      {ending?.epilogue?.map((section) => (
        <div key={section.title} style={{ marginTop: 16, width: "100%", maxWidth: 380, textAlign: "left" }}>
          <div style={{
            fontSize: 11, fontFamily: "var(--font-mono)", letterSpacing: "0.2em",
            color: accent, opacity: 0.7, marginBottom: 6,
          }}>
            {section.title.toUpperCase()}
          </div>
          {section.lines.map((line, i) => (
            <p key={i} style={{ margin: "2px 0", fontSize: 13, lineHeight: 1.6, color: "rgba(232,244,253,0.8)" }}>
              {line}
            </p>
          ))}
        </div>
      ))}

      {ending?.epithet && (
        <div style={{
          padding: "6px 14px",
          border: "1px solid var(--color-gold-dim)",
          borderRadius: 8,
          fontSize: 12,
          color: "var(--color-gold)",
          fontStyle: "italic",
          background: "rgba(255,209,102,0.06)",
        }}>
          Comandante {ending.epithet}
        </div>
      )}

      <p style={{
        margin: 0,
        fontSize: 12,
        fontFamily: "var(--font-mono)",
        color: "var(--color-text-muted)",
      }}>
        giorno {day}
      </p>

      <button
        data-testid="restart"
        onClick={onRestart}
        style={{
          marginTop: 8,
          padding: "10px 28px",
          borderRadius: 10,
          border: `1px solid ${accent}`,
          background: "transparent",
          color: accent,
          fontSize: 13,
          fontFamily: "var(--font-mono)",
          letterSpacing: "0.18em",
          cursor: "pointer",
          transition: "background 150ms ease",
        }}
        onMouseOver={e => (e.currentTarget.style.background = `rgba(0,0,0,0.3)`)}
        onMouseOut={e => (e.currentTarget.style.background = "transparent")}
      >
        ANCORA
      </button>
    </div>
  );
}
