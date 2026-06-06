<?php

namespace App\Game\Engine;

use App\Models\Run;

/**
 * Flushes profile-scoped state from a resolved RunState back onto the run's
 * Profile, so it persists across runs.
 *
 * Two things cross the run boundary:
 *   - profile flags (cross-run memory): merged into profile.flags.
 *   - research points: the EffectApplier accumulates grants in the transient
 *     profileFlags['__research_points']; we move that delta into the profile's
 *     research_points column and clear the accumulator.
 *
 * Idempotent per call: it only adds the NEW points since the last flush
 * (tracked via a baseline stored on the run state's accumulator key).
 */
final class ProfileSync
{
    private const RP_KEY = '__research_points';

    public function flush(Run $run, RunState $state): void
    {
        $profile = $run->profile;
        if ($profile === null) {
            return; // unlinked run (e.g. a low-level unit test) — nothing to persist
        }

        // Move accumulated research points into the profile, then reset the
        // accumulator so the next flush doesn't double-count.
        $earned = (int) ($state->profileFlags[self::RP_KEY] ?? 0);
        if ($earned !== 0) {
            $profile->research_points += $earned;
            unset($state->profileFlags[self::RP_KEY]);
        }

        // Persist all other profile flags (the cross-run memory).
        $flags = $state->profileFlags;
        unset($flags[self::RP_KEY]);
        $profile->flags = array_merge($profile->flags ?? [], $flags);

        $profile->save();
    }
}
