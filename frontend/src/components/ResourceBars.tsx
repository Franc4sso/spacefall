import type { ResourceMeta } from "../api";

// Italian labels for the English resource codes (player-facing text is Italian).
const LABELS: Record<string, string> = {
  oxygen: "OSSIGENO",
  food: "CIBO",
  power: "ENERGIA",
  morale: "MORALE",
  hull: "SCAFO",
};

type Props = {
  resources: Record<string, number>;
  meta: Record<string, ResourceMeta>;
};

export function ResourceBars({ resources, meta }: Props) {
  return (
    <div className="flex flex-col gap-3">
      {Object.entries(resources).map(([code, value]) => {
        const max = meta[code]?.max ?? 100;
        const pct = Math.max(0, Math.min(100, (value / max) * 100));
        const twoSided = meta[code]?.two_sided ?? false;
        // Critical at the low end always; for two-sided resources, also at the top.
        const critical = pct <= 20 || (twoSided && pct >= 92);

        return (
          <div key={code} data-testid={`bar-${code}`}>
            <div className="flex justify-between text-[10px] tracking-widest text-phosphor-dim">
              <span>{LABELS[code] ?? code.toUpperCase()}</span>
              <span className={critical ? "text-alarm" : ""}>{value}</span>
            </div>
            <div className="bar-track mt-1 h-2 w-full overflow-hidden rounded-sm">
              <div
                className={`bar-fill h-full rounded-sm ${critical ? "critical" : ""}`}
                style={{ width: `${pct}%` }}
              />
            </div>
          </div>
        );
      })}
    </div>
  );
}
