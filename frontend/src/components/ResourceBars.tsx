import type { ResourceMeta } from "../api";

const LABELS: Record<string, string> = {
  oxygen: "Ossigeno", food: "Cibo", power: "Energia", morale: "Morale", hull: "Scafo",
};
const ICONS: Record<string, string> = {
  oxygen: "○", food: "◇", power: "◈", morale: "♡", hull: "△",
};

type Props = { resources: Record<string, number>; meta: Record<string, ResourceMeta> };

export function ResourceBars({ resources, meta }: Props) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ fontSize: 10, letterSpacing: "0.14em", color: "var(--color-text-muted)", fontFamily: "var(--font-mono)", marginBottom: 2 }}>
        RISORSE
      </div>
      {Object.entries(resources).map(([code, value]) => {
        const max = meta[code]?.max ?? 100;
        const pct = Math.max(0, Math.min(100, (value / max) * 100));
        const fillClass = pct <= 20 ? "critical" : pct <= 40 ? "warning" : "";
        const valueColor = pct <= 20 ? "var(--color-red)" : pct <= 40 ? "var(--color-orange)" : "var(--color-text)";

        return (
          <div key={code} data-testid={`bar-${code}`}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 4 }}>
              <span style={{ fontSize: 11, color: "var(--color-text-dim)", display: "flex", alignItems: "center", gap: 5 }}>
                <span style={{ fontSize: 10, opacity: 0.7 }}>{ICONS[code] ?? "·"}</span>
                {LABELS[code] ?? code}
              </span>
              <span style={{ fontSize: 11, fontFamily: "var(--font-mono)", fontWeight: 700, color: valueColor }}>
                {value}
              </span>
            </div>
            <div className="bar-track" style={{ height: 5 }}>
              <div className={`bar-fill ${fillClass}`} style={{ width: `${pct}%` }} />
            </div>
          </div>
        );
      })}
    </div>
  );
}
