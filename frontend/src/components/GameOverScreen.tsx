import type { Ending } from "../api";

type Props = {
  ending: Ending;
  day: number;
  onRestart: () => void;
};

// Game over. One screen, one tap to start again (the second tap is DISTACCO on
// the start screen) — fast restart is part of flow & replayability (§1.5/§2.1).
export function GameOverScreen({ ending, day, onRestart }: Props) {
  const win = ending?.type === "win";

  return (
    <div className="jolt mx-auto flex h-full max-w-lg flex-col items-center justify-center gap-6 p-8 text-center">
      <div className={`text-xs tracking-[0.3em] ${win ? "text-phosphor" : "text-alarm"}`}>
        {win ? "// SEGNALE TRASMESSO" : "// SEGNALE PERSO"}
      </div>

      <h1
        className={`glitch text-4xl tracking-widest ${win ? "text-phosphor" : "text-alarm"}`}
        data-testid="ending-name"
      >
        {ending?.name ?? "FINE"}
      </h1>

      <p className="max-w-md text-sm leading-relaxed text-phosphor/80">{ending?.text}</p>

      <p className="text-xs text-phosphor-dim">giorno {day}</p>

      <button
        data-testid="restart"
        onClick={onRestart}
        className="cursor mt-2 rounded-sm border border-phosphor px-6 py-2 text-sm tracking-widest text-phosphor transition-colors hover:bg-phosphor hover:text-bg"
      >
        ANCORA
      </button>
    </div>
  );
}
