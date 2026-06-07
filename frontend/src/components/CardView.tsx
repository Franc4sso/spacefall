import { useEffect, useRef, useState } from "react";
import type { Card } from "../api";

type Props = {
  card: Card;
  busy: boolean;
  onChoose: (index: number) => void;
  onAdvance: () => void;
  relevantItems: string[];
};

function artClass(key: string): string {
  if (/crisis|breach|alarm|trap|mutiny|cascade|collapse/.test(key)) return "card-art-crisis";
  if (/silent|quiet|moment|window|hum/.test(key)) return "card-art-silent";
  if (/moral|dilemma|last_dose|log_falsif/.test(key)) return "card-art-moral";
  if (/system|power|hull|life_support|repair/.test(key)) return "card-art-system";
  if (/crew|doctor|engineer|pilot|ayaka|marco|char/.test(key)) return "card-art-character";
  return "card-art-exploration";
}

export function CardView({ card, busy, onChoose, onAdvance, relevantItems }: Props) {
  const available = card.choices.filter(c => c.available);
  const binary = available.length === 2;
  const isSilent = card.choices.length === 0;

  const [drag, setDrag] = useState(0);
  const startX = useRef<number | null>(null);
  const COMMIT = 95;

  function onPointerDown(e: React.PointerEvent) {
    if (!binary || busy || isSilent) return;
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
    if (d >= COMMIT && available[1]) onChoose(available[1].index);
    else if (d <= -COMMIT && available[0]) onChoose(available[0].index);
  }

  const tilt = Math.max(-10, Math.min(10, drag / 10));
  const tellSide = drag >= 50 ? "tell-right" : drag <= -50 ? "tell-left" : "";

  if (isSilent) {
    return <SilentCardInline key={card.key} card={card} onAdvance={onAdvance} />;
  }

  return (
    <div style={{ width: "100%", maxWidth: 420, display: "flex", flexDirection: "column", gap: 10 }}>
      <div
        key={card.key}
        data-testid="card"
        className={`card-shell card-enter ${tellSide}`}
        style={{ transform: `translateX(${drag}px) rotate(${tilt}deg)` }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
      >
        {/* Art zone */}
        <div className={`card-art ${artClass(card.key)}`}>
          <div className="card-art-stars" />
          {card.speaker && (
            <div style={{
              position: "absolute", bottom: 10, left: 14,
              background: "rgba(0,0,0,0.65)", backdropFilter: "blur(4px)",
              borderRadius: 6, padding: "3px 10px",
              fontSize: 11, fontWeight: 700, letterSpacing: "0.12em",
              color: "var(--color-cyan)",
            }}>
              {card.speaker.toUpperCase()}
            </div>
          )}
          {binary && (
            <div style={{
              position: "absolute", bottom: 10, right: 14,
              display: "flex", gap: 12, fontSize: 10,
              color: "rgba(255,255,255,0.3)",
              pointerEvents: "none",
            }}>
              <span style={drag <= -50 ? { color: "var(--color-red)" } : {}}>
                ◄ {available[0]?.label}
              </span>
              <span style={drag >= 50 ? { color: "var(--color-cyan)" } : {}}>
                {available[1]?.label} ►
              </span>
            </div>
          )}
        </div>

        {/* Text */}
        <div style={{ padding: "14px 18px 18px" }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: "var(--color-text)", lineHeight: 1.3 }}>
            {card.title}
          </h2>
          <p style={{ margin: "8px 0 0", fontSize: 14, lineHeight: 1.65, color: "rgba(232,244,253,0.82)" }}>
            {card.body}
          </p>
        </div>
      </div>

      {/* Choices */}
      <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
        {card.choices.map(c => {
          const itemGated = c.requires_item !== null;
          const isRelevant = c.requires_item && relevantItems.includes(c.requires_item);
          return (
            <button
              key={c.index}
              data-testid={`choice-${c.index}`}
              disabled={!c.available || busy}
              onClick={() => onChoose(c.index)}
              className={`choice-btn ${itemGated ? "item-gated" : ""}`}
            >
              <span>{c.label}</span>
              <div style={{ display: "flex", alignItems: "center", gap: 8, flexShrink: 0 }}>
                {itemGated && (
                  <span style={{
                    fontSize: 10, fontFamily: "var(--font-mono)",
                    color: isRelevant ? "var(--color-gold)" : "var(--color-text-muted)",
                  }}>
                    ✦ {c.requires_item}
                  </span>
                )}
                {c.hint && (
                  <span style={{ fontSize: 11, fontStyle: "italic", color: "var(--color-text-dim)" }}>
                    {c.hint}
                  </span>
                )}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}

function SilentCardInline({ card, onAdvance }: { card: Card; onAdvance: () => void }) {
  const [progress, setProgress] = useState(0);
  const fired = useRef(false);

  useEffect(() => {
    const start = performance.now();
    const duration = 4000;
    let raf = requestAnimationFrame(function tick(now: number) {
      const pct = Math.min(100, ((now - start) / duration) * 100);
      setProgress(pct);
      if (pct < 100) {
        raf = requestAnimationFrame(tick);
      } else if (!fired.current) {
        fired.current = true;
        onAdvance();
      }
    });
    return () => cancelAnimationFrame(raf);
    // Re-arm for each distinct silent card (the component is also keyed by card.key).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [card.key]);

  return (
    <div className="card-shell card-enter fade-in-up" style={{ maxWidth: 420, width: "100%" }}>
      <div className="card-art card-art-silent">
        <div className="card-art-stars" />
      </div>
      <div style={{ padding: "14px 18px 18px" }}>
        <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: "var(--color-text)", lineHeight: 1.3 }}>
          {card.title}
        </h2>
        <p style={{ margin: "8px 0 16px", fontSize: 14, lineHeight: 1.65, color: "rgba(232,244,253,0.65)", fontStyle: "italic" }}>
          {card.body}
        </p>
        <div className="bar-track" style={{ height: 2 }}>
          <div className="silent-progress" style={{ width: `${progress}%` }} />
        </div>
      </div>
    </div>
  );
}
