<?php

/*
|--------------------------------------------------------------------------
| Island theme — Starfall Station (stub — work in progress)
|--------------------------------------------------------------------------
|
| Minimal config to bootstrap island runs. All engine reads must resolve
| a non-null value here; flesh out for full island content later.
|
*/

return [

    /*
     | Resources. Island survival track: morale, water, food, shelter, signal.
     | Deliberately placeholder tuning — balance pass deferred.
     */
    'resources' => [
        'morale'   => ['max' => 100, 'start' => 70,  'daily' => 2, 'two_sided' => true],
        'water'    => ['max' => 100, 'start' => 80,  'daily' => 3, 'two_sided' => false],
        'food'     => ['max' => 100, 'start' => 80,  'daily' => 2, 'two_sided' => false],
        'shelter'  => ['max' => 100, 'start' => 60,  'daily' => 1, 'two_sided' => false],
        'signal'   => ['max' => 100, 'start' => 10,  'daily' => 0, 'two_sided' => false],
    ],

    'systems' => [],

    'phases' => [
        'order' => ['survival', 'struggle', 'reckoning'],
        'day_bands' => [
            ['phase' => 'survival', 'from_day' => 1],
            ['phase' => 'struggle', 'from_day' => 10],
            ['phase' => 'reckoning', 'from_day' => 21],
        ],
        'pressure' => [
            'critical_at_or_below' => 20,
            'bands' => [
                ['min_critical' => 3, 'phase' => 'struggle'],
                ['min_critical' => 5, 'phase' => 'reckoning'],
            ],
        ],
        'labels' => [
            'survival' => 'Sopravvivenza',
            'struggle' => 'Lotta',
            'reckoning' => 'Resa dei conti',
        ],
    ],

    'phase_decay' => [
        'survival'   => 1.0,
        'struggle'   => 1.25,
        'reckoning'  => 1.48,
    ],

    'relationships' => [
        'death_drift'      => 3,
        'expedition_risk'  => 3,
    ],

    'hardship' => [
        ['resource' => 'food',   'at_or_below' => 18, 'stress' => 5],
        ['resource' => 'water',  'at_or_below' => 20, 'stress' => 6],
        ['resource' => 'morale', 'at_or_below' => 12, 'stress' => 4],
    ],

    'hunger' => [
        'daily_rise'   => 5,
        'starve_at'    => 100,
        'stress_bands' => [
            ['at_or_above' => 70, 'stress' => 8],
            ['at_or_above' => 40, 'stress' => 4],
        ],
        'spawn_bands'  => [],
    ],

    'stress_bands' => [
        ['min' => 0,  'spawn' => null],
        ['min' => 60, 'spawn' => null],
        ['min' => 85, 'spawn' => null],
    ],

    'traits' => [],

    'roster' => [
        ['name' => 'Mara',    'role' => 'medic',     'traits' => []],
        ['name' => 'Tomas',   'role' => 'engineer',  'traits' => []],
        ['name' => 'Silvia',  'role' => 'navigator', 'traits' => []],
    ],

    'items_pick' => 2,

    'items' => [],

    'unlocks' => [],

    'endings' => [],

    'win_conditions' => [],

];
