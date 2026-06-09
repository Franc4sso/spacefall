<?php

namespace App\Game\Engine;

/**
 * Turns a resolved choice/outcome into crew reactions — the "spoken memory"
 * that makes the crew feel alive. Pure and total: same inputs, same reactions.
 *
 * Priority: an outcome may declare explicit `reactions` (authored for strong
 * beats); otherwise reactions are derived from the choice's tags and the
 * outcome's effects. Every reaction names a character, a tone, and a line.
 *
 * Reactions also move standing: anger costs the reactor's trust in you,
 * approval earns it. The engine reads standingDelta() to apply that.
 */
final class ReactionDeriver
{
    private const TONE_STANDING = ['anger' => -10, 'approve' => 8, 'complicated' => 0];

    /**
     * @param  array<string,mixed>  $choice   the chosen option (carries `tags`)
     * @param  array<string,mixed>  $outcome  the resolved outcome (effects / explicit reactions)
     * @return list<array{who:string,tone:string,line:string}>
     */
    public function derive(array $choice, array $outcome, RunState $state): array
    {
        if (! empty($outcome['reactions']) && is_array($outcome['reactions'])) {
            return array_values(array_filter(
                $outcome['reactions'],
                fn ($r) => is_array($r) && $this->isAlive($r['who'] ?? '', $state),
            ));
        }

        $tags = $choice['tags'] ?? [];
        $effects = $outcome['effects'] ?? [];

        // A death silences everything else: the whole crew reacts to it.
        if ($this->hasEffect($effects, 'kill')) {
            $out = [];
            foreach ($this->livingNames($state) as $name) {
                $out[] = ['who' => $name, 'tone' => 'anger', 'line' => 'Non lo dimenticherò.'];
            }
            return $out;
        }

        $out = [];
        $cold = array_intersect((array) $tags, ['sacrifice_crew', 'il_freddo']) !== [];
        $kind = array_intersect((array) $tags, ['generous', 'honest']) !== [];

        if ($cold && $this->isAlive('Bex', $state)) {
            $out[] = ['who' => 'Bex', 'tone' => 'anger', 'line' => 'Non dovevi farlo.'];
        }
        if ($kind && $this->isAlive('Bex', $state)) {
            $out[] = ['who' => 'Bex', 'tone' => 'approve', 'line' => 'Hai fatto la cosa giusta.'];
        }
        if ($this->hasEffect($effects, 'damage_system') && $this->isAlive('Anna', $state)) {
            $out[] = ['who' => 'Anna', 'tone' => 'anger', 'line' => 'Quei sistemi mi servivano.'];
        }

        // Relationship shifts surface as a spoken beat so the player SEES the
        // dynamic move: a worsening pair reads as friction, an improving one as
        // warmth. Tone is structural (derived from delta sign), not authored copy.
        foreach ($effects as $e) {
            if (! is_array($e) || ! array_key_exists('relationship', $e)) {
                continue;
            }
            $spec = $e['relationship'];
            $a = $spec['a'] ?? null;
            $b = $spec['b'] ?? null;
            $delta = (int) ($spec['delta'] ?? 0);
            if ($a === null || $b === null || $delta === 0) {
                continue;
            }
            $speaker = $this->isAlive($a, $state) ? $a : ($this->isAlive($b, $state) ? $b : null);
            $other = $speaker === $a ? $b : $a;
            if ($speaker === null) {
                continue;
            }
            if ($delta < 0) {
                $out[] = ['who' => $speaker, 'tone' => 'complicated', 'line' => "Qualcosa tra me e {$other} si è incrinato."];
            } else {
                $out[] = ['who' => $speaker, 'tone' => 'approve', 'line' => "Io e {$other} ci siamo capiti."];
            }
        }

        return $out;
    }

    public function standingDelta(string $tone): int
    {
        return self::TONE_STANDING[$tone] ?? 0;
    }

    /**
     * A short third-person line for the Diary, from the first reaction.
     *
     * @param  list<array{who:string,tone:string,line:string}>  $reactions
     */
    public function summary(array $reactions): ?string
    {
        if ($reactions === []) {
            return null;
        }
        $first = $reactions[0];
        $who = $first['who'] ?? '';
        return match ($first['tone'] ?? '') {
            'anger' => "{$who} non era d'accordo.",
            'approve' => "{$who} ha approvato.",
            default => "{$who} ha avuto da ridire.",
        };
    }

    private function isAlive(string $name, RunState $state): bool
    {
        foreach ($state->characters as $c) {
            if (($c['name'] ?? null) === $name) {
                return (bool) ($c['alive'] ?? true);
            }
        }
        return false;
    }

    /** @return list<string> */
    private function livingNames(RunState $state): array
    {
        $out = [];
        foreach ($state->characters as $c) {
            if ($c['alive'] ?? true) {
                $out[] = $c['name'] ?? '?';
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $effects */
    private function hasEffect(array $effects, string $kind): bool
    {
        foreach ($effects as $e) {
            if (is_array($e) && array_key_exists($kind, $e)) {
                return true;
            }
        }
        return false;
    }
}
