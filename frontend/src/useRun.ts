import { useCallback, useState } from "react";
import { advanceRun, resolveChoice, startRun, type RunState, type Reaction } from "./api";

// Drives a single run. State is server-authoritative: every choice POST returns
// the *next* state (card included), so resolving and "prefetching the next
// card" are the same round-trip — there is never a second request to wait on
// mid-run (flow §1.5). The UI animates the swipe optimistically while that one
// request is in flight and never blocks input on it.

export type RunPhase = "start" | "playing" | "ended";

export function useRun(handle: string) {
  const [run, setRun] = useState<RunState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const begin = useCallback(
    async (items: string[], theme: string = "space") => {
      setError(null);
      setBusy(true);
      try {
        const state = await startRun({ items, handle, theme });
        setRun(state);
      } catch (e) {
        setError(e instanceof Error ? e.message : "Errore di avvio");
      } finally {
        setBusy(false);
      }
    },
    [handle],
  );

  // Resolve a choice. Returns the log line and reactions so the UI can flash
  // it and animate crew. The next card arrives in the same response — no extra fetch.
  const choose = useCallback(
    async (
      choiceIndex: number,
    ): Promise<{ log: string | null; reactions: Reaction[]; effects: unknown[] } | null> => {
      if (!run || busy) return null;
      setBusy(true);
      try {
        const res = await resolveChoice(run.id, choiceIndex);
        setRun(res.state);
        return {
          log: res.resolution.log ?? null,
          reactions: res.resolution.reactions ?? [],
          effects: res.resolution.effects ?? [],
        };
      } catch (e) {
        setError(e instanceof Error ? e.message : "Errore");
        return null;
      } finally {
        setBusy(false);
      }
    },
    [run, busy],
  );

  const advance = useCallback(async () => {
    if (!run || busy) return;
    setBusy(true);
    try {
      const state = await advanceRun(run.id);
      setRun(state);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Errore");
    } finally {
      setBusy(false);
    }
  }, [run, busy]);

  const reset = useCallback(() => {
    setRun(null);
    setError(null);
  }, []);

  const phase: RunPhase = !run ? "start" : run.status === "ended" ? "ended" : "playing";

  return { run, phase, busy, error, begin, choose, advance, reset };
}
