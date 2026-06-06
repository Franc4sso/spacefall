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
     | Station systems. Each has an efficiency [0, 100] that degrades a little
     | each day and can be damaged by events (damage_system effect). When a
     | system's efficiency drops below `penalty_below`, it inflicts `penalty`
     | on a resource every day — a failing life-support quietly bleeds oxygen.
     | This is what turns neglect into a slow-motion death: ignore repairs and
     | the daily drain compounds. All data; no system name in code.
     |
     |   start          efficiency at run start
     |   daily_decay    efficiency lost per day
     |   penalty_below  efficiency threshold under which the penalty applies
     |   penalty        { resource, delta } applied per day while below threshold
     */
    'systems' => [
        'life_support' => [
            'start' => 100, 'daily_decay' => 3, 'penalty_below' => 50,
            'penalty' => ['resource' => 'oxygen', 'delta' => -5],
        ],
        'power_grid' => [
            'start' => 100, 'daily_decay' => 2, 'penalty_below' => 50,
            'penalty' => ['resource' => 'power', 'delta' => -4],
        ],
        'hull_integrity' => [
            'start' => 100, 'daily_decay' => 1, 'penalty_below' => 40,
            'penalty' => ['resource' => 'hull', 'delta' => -3],
        ],
    ],

    /*
     | Hardship stress: when a resource sits at or below its `at_or_below`
     | threshold at end of day, every living survivor gains `stress`. Scarcity
     | wears people down, which feeds the stress-band behaviour above — the
     | rationing pressure (60 Seconds) made mechanical. Data only.
     */
    'hardship' => [
        ['resource' => 'food', 'at_or_below' => 20, 'stress' => 8],
        ['resource' => 'oxygen', 'at_or_below' => 25, 'stress' => 10],
        ['resource' => 'morale', 'at_or_below' => 15, 'stress' => 6],
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

    /*
     | Items. The player picks `items_pick` of these at the start of a run.
     | Items gate CHOICES (via the `has_item` condition in a choice's requires),
     | so a Drone build and a Scanner build open genuinely different routes —
     | items change *how you play*, not just numbers (design §2.1). Each entry is
     | pure data: key (English), name + description (Italian). No item logic in
     | code; what an item *does* is expressed entirely in the events that gate on
     | it. `unlock` items are reserved for the meta system (Phase 7) — left out of
     | the default pool for now.
     */
    'items_pick' => 5,

    /*
     | Endings. Each is a data-driven ending: a `when` Condition (the same DSL),
     | a `type` (win|lose), and Italian name + epilogue text. Evaluated after
     | every choice resolution and every day advance; the FIRST whose `when`
     | holds fires (so order = priority). Deaths come first so a lethal state
     | pre-empts any simultaneous win.
     |
     | Adding an ending is one new entry here — never engine code (Directive #1).
     |
     | Two-sided danger: `morale` is hazardous at BOTH ends — at 0 the crew
     | breaks down (lose), at max it tips into reckless euphoria that gets
     | everyone killed (lose). This is the design §1.5 "danger at both ends".
     */
    'endings' => [
        // --- Lethal states (checked first) ---
        [
            'key' => 'death_asphyxiation', 'type' => 'lose',
            'name' => 'Asfissia',
            'text' => 'L\'aria finisce. Il silenzio della stazione diventa il tuo.',
            'when' => ['resource' => 'oxygen', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_hull', 'type' => 'lose',
            'name' => 'Decompressione',
            'text' => 'Lo scafo cede di colpo. Non fa nemmeno in tempo a far male.',
            'when' => ['resource' => 'hull', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_starvation', 'type' => 'lose',
            'name' => 'Fame',
            'text' => 'Le scorte sono finite da giorni. Anche le forze.',
            'when' => ['resource' => 'food', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_blackout', 'type' => 'lose',
            'name' => 'Buio totale',
            'text' => 'L\'energia si spegne. Con lei tutto ciò che ti teneva vivo.',
            'when' => ['resource' => 'power', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_breakdown', 'type' => 'lose',
            'name' => 'Crollo',
            'text' => 'A morale zero, nessuno trova più un motivo per continuare.',
            'when' => ['resource' => 'morale', 'op' => '<=', 'value' => 0],
        ],
        [
            // Two-sided: morale at the ceiling = reckless euphoria.
            'key' => 'death_recklessness', 'type' => 'lose',
            'name' => 'Euforia fatale',
            'text' => 'Vi sentivate invincibili. La stazione non era d\'accordo.',
            'when' => ['resource' => 'morale', 'op' => '>=', 'value' => 100],
        ],

        // --- Wins (checked after deaths; harder requirements first) ---
        [
            'key' => 'win_escape', 'type' => 'win',
            'name' => 'Fuga',
            'text' => 'La tuta tiene, la navetta parte. La stazione si spegne dietro di te.',
            'when' => ['all' => [
                ['has_item' => 'spacesuit'],
                ['day' => ['op' => '>=', 'value' => 12]],
                ['resource' => 'power', 'op' => '>=', 'value' => 40],
            ]],
        ],
        [
            'key' => 'win_rescue', 'type' => 'win',
            'name' => 'Soccorso',
            'text' => 'La radio gracchia una risposta. Qualcuno sta arrivando.',
            'when' => ['all' => [
                ['has_item' => 'comms'],
                ['day' => ['op' => '>=', 'value' => 18]],
                ['resource' => 'morale', 'op' => '>=', 'value' => 30],
            ]],
        ],
        [
            'key' => 'win_colony', 'type' => 'win',
            'name' => 'Colonia',
            'text' => 'Coltivate, riparate, resistete. La stazione torna a vivere.',
            'when' => ['all' => [
                ['has_item' => 'seedbank'],
                ['day' => ['op' => '>=', 'value' => 25]],
                ['resource' => 'food', 'op' => '>=', 'value' => 60],
            ]],
        ],
        [
            'key' => 'win_research', 'type' => 'win',
            'name' => 'Scoperta',
            'text' => 'I dati che hai salvato valgono più di una vita sola. Li hai trasmessi.',
            'when' => ['all' => [
                ['flag' => 'research_complete', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 15]],
            ]],
        ],
        [
            'key' => 'win_sacrifice', 'type' => 'win',
            'name' => 'Sacrificio',
            'text' => 'Resti indietro perché gli altri ce la facciano. È una vittoria, a modo suo.',
            'when' => ['flag' => 'made_the_sacrifice', 'is' => true],
        ],
    ],

    'items' => [
        ['key' => 'drone',        'name' => 'Drone da ricognizione', 'description' => 'Esplora i settori sigillati al posto tuo.'],
        ['key' => 'scanner',      'name' => 'Scanner portatile',     'description' => 'Legge guasti e minacce prima che esplodano.'],
        ['key' => 'welder',       'name' => 'Saldatrice',            'description' => 'Ripara brecce nello scafo sul posto.'],
        ['key' => 'medkit',       'name' => 'Kit medico',            'description' => 'Tiene in piedi chi sta cedendo.'],
        ['key' => 'rifle',        'name' => 'Fucile a impulsi',      'description' => 'Quando parlare non basta.'],
        ['key' => 'seedbank',     'name' => 'Banca semi',            'description' => 'Coltivi cibo invece di razionarlo.'],
        ['key' => 'reactor_cell', 'name' => 'Cella di riserva',      'description' => 'Energia d\'emergenza per un giorno nero.'],
        ['key' => 'spacesuit',    'name' => 'Tuta EVA',              'description' => 'Uscire fuori smette di essere un suicidio.'],
        ['key' => 'comms',        'name' => 'Radio a lungo raggio',  'description' => 'Una possibilità di chiamare aiuto.'],
        ['key' => 'toolkit',      'name' => 'Cassetta attrezzi',     'description' => 'Improvvisi riparazioni che altri non possono.'],
        ['key' => 'rebreather',   'name' => 'Riciclatore d\'aria',   'description' => 'Allunga ogni respiro.'],
        ['key' => 'battery',      'name' => 'Pacco batterie',        'description' => 'Accumuli energia per dopo.'],
        ['key' => 'rations',      'name' => 'Razioni extra',         'description' => 'Un cuscinetto contro la fame.'],
        ['key' => 'manual',       'name' => 'Manuale tecnico',       'description' => 'Sai cosa stai toccando, per una volta.'],
        ['key' => 'turret',       'name' => 'Torretta automatica',   'description' => 'Difende un settore senza il tuo presidio.'],
        ['key' => 'cryopod',      'name' => 'Cella criogenica',      'description' => 'Metti qualcuno in pausa per salvarlo.'],
        ['key' => 'sensors',      'name' => 'Rete di sensori',       'description' => 'La stazione ti avvisa, a volte.'],
        ['key' => 'fabricator',   'name' => 'Fabbricatore',          'description' => 'Stampi pezzi che non hai.'],
        ['key' => 'flare',        'name' => 'Razzi di segnalazione', 'description' => 'Un grido visibile nel vuoto.'],
        ['key' => 'logbank',      'name' => 'Archivio di bordo',     'description' => 'Sai cosa è successo a chi c\'era prima.'],
    ],

];
