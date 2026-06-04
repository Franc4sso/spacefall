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

    /*
     | Traits. Each is pure data the engine consults; no trait name is ever
     | hard-coded in code. Two levers:
     |
     |   hint_bias    how this trait distorts a spoken risk estimate:
     |                  'reliable' — reports the true risk band
     |                  'inflate'  — reports one band more dangerous
     |                  'downplay' — reports one band safer
     |                This is the "probability filtered through a character"
     |                rule: the player never sees a number, only a vague phrase,
     |                and the trait colours it.
     |
     |   luck_shift   when the SPEAKER of an event carries this trait, every
     |                outcome's weight is multiplied by luck_shift ^ (danger of
     |                that outcome). danger is inferred from the outcome's net
     |                resource effect (negative = dangerous). >1 favours good
     |                outcomes, <1 favours bad ones. This makes a Genius's plans
     |                measurably work out better than a Reckless survivor's over
     |                many seeds, without authoring per-event branches.
     */
    'traits' => [
        'genius'   => ['hint_bias' => 'reliable', 'luck_shift' => 1.0],
        'coward'   => ['hint_bias' => 'inflate',  'luck_shift' => 1.0],
        'paranoid' => ['hint_bias' => 'inflate',  'luck_shift' => 1.0],
        'optimist' => ['hint_bias' => 'downplay', 'luck_shift' => 1.0],
        'lucky'    => ['hint_bias' => 'reliable', 'luck_shift' => 1.6],
        'reckless' => ['hint_bias' => 'downplay', 'luck_shift' => 0.65],
    ],

    /*
     | Risk bands → the Italian phrase shown to the player. A choice's true risk
     | is computed from its outcome spread; the speaker's hint_bias shifts which
     | band's phrase is shown. Order matters: index 0 = safest.
     */
    'risk_bands' => [
        ['key' => 'safe',       'phrase' => 'dovrebbe reggere'],
        ['key' => 'uncertain',  'phrase' => 'incerto'],
        ['key' => 'risky',      'phrase' => 'rischioso'],
        ['key' => 'dangerous',  'phrase' => 'molto pericoloso'],
    ],

    /*
     | Stress bands → self-initiated behaviour. When a survivor's stress crosses
     | a threshold at end-of-day, the engine schedules the named event (it fires
     | through the normal scheduled-event path — no special-casing). The events
     | themselves are content (Phase 5/8). 'spawn' null = no behaviour.
     */
    'stress_bands' => [
        ['min' => 0,  'spawn' => null],
        ['min' => 60, 'spawn' => 'survivor_strained'],
        ['min' => 85, 'spawn' => 'survivor_breaks'],
    ],

    /*
     | Default starting roster. Names are content; roles/traits are English keys.
     | Female-safe phrasing in events means names need no gender field (design §2).
     */
    'roster' => [
        ['name' => 'Anna',  'role' => 'engineer', 'traits' => ['genius']],
        ['name' => 'Bex',   'role' => 'doctor',   'traits' => ['optimist']],
        ['name' => 'Cole',  'role' => 'pilot',    'traits' => ['coward']],
    ],

];
