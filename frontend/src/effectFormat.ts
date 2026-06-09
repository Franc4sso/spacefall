// Turns the raw effects array (returned by resolveChoice) into short Italian
// delta strings the UI can flash. The backend validates effect shapes; here we
// only read what we recognize and ignore the rest.

const RES_LABELS: Record<string, string> = {
  oxygen: "ossigeno",
  food: "cibo",
  power: "energia",
  morale: "morale",
  hull: "scafo",
};

function signed(n: number): string {
  return n > 0 ? `+${n}` : `−${Math.abs(n)}`; // real minus sign for negatives
}

export function formatEffects(effects: unknown[]): string[] {
  const out: string[] = [];
  for (const raw of effects ?? []) {
    if (typeof raw !== "object" || raw === null) continue;
    const e = raw as Record<string, unknown>;
    if ("resource" in e) {
      const code = String(e.resource);
      const label = RES_LABELS[code] ?? code;
      const delta = Number(e.delta ?? 0);
      if (delta !== 0) out.push(`${label} ${signed(delta)}`);
    } else if ("kill" in e) {
      out.push("una morte");
    } else if ("consume_item" in e) {
      out.push(`${String(e.consume_item)} consumato`);
    } else if ("grant_item" in e) {
      out.push(`${String(e.grant_item)} ottenuto`);
    } else if ("damage_system" in e) {
      out.push(`${String(e.damage_system)} danneggiato`);
    } else if ("character" in e) {
      const who = String(e.character);
      if (Number(e.stress ?? 0) > 0) out.push(`${who}: stress su`);
    }
  }
  return out;
}
