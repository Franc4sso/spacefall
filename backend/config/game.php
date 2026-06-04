<?php

/*
|--------------------------------------------------------------------------
| Starfall Station — game tuning constants
|--------------------------------------------------------------------------
|
| Data, not logic. Engine code reads these; it never hard-codes a resource
| name or a number. Adding/retuning a resource is a config edit, never a
| code change (Prime Directive #1).
|
| Codes are English. Player-facing labels live in the frontend (Italian).
|
*/

return [

    /*
     | The five run resources. Each:
     |   max          ceiling (values clamp to [0, max])
     |   start        value at run start
     |   daily        end-of-day consumption (subtracted each day)
     |   two_sided    true if BOTH 0 and max are dangerous (Phase 6 uses this)
     |
     | Daily consumption here is the Phase 1 baseline: a flat per-day drain so
     | a do-nothing run still trends toward death. Events (Phase 2+) layer on
     | top. Tuned for real in Phase 10 via the simulation harness.
     */
    'resources' => [
        'oxygen' => ['max' => 100, 'start' => 100, 'daily' => 8,  'two_sided' => false],
        'food'   => ['max' => 100, 'start' => 80,  'daily' => 10, 'two_sided' => false],
        'power'  => ['max' => 100, 'start' => 90,  'daily' => 6,  'two_sided' => false],
        'morale' => ['max' => 100, 'start' => 60,  'daily' => 4,  'two_sided' => true],
        'hull'   => ['max' => 100, 'start' => 100, 'daily' => 2,  'two_sided' => false],
    ],

];
