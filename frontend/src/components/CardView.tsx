import { useRef, useState } from "react";
import type { Card } from "../api";

type Props = {
  card: Card;
  busy: boolean;
  onChoose: (index: number) => void;
};

// The decision card. Buttons are the reliable path (any choice count, keyboard-
// accessible). On top of that, when a card is a binary choice we add the Reigns
// drag-to-swipe "tell": the card tilts toward the side you're dragging and
// previews which choice you're about to commit. Dragging never gates input —
// you can also just tap a button. Pointer-based, no animation library, so it
// stays light and interruptible (flow §1.5).
export function CardView({ card, busy, onChoose }: Props) {
  const available = card.choices.filter((c) => c.available);
  const binary = available.length === 2;

  const [drag, setDrag] = useState(0); // px offset, only used in binary mode
  const startX = useRef<number | null>(null);

  // Map a positive drag (right) to the second available choice, negative to the
  // first — Reigns convention. Threshold to commit.
  const COMMIT = 90;
  const rightChoice = available[1];
  const leftChoice = available[0];

  function onPointerDown(e: React.PointerEvent) {
    if (!binary || busy) return;
    startX.current = e.clientX;
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
  }
  function onPointerMove(e: React.PointerEvent) {
    if (startX.current === null) return;
    setDrag(e.clientX - startX.current);
  }
  function onPointerUp() {
    if (startX.current === null) return;
    const d = drag;
    startX.current = null;
    setDrag(0);
    if (d >= COMMIT && rightChoice) onChoose(rightChoice.index);
    else if (d <= -COMMIT && leftChoice) onChoose(leftChoice.index);
  }

  const tilt = Math.max(-12, Math.min(12, drag / 8));
  const tellSide = drag >= 40 ? "tell-right" : drag <= -40 ? "tell-left" : "";

  return (
    <div className="flex w-full max-w-md flex-col items-stretch">
      <div
        key={card.key}
        data-testid="card"
        className={`card-shell card-enter relative rounded-md border border-phosphor-dim bg-phosphor-deep/40 p-5 ${tellSide}`}
        style={{ transform: `translateX(${drag}px) rotate(${tilt}deg)` }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
      >
        {/* a small SVG console glyph — atmosphere, not a text box */}
        <Glyph />
        {card.speaker && (
          <div className="mb-1 text-[11px] tracking-widest text-amber">
            {card.speaker.toUpperCase()}
          </div>
        )}
        <h2 className="text-lg leading-tight text-phosphor">{card.title}</h2>
        <p className="mt-2 text-sm leading-snug text-phosphor/85">{card.body}</p>

        {/* drag hint for binary cards */}
        {binary && (
          <div className="pointer-events-none mt-3 flex justify-between text-[10px] text-phosphor-dim">
            <span className={drag <= -40 ? "text-alarm" : ""}>‹ {leftChoice?.label}</span>
            <span className={drag >= 40 ? "text-phosphor" : ""}>{rightChoice?.label} ›</span>
          </div>
        )}
      </div>

      <div className="mt-4 flex flex-col gap-2">
        {card.choices.map((c) => (
          <button
            key={c.index}
            data-testid={`choice-${c.index}`}
            disabled={!c.available || busy}
            onClick={() => onChoose(c.index)}
            className="group flex items-center justify-between rounded-sm border border-phosphor-dim px-3 py-2 text-left text-sm transition-colors enabled:hover:bg-phosphor-deep disabled:cursor-not-allowed disabled:opacity-35"
          >
            <span>{c.label}</span>
            {c.hint && <span className="ml-3 text-[10px] italic text-amber">{c.hint}</span>}
          </button>
        ))}
      </div>
    </div>
  );
}

function Glyph() {
  return (
    <svg
      className="absolute right-4 top-4 opacity-30"
      width="34"
      height="34"
      viewBox="0 0 34 34"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.2"
      aria-hidden
    >
      <circle cx="17" cy="17" r="14" />
      <path d="M17 5 L17 17 L25 22" />
      <path d="M3 17 L7 17 M27 17 L31 17" />
    </svg>
  );
}
