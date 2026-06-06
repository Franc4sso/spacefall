import { useCallback, useState } from "react";
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
  const { run, phase, busy, error, begin, choose, reset } = useRun(handle);
  const [lastLog, setLastLog] = useState<string | null>(null);

  const onChoose = useCallback(
    async (index: number) => {
      const log = await choose(index);
      setLastLog(log);
    },
    [choose],
  );

  return (
    <div className="crt h-full">
      {error && (
        <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 rounded-sm border border-alarm px-3 py-1 text-xs text-alarm">
          {error}
        </div>
      )}

      {phase === "start" && (
        <StartScreen handle={handle} busy={busy} onBegin={begin} />
      )}

      {phase === "playing" && run && (
        <GameScreen run={run} busy={busy} lastLog={lastLog} onChoose={onChoose} />
      )}

      {phase === "ended" && run && (
        <GameOverScreen ending={run.ending} day={run.day} onRestart={reset} />
      )}
    </div>
  );
}
