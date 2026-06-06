import type { Item } from "../api";

type Props = { items: Item[]; relevantItems?: string[] };

export function Inventory({ items, relevantItems = [] }: Props) {
  if (items.length === 0) return null;

  return (
    <div style={{
      display: "flex", gap: 8, padding: "6px 16px",
      flexWrap: "nowrap", overflowX: "auto", alignItems: "center",
    }}>
      <span style={{
        fontSize: 10, letterSpacing: "0.14em",
        color: "var(--color-text-muted)", flexShrink: 0,
        fontFamily: "var(--font-mono)",
      }}>
        ZAINO
      </span>
      {items.map(item => {
        const relevant = relevantItems.includes(item.key);
        return (
          <div key={item.key} className={`item-pill ${relevant ? "relevant" : ""}`} title={item.description}>
            {relevant && <span style={{ marginRight: 4 }}>✦</span>}
            {item.name}
          </div>
        );
      })}
    </div>
  );
}
