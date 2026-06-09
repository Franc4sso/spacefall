<?php

namespace App\Game\Engine;

/**
 * Builds the sectioned end-of-run epilogue from the run's facts: the outcome,
 * who fell (death_log), the key choices that defined the run (witness flags),
 * what became of the survivors, and the earned epithet. Pure and config-driven;
 * each section is terse. Empty sections are omitted.
 */
final class EpilogueComposer
{
    /**
     * @param  array<string,mixed>  $ending  the matched ending config (key/name/text)
     * @return list<array{title: string, lines: list<string>}>
     */
    public function compose(RunState $state, array $ending): array
    {
        $sections = [];

        $sections[] = ['title' => 'Esito', 'lines' => [(string) ($ending['text'] ?? '')]];

        $causes = config('game.epilogue.cause_phrases', []);
        $fallen = [];
        foreach ($state->deathLog as $d) {
            $name = $d['name'] ?? '?';
            $day = $d['day'] ?? '?';
            $phrase = $causes[$d['cause'] ?? 'event'] ?? 'caduto';
            $fallen[] = "{$name}, giorno {$day}. " . ucfirst($phrase) . '.';
        }
        if ($fallen !== []) {
            $sections[] = ['title' => 'Caduti', 'lines' => $fallen];
        }

        $witness = config('game.epilogue.witness_flags', []);
        $choiceLines = [];
        foreach ($witness as $flag => $line) {
            if (($state->flags[$flag] ?? false) === true) {
                $choiceLines[] = $line;
            }
        }
        if ($choiceLines !== []) {
            $sections[] = ['title' => 'Le tue scelte', 'lines' => $choiceLines];
        }

        $survLines = [];
        foreach ($state->characters as $c) {
            if (! ($c['alive'] ?? true)) {
                continue;
            }
            $name = $c['name'] ?? '?';
            $stress = (int) ($c['stress'] ?? 0);
            $standing = (int) ($state->flags['standing_' . strtolower($name)] ?? 0);
            $survLines[] = "{$name}: " . $this->survivorLine($stress, $standing);
        }
        if ($survLines !== []) {
            $sections[] = ['title' => 'I superstiti', 'lines' => $survLines];
        }

        $epithet = $state->profileFlags['epithet'] ?? null;
        if ($epithet !== null) {
            $sections[] = ['title' => 'Come ti ricorderanno', 'lines' => [$this->epithetLine((string) $epithet)]];
        }

        return $sections;
    }

    private function survivorLine(int $stress, int $standing): string
    {
        if ($standing <= -25) {
            return 'vivo, ma non ti perdona.';
        }
        if ($standing >= 25) {
            return 'vivo. Vi siete capiti.';
        }
        if ($stress >= 70) {
            return 'vivo, ma a pezzi.';
        }
        return 'vivo. Tira avanti.';
    }

    private function epithetLine(string $epithet): string
    {
        return match ($epithet) {
            'il_freddo' => 'Il Freddo. Hai scelto la sopravvivenza sopra ogni cosa.',
            'il_generoso' => 'Il Generoso. Hai dato, anche quando non potevi.',
            'l_imprudente' => "L'Imprudente. Hai ignorato gli avvertimenti.",
            'il_prudente' => 'Il Prudente. Hai temuto, e per questo siete vivi.',
            'il_solitario' => 'Il Solitario. Hai deciso da solo, sempre.',
            default => $epithet,
        };
    }
}
