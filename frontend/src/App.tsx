import { useCallback, useState } from "react";
import type { Reaction } from "./api";
import { GameOverScreen } from "./components/GameOverScreen";
import { GameScreen } from "./components/GameScreen";
import { StartScreen } from "./components/StartScreen";
import { useRun } from "./useRun";

// One stable handle per browser → the profile that carries cross-run memory and
// unlocks. No login (out of scope); a random handle persisted locally is enough.
function useHandle(): string {
  const [handle] = useState(() => {
    const existing = localStorage.getItem("starfall_handle");
    if (existing) return existing;
    const fresh = "pilot-" + Math.random().toString(36).slice(2, 9);
    localStorage.setItem("starfall_handle", fresh);
    return fresh;
  });
  return handle;
}

export default function App() {
  const handle = useHandle();
  const { run, phase, busy, error, begin, choose, advance, reset } = useRun(handle);
  const [lastLog, setLastLog] = useState<string | null>(null);
  const [reactions, setReactions] = useState<Reaction[]>([]);

  const onChoose = useCallback(
    async (index: number) => {
      const result = await choose(index);
      setLastLog(result?.log ?? null);
      setReactions(result?.reactions ?? []);
    },
    [choose],
  );

  return (
    <div style={{ height: "100%", background: "var(--color-bg)", position: "relative" }}>
      {error && (
        <div style={{
          position: "absolute", top: 12, left: "50%", transform: "translateX(-50%)",
          zIndex: 50, border: "1px solid var(--color-red)", borderRadius: 6,
          padding: "4px 12px", fontSize: 12, color: "var(--color-red)",
          background: "var(--color-surface)",
        }}>
          {error}
        </div>
      )}

      {phase === "start" && (
        <StartScreen handle={handle} busy={busy} onBegin={begin} />
      )}

      {phase === "playing" && run && (
        <GameScreen
          run={run}
          busy={busy}
          lastLog={lastLog}
          reactions={reactions}
          onChoose={onChoose}
          onAdvance={advance}
        />
      )}

      {phase === "ended" && run && (
        <GameOverScreen ending={run.ending} day={run.day} onRestart={reset} />
      )}
    </div>
  );
}
