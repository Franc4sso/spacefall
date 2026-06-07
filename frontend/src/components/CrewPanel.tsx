import type { Character, Reaction } from "../api";

const ROLE_LABELS: Record<string, string> = {
  engineer: "Ingegnere", doctor: "Medico", pilot: "Pilota", survivor: "Superstite",
};

const EPITHET_LABELS: Record<string, string> = {
  il_generoso: "il Generoso",
  il_freddo: "il Freddo",
  l_imprudente: "l'Imprudente",
  il_prudente: "il Prudente",
  il_solitario: "il Solitario",
};

// Standing → qualitative band (never a number on screen).
function standingBand(s: number): { ring: string; word: string } {
  if (s <= -40) return { ring: "standing-hostile", word: "ostile" };
  if (s <= -15) return { ring: "standing-cold", word: "freddo" };
  if (s < 15) return { ring: "standing-neutral", word: "neutro" };
  if (s < 40) return { ring: "standing-trust", word: "fiducia" };
  return { ring: "standing-bond", word: "legame" };
}

type Props = {
  characters: Character[];
  epithet?: string | null;
  reactions?: Reaction[];
};

export function CrewPanel({ characters, epithet, reactions = [] }: Props) {
  const reactionByName = new Map(reactions.map((r) => [r.who, r]));

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      <div style={{ fontSize: 10, letterSpacing: "0.14em", color: "var(--color-text-muted)", fontFamily: "var(--font-mono)" }}>
        EQUIPAGGIO
      </div>

      {characters.map((c) => {
        const roleKey = c.role ?? "survivor";
        const initials = c.name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase();
        const stressPct = c.stress;
        const stressColor = stressPct >= 85 ? "var(--color-red)" : stressPct >= 60 ? "var(--color-orange)" : "var(--color-cyan-dim)";
        const band = standingBand(c.standing ?? 0);
        const away = c.away ?? false;
        const hunger = c.hunger ?? 0;
        const hungerClass = hunger >= 70 ? "starving" : hunger >= 40 ? "hungry" : "";
        const hungerWord = hunger >= 70 ? "allo stremo" : hunger >= 40 ? "affamato" : "";
        const reaction = c.alive ? reactionByName.get(c.name) : undefined;
        const avatarClass = !c.alive
          ? "crew-avatar dead"
          : away
            ? `crew-avatar ${roleKey} away`
            : `crew-avatar ${roleKey} ${reaction ? `react-${reaction.tone}` : band.ring} ${hungerClass}`;

        return (
          <div key={c.name} data-testid={`crew-${c.name}`}
               style={{ display: "flex", gap: 10, alignItems: "flex-start", opacity: c.alive ? 1 : 0.4 }}>
            <div className={avatarClass}>{initials}</div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline" }}>
                <span style={{ fontSize: 13, fontWeight: 600, textDecoration: c.alive ? "none" : "line-through", color: "var(--color-text)" }}>
                  {c.name}
                </span>
                <span style={{ fontSize: 10, color: "var(--color-text-muted)" }}>
                  {c.alive ? band.word : (ROLE_LABELS[roleKey] ?? roleKey)}
                </span>
              </div>
              {!c.alive ? (
                <div style={{ fontSize: 10, color: "var(--color-red)", marginTop: 2 }}>— perso —</div>
              ) : away ? (
                <div className="away-tag">● in spedizione · rientro g.{c.away_until}</div>
              ) : (
                <div style={{ marginTop: 4 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}>
                    <span style={{ fontSize: 10, color: "var(--color-text-dim)" }}>stress</span>
                    <span style={{ fontSize: 10, fontFamily: "var(--font-mono)", color: stressColor }}>{stressPct}</span>
                  </div>
                  <div className="bar-track" style={{ height: 4 }}>
                    <div style={{
                      height: "100%", borderRadius: 4, width: `${stressPct}%`,
                      background: stressColor, transition: "width 500ms ease",
                    }} />
                  </div>
                  {hungerWord && (
                    <div className={`hunger-tag ${hungerClass}`}>● {hungerWord}</div>
                  )}
                  {reaction && (
                    <div className={`react-line ${reaction.tone}`}>«{reaction.line}»</div>
                  )}
                </div>
              )}
            </div>
          </div>
        );
      })}

      {epithet && (
        <div style={{
          marginTop: 4, padding: "6px 10px",
          background: "rgba(255,209,102,0.06)",
          border: "1px solid var(--color-gold-dim)",
          borderRadius: 8,
          fontSize: 11, color: "var(--color-gold)", fontStyle: "italic",
        }}>
          Comandante {EPITHET_LABELS[epithet] ?? epithet}
        </div>
      )}
    </div>
  );
}
