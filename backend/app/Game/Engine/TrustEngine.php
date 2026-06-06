<?php

namespace App\Game\Engine;

final class TrustEngine
{
    private const MUTINY_THRESHOLD = 20;

    public function shouldMutiny(RunState $state): bool
    {
        $trust = (int) ($state->flags['crew_trust'] ?? 60);
        if ($trust >= self::MUTINY_THRESHOLD) {
            return false;
        }
        foreach ($state->characters as $c) {
            if ($c['alive'] ?? true) {
                return true;
            }
        }
        return false;
    }

    public function mutinyEventKey(): string
    {
        return 'mutiny_trigger';
    }
}
