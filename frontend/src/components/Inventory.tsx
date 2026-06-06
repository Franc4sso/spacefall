import type { Item } from "../api";

export function Inventory({ items }: { items: Item[] }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-[10px] tracking-widest text-phosphor-dim">DOTAZIONE</span>
      {items.length === 0 && <span className="text-[10px] text-phosphor-dim">— vuota —</span>}
      {items.map((it) => (
        <span
          key={it.key}
          title={it.description}
          className="rounded-sm border border-phosphor-dim px-2 py-0.5 text-[11px]"
        >
          {it.name}
        </span>
      ))}
    </div>
  );
}
