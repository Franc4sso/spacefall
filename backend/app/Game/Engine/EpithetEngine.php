<?php

namespace App\Game\Engine;

final class EpithetEngine
{
    private const PATTERNS = [
        'il_generoso'    => ['generous'],
        'il_freddo'      => ['sacrifice_crew'],
        'l_imprudente'   => ['ignored_warning'],
        'il_prudente'    => ['cautious'],
        'il_solitario'   => ['lone_decision'],
    ];

    private const THRESHOLD = 4;

    public function calculate(RunState $state): ?string
    {
        $counts = [];
        foreach ($state->choiceLog as $entry) {
            foreach ($entry['tags'] ?? [] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        foreach (self::PATTERNS as $epithet => $tags) {
            $total = 0;
            foreach ($tags as $tag) {
                $total += $counts[$tag] ?? 0;
            }
            if ($total >= self::THRESHOLD) {
                return $epithet;
            }
        }

        return null;
    }
}
