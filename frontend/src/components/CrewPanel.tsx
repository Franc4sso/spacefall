import type { Character } from "../api";

const ROLE_LABELS: Record<string, string> = {
  engineer: "ingegnere",
  doctor: "medico",
  pilot: "pilota",
  survivor: "superstite",
};

function stressColor(stress: number): string {
  if (stress >= 85) return "text-alarm";
  if (stress >= 60) return "text-amber";
  return "text-phosphor-dim";
}

export function CrewPanel({ characters }: { characters: Character[]; epithet?: string | null }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="text-[10px] tracking-widest text-phosphor-dim">EQUIPAGGIO</div>
      {characters.map((c) => (
        <div
          key={c.name}
          data-testid={`crew-${c.name}`}
          className={`border-l-2 pl-2 ${
            c.alive ? "border-phosphor-dim" : "border-transparent opacity-40"
          }`}
        >
          <div className="flex items-baseline justify-between">
            <span className={c.alive ? "" : "line-through"}>{c.name}</span>
            <span className="text-[10px] text-phosphor-dim">
              {ROLE_LABELS[c.role ?? ""] ?? c.role}
            </span>
          </div>
          {c.alive ? (
            <div className="mt-0.5 text-[10px]">
              <span className="text-phosphor-dim">stress </span>
              <span className={stressColor(c.stress)}>{c.stress}</span>
            </div>
          ) : (
            <div className="mt-0.5 text-[10px] text-alarm">— perso —</div>
          )}
        </div>
      ))}
    </div>
  );
}
