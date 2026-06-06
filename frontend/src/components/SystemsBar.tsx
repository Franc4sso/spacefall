type SystemsBarProps = {
  systems: Record<string, { efficiency: number }>;
  crewTrust: number;
};

const SYSTEM_LABELS: Record<string, string> = {
  life_support:   "Vita Artif.",
  power_grid:     "Rete",
  hull_integrity: "Scafo Integ.",
};

function dotClass(eff: number): string {
  if (eff >= 60) return "ok";
  if (eff >= 35) return "warning";
  return "critical";
}

export function SystemsBar({ systems, crewTrust }: SystemsBarProps) {
  const trustLow = crewTrust < 30;

  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 20, padding: "6px 16px",
      fontSize: 11, color: "var(--color-text-dim)", fontFamily: "var(--font-mono)",
    }}>
      {Object.entries(systems).map(([code, s]) => (
        <div key={code} style={{ display: "flex", alignItems: "center", gap: 6 }}>
          <span className={`system-dot ${dotClass(s.efficiency)}`} />
          <span>{SYSTEM_LABELS[code] ?? code}</span>
          <span style={{ color: "var(--color-text-muted)" }}>{s.efficiency}%</span>
        </div>
      ))}

      <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 8 }}>
        <span>Fiducia</span>
        <div className="bar-track" style={{ width: 56, height: 5 }}>
          <div className={`trust-bar-fill ${crewTrust < 30 ? "low" : ""}`}
               style={{ width: `${crewTrust}%`, height: "100%" }} />
        </div>
        <span style={{ fontWeight: 700, color: trustLow ? "var(--color-red)" : "var(--color-text-dim)" }}>
          {crewTrust}
        </span>
        {trustLow && <span style={{ color: "var(--color-red)", fontSize: 10 }}>⚠</span>}
      </div>
    </div>
  );
}
