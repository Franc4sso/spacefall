<?php

namespace App\Game\Engine;

use App\Game\ThemeConfig;
use App\Models\Run;

/**
 * Decides whether a run has reached an ending and records it.
 *
 * Endings are pure data (config game.endings): a `when` Condition, a type
 * (win|lose), and Italian name/text. The FIRST ending whose condition holds
 * fires — config order is priority, with lethal states listed first so a deadly
 * state pre-empts a simultaneous win. Evaluated after every choice resolution
 * and every day advance.
 *
 * No ending is hard-coded; adding one is a config entry. The evaluation reuses
 * the same total ConditionEvaluator as everything else.
 */
final class EndingService
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly ThemeConfig $theme = new ThemeConfig(),
    ) {
    }

    /**
     * If an ending condition holds for the run's current state, mark the run
     * ended (status + ending_key + ending_type) and persist. Returns the
     * matched ending config (with key/type/name/text), or null if still alive.
     *
     * @return array<string,mixed>|null
     */
    public function check(Run $run): ?array
    {
        if ($run->status !== 'active') {
            return $this->describe($run->ending_key, $run->theme);
        }

        $state = RunState::fromRun($run);

        foreach ($this->theme->for($run->theme)->get('endings') as $ending) {
            if ($this->evaluator->evaluate($ending['when'] ?? null, $state)) {
                $run->status = 'ended';
                $run->ending_key = $ending['key'];
                $run->ending_type = $ending['type'];
                $run->current_event_key = null; // no more cards
                $run->save();

                return $ending;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function describe(?string $key, string $theme = 'space'): ?array
    {
        if ($key === null) {
            return null;
        }
        foreach ($this->theme->for($theme)->get('endings') as $ending) {
            if ($ending['key'] === $key) {
                return $ending;
            }
        }
        return null;
    }
}
