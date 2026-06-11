<?php

/*
|--------------------------------------------------------------------------
| Island theme — LOST-style plane-crash survival
|--------------------------------------------------------------------------
|
| Data, not logic. Mirrors the STRUCTURE of space.php exactly (the engine
| reads the same top-level key set and shapes); only the island content
| differs. Codes/flags are English; player-facing labels are Italian.
|
| Resource map vs space: oxygen→water, power→fire, hull→shelter, food/morale
| keep their names. Systems: life_support→water_still, power_grid→signal_fire,
| hull_integrity→shelter_frame.
|
*/

return [

    /*
     | The five run resources. Same shape as space.
     |   max / start / daily / two_sided
     */
    'resources' => [
        'water'   => ['max' => 100, 'start' => 100, 'daily' => 3, 'two_sided' => false],
        'food'    => ['max' => 100, 'start' => 100, 'daily' => 1, 'two_sided' => false],
        'fire'    => ['max' => 100, 'start' => 100, 'daily' => 3, 'two_sided' => false],
        'shelter' => ['max' => 100, 'start' => 100, 'daily' => 1, 'two_sided' => false],
        'morale'  => ['max' => 100, 'start' => 65,  'daily' => 2, 'two_sided' => true],
    ],

    /*
     | Island systems. Same shape as space systems: when efficiency drops below
     | penalty_below, a per-day penalty bleeds the mapped resource.
     */
    'systems' => [
        'water_still' => [
            'start' => 100, 'daily_decay' => 1, 'penalty_below' => 40,
            'penalty' => ['resource' => 'water', 'delta' => -3],
        ],
        'signal_fire' => [
            'start' => 100, 'daily_decay' => 1, 'penalty_below' => 40,
            'penalty' => ['resource' => 'fire', 'delta' => -3],
        ],
        'shelter_frame' => [
            'start' => 100, 'daily_decay' => 1, 'penalty_below' => 35,
            'penalty' => ['resource' => 'shelter', 'delta' => -2],
        ],
    ],

    /*
     | Phases (acts). Same shape as space; island labels.
     */
    'phases' => [
        'order' => ['castaway', 'deterioration', 'reckoning'],
        'day_bands' => [
            ['phase' => 'castaway', 'from_day' => 1],
            ['phase' => 'deterioration', 'from_day' => 10],
            ['phase' => 'reckoning', 'from_day' => 21],
        ],
        'pressure' => [
            'critical_at_or_below' => 20,
            'bands' => [
                ['min_critical' => 3, 'phase' => 'deterioration'],
                ['min_critical' => 5, 'phase' => 'reckoning'],
            ],
        ],
        'labels' => [
            'castaway' => 'Naufragio',
            'deterioration' => 'Logoramento',
            'reckoning' => 'Resa dei conti',
        ],
    ],

    'phase_decay' => [
        'castaway' => 1.0,
        'deterioration' => 1.25,
        'reckoning' => 1.48,
    ],

    'relationships' => [
        'death_drift' => 3,
        'expedition_risk' => 3,
    ],

    'hardship' => [
        ['resource' => 'food', 'at_or_below' => 18, 'stress' => 5],
        ['resource' => 'water', 'at_or_below' => 20, 'stress' => 6],
        ['resource' => 'morale', 'at_or_below' => 12, 'stress' => 4],
    ],

    'hunger' => [
        'daily_rise' => 5,
        'starve_at' => 100,
        'stress_bands' => [
            ['at_or_above' => 70, 'stress' => 8],
            ['at_or_above' => 40, 'stress' => 4],
        ],
        'spawn_bands' => [
            ['at_or_above' => 30, 'spawn' => 'food_ration'],
            ['at_or_above' => 65, 'spawn' => 'hunger_warning'],
            ['at_or_above' => 88, 'spawn' => 'hunger_warning'],
        ],
    ],

    'traits' => [
        'genius'   => ['hint_bias' => 'reliable', 'luck_shift' => 1.0],
        'coward'   => ['hint_bias' => 'inflate',  'luck_shift' => 1.0],
        'paranoid' => ['hint_bias' => 'inflate',  'luck_shift' => 1.0],
        'optimist' => ['hint_bias' => 'downplay', 'luck_shift' => 1.0],
        'lucky'    => ['hint_bias' => 'reliable', 'luck_shift' => 1.6],
        'reckless' => ['hint_bias' => 'downplay', 'luck_shift' => 0.65],
    ],

    'risk_bands' => [
        ['key' => 'safe',       'phrase' => 'dovrebbe reggere'],
        ['key' => 'uncertain',  'phrase' => 'incerto'],
        ['key' => 'risky',      'phrase' => 'rischioso'],
        ['key' => 'dangerous',  'phrase' => 'molto pericoloso'],
    ],

    'stress_bands' => [
        ['min' => 0,  'spawn' => null],
        ['min' => 60, 'spawn' => 'survivor_strained'],
        ['min' => 85, 'spawn' => 'survivor_breaks'],
    ],

    /*
     | Default starting roster — plane-crash passengers. English role keys,
     | Italian names. Same shape as space.
     */
    'roster' => [
        ['name' => 'Nadia', 'role' => 'engineer', 'traits' => ['genius']],
        ['name' => 'Bruno', 'role' => 'doctor',   'traits' => ['optimist']],
        ['name' => 'Carla', 'role' => 'pilot',    'traits' => ['coward']],
    ],

    'recruit_names' => ['Elio', 'Rosa', 'Marco', 'Lia', 'Ivo', 'Tea'],

    'items_pick' => 4,

    /*
     | Endings. Death conditions reference island resource keys
     | (oxygen→water, power→fire, hull→shelter). Morale stays two-sided.
     | Win keys kept structurally similar to space; rescue_launched is a
     | placeholder for Task 14 (analogous to space's escape_launched).
     */
    'endings' => [
        // --- Lethal states (checked first) ---
        [
            'key' => 'death_thirst', 'type' => 'lose',
            'name' => 'Sete',
            'text' => 'A corto d\'acqua, la sete vi consuma. L\'isola vi inghiotte.',
            'when' => ['resource' => 'water', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_exposure', 'type' => 'lose',
            'name' => 'Intemperie',
            'text' => 'Il riparo crolla. La notte e la pioggia fanno il resto.',
            'when' => ['resource' => 'shelter', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_starvation', 'type' => 'lose',
            'name' => 'Fame',
            'text' => 'Non c\'è più nulla da cacciare né da raccogliere. Anche le forze finiscono.',
            'when' => ['resource' => 'food', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_cold', 'type' => 'lose',
            'name' => 'Buio e gelo',
            'text' => 'Il falò si spegne. Con lui l\'ultimo calore, e l\'ultima speranza.',
            'when' => ['resource' => 'fire', 'op' => '<=', 'value' => 0],
        ],
        [
            'key' => 'death_breakdown', 'type' => 'lose',
            'name' => 'Crollo',
            'text' => 'A morale zero, nessuno trova più un motivo per resistere.',
            'when' => ['resource' => 'morale', 'op' => '<=', 'value' => 0],
        ],
        [
            // Two-sided: morale at the ceiling = reckless euphoria.
            'key' => 'death_recklessness', 'type' => 'lose',
            'name' => 'Euforia fatale',
            'text' => 'Vi sentivate padroni dell\'isola. L\'isola non era d\'accordo.',
            'when' => ['resource' => 'morale', 'op' => '>=', 'value' => 100],
        ],

        // Finale: ammutinamento avvenuto
        [
            'key' => 'mutiny_end', 'type' => 'lose',
            'name' => 'AMMUTINAMENTO',
            'text' => 'Il gruppo ti ha tolto la guida. Sei rimasto a guardare dalla spiaggia deserta.',
            'when' => ['flag' => 'mutiny_occurred', 'is' => true],
        ],

        // Finale: gruppo perduto — l'ultimo è morto.
        [
            'key' => 'crew_lost', 'type' => 'lose',
            'name' => 'SOLO',
            'text' => 'Sei rimasto solo. L\'accampamento regge ancora. Tu, dentro, un po\' meno.',
            'when' => ['living_crew' => ['op' => '==', 'value' => 0]],
        ],

        // Finale: il prezzo della fame — sopravvissuto a un costo morale.
        [
            'key' => 'prezzo_della_fame', 'type' => 'win',
            'name' => 'IL PREZZO DELLA FAME',
            'text' => 'Siete vivi. Ma per restarlo avete oltrepassato un confine da cui non si torna. L\'isola vi ha salvati; non vi ha resi innocenti.',
            'when' => ['all' => [
                ['flag' => 'cannibalism', 'is' => true],
                ['flag' => 'ate_alone', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 25]],
                ['living_crew' => ['op' => '>=', 'value' => 1]],
            ]],
        ],

        // --- Wins (checked after deaths; harder requirements first) ---
        // Placeholder rescue win, gated on rescue_launched (Task 14 wires events).
        [
            'key' => 'win_rescue_launched', 'type' => 'win',
            'name' => 'Soccorso',
            'text' => 'La barca arriva. L\'isola si allontana dietro di voi.',
            'when' => ['all' => [
                ['flag' => 'rescue_launched', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 15]],
            ]],
        ],
        [
            'key' => 'win_signal', 'type' => 'win',
            'name' => 'Segnale',
            'text' => 'La radio gracchia una risposta. Qualcuno ha sentito il vostro grido.',
            'when' => ['all' => [
                ['has_item' => 'radio'],
                ['day' => ['op' => '>=', 'value' => 24]],
                ['resource' => 'morale', 'op' => '>=', 'value' => 45],
                ['flag' => 'sos_sent', 'is' => true],
            ]],
        ],
        [
            'key' => 'win_colony', 'type' => 'win',
            'name' => 'Colonia',
            'text' => 'Coltivate, costruite, resistete. L\'isola diventa una casa.',
            'when' => ['all' => [
                ['has_item' => 'seedbank'],
                ['day' => ['op' => '>=', 'value' => 25]],
                ['resource' => 'food', 'op' => '>=', 'value' => 68],
                ['flag' => 'tended_crops', 'is' => true],
            ]],
        ],
        [
            'key' => 'win_research', 'type' => 'win',
            'name' => 'Scoperta',
            'text' => 'Ciò che avete scoperto sull\'isola vale più di una vita sola. L\'avete tramandato.',
            'when' => ['all' => [
                ['flag' => 'research_complete', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 25]],
                ['resource' => 'fire', 'op' => '>=', 'value' => 30],
            ]],
        ],
        [
            'key' => 'win_sacrifice', 'type' => 'win',
            'name' => 'Sacrificio',
            'text' => 'Resti indietro perché gli altri ce la facciano. È una vittoria, a modo suo.',
            'when' => ['all' => [
                ['flag' => 'made_the_sacrifice', 'is' => true],
                ['day' => ['op' => '>=', 'value' => 16]],
                ['any' => [
                    ['resource' => 'water', 'op' => '<', 'value' => 30],
                    ['resource' => 'fire', 'op' => '<', 'value' => 30],
                    ['resource' => 'food', 'op' => '<', 'value' => 30],
                ]],
            ]],
        ],

        // Finale: vittoria fredda (epiteto il_freddo)
        [
            'key' => 'cold_victory', 'type' => 'win',
            'name' => 'FREDDA SOPRAVVIVENZA',
            'text' => 'Hai fatto le scelte difficili. Le facce di chi non ce l\'ha fatta ti seguiranno per sempre.',
            'when' => ['all' => [
                ['resource' => 'water', 'op' => '>', 'value' => 0],
                ['day' => ['op' => '>', 'value' => 25]],
                ['flag' => 'epithet', 'scope' => 'profile', 'is' => 'il_freddo'],
            ]],
        ],

        // Finale: vittoria con gruppo intero (ingegnere + medico entrambi vivi)
        [
            'key' => 'crew_intact', 'type' => 'win',
            'name' => 'NESSUNO RIMASTO INDIETRO',
            'text' => 'Ogni sopravvissuto è vivo. Ogni riparo regge. Avete resistito insieme.',
            'when' => ['all' => [
                ['resource' => 'water', 'op' => '>', 'value' => 0],
                ['day' => ['op' => '>', 'value' => 25]],
                ['has_role' => 'engineer'],
                ['has_role' => 'doctor'],
            ]],
        ],

        // Finale: sopravvissuto solitario (fallback catch-all win)
        [
            'key' => 'lone_survivor', 'type' => 'win',
            'name' => 'ULTIMO IN PIEDI',
            'text' => 'Hai tenuto in piedi l\'accampamento. Non hai salvato nessun altro.',
            'when' => ['all' => [
                ['resource' => 'water', 'op' => '>', 'value' => 0],
                ['day' => ['op' => '>', 'value' => 28]],
            ]],
        ],
    ],

    /*
     | Epilogue fragments. Same shape as space. rescue_outcome_lines is the
     | island analogue of space's escape_outcome_lines (Task 14 references it).
     */
    'epilogue' => [
        'cause_phrases' => [
            'event' => 'caduto',
            'expedition' => 'perso nell\'entroterra',
            'starvation' => 'morto di fame',
            'morale' => 'spezzato',
        ],
        'witness_flags' => [
            'cannibalism' => 'Avete mangiato uno dei vostri. Nessuno ne parla.',
            'ate_alone' => 'Hai mangiato voltando le spalle agli altri.',
            'made_the_sacrifice' => 'Sei rimasto indietro perché gli altri vivessero.',
            'sos_sent' => 'Hai gridato verso il mare. Qualcuno, forse, ha sentito.',
            'mutiny_occurred' => 'Il gruppo ti ha tolto la guida.',
            'log_falsified' => 'Hai riscritto la verità nel diario.',
            'vented_the_technician' => 'Hai abbandonato un uomo alla marea.',
            'lost_on_expedition' => 'Hai lasciato l\'accampamento a chi non era ancora rientrato.',
            'arc_garden_bloomed' => 'Hai fatto crescere un orto dove tutto seccava.',
            'arc_radio_answered' => 'La radio ha gracchiato una risposta. Qualcuno, là fuori, sapeva di voi.',
            'arc_radio_silent' => 'Hai bruciato l\'ultima carica nell\'etere. Solo statica ti ha risposto.',
            'nadia_vindicated'  => 'L\'azzardo di Nadia ha pagato: acqua dove c\'era solo sete.',
            'nadia_overreached' => 'Nadia ha voluto strafare. Il suo apparecchio è saltato, e con esso qualcosa in lei.',
            'bruno_hope_held'   => 'La speranza ostinata di Bruno ha tenuto, quando contava davvero.',
            'bruno_denial_cost' => 'Bruno ha sorriso troppo a lungo, e la verità è arrivata tardi.',
            'carla_redeemed'    => 'Carla ha vinto la sua paura quando un altro ne dipendeva.',
            'carla_broke'       => 'Carla si è bloccata ancora. Stavolta è rimasta a terra per sempre.',
        ],
        'victory_beats' => [
            'escape_repaired' => 'Hai rimesso in sesto la scialuppa, giorno {day}.',
            'escape_fueled'   => 'Hai radunato le provviste per la traversata, giorno {day}.',
        ],
        'victory_beats_event' => [
            'escape_repaired' => 'rescue_2_repair',
            'escape_fueled'   => 'rescue_3_supply',
        ],
        'rescue_outcome_lines' => [
            'rescue_captain_stayed' => 'Sei rimasto indietro perché loro vivessero.',
            'rescue_captain_chose'  => 'Hai scelto tu chi saliva sulla barca. Gli altri sono rimasti.',
        ],
    ],

    'death_notice_phrases' => [
        'starvation' => 'La fame ha avuto la meglio.',
        'event' => 'Una scelta è costata cara.',
        'expedition' => 'L\'entroterra l\'ha inghiottito.',
        'morale' => 'Si è spento dentro, prima che fuori.',
    ],

    /*
     | Meta unlocks — same shape as space (key/cost/name/description/grants_item).
     */
    'unlocks' => [
        ['key' => 'unlock_traps',   'cost' => 15, 'name' => 'Trappole da caccia',
            'description' => 'Sblocca le trappole tra gli equipaggiamenti iniziali.', 'grants_item' => 'traps'],
        ['key' => 'unlock_raft',    'cost' => 25, 'name' => 'Zattera',
            'description' => 'Sblocca la zattera.', 'grants_item' => 'raft'],
        ['key' => 'unlock_still',   'cost' => 40, 'name' => 'Alambicco',
            'description' => 'Sblocca l\'alambicco per l\'acqua.', 'grants_item' => 'still'],
    ],

    /*
     | Items. Same shape as space (key English, name/description Italian).
     | items_pick of the unlocked entries are picked at run start.
     */
    'items' => [
        ['key' => 'machete',   'name' => 'Machete',             'description' => 'Apre un varco nella giungla più fitta.'],
        ['key' => 'binoculars','name' => 'Binocolo',            'description' => 'Avvista pericoli e relitti prima che ti raggiungano.'],
        ['key' => 'tarp',      'name' => 'Telo cerato',         'description' => 'Ripara la capanna dalla pioggia battente.'],
        ['key' => 'medkit',    'name' => 'Kit di pronto soccorso', 'description' => 'Tiene in piedi chi sta cedendo.'],
        ['key' => 'speargun',  'name' => 'Fucile subacqueo',    'description' => 'Quando la fame non aspetta.'],
        ['key' => 'seedbank',  'name' => 'Sacchetto di semi',   'description' => 'Coltivi cibo invece di razionarlo.'],
        ['key' => 'wetsuit',   'name' => 'Muta da sub',         'description' => 'Esplorare la barriera smette di essere un suicidio.'],
        ['key' => 'radio',     'name' => 'Radio da campo',      'description' => 'Una possibilità di chiamare aiuto.'],
        ['key' => 'rations',   'name' => 'Razioni extra',       'description' => 'Un cuscinetto contro la fame.'],

        // Locked: reserved for the meta-unlock system.
        ['key' => 'toolkit',   'name' => 'Cassetta attrezzi',   'description' => 'Improvvisi riparazioni che altri non possono.', 'locked' => true],
        ['key' => 'manual',    'name' => 'Manuale di sopravvivenza', 'description' => 'Sai cosa stai facendo, per una volta.', 'locked' => true],
        ['key' => 'firesteel', 'name' => 'Acciarino',           'description' => 'Riaccendi il falò anche sotto la pioggia.', 'locked' => true],
        ['key' => 'snares',    'name' => 'Rete di tagliole',    'description' => 'L\'isola ti avvisa, a volte.', 'locked' => true],
        ['key' => 'flare',     'name' => 'Razzi di segnalazione','description' => 'Un grido visibile sull\'oceano.', 'locked' => true],
        ['key' => 'logbook',   'name' => 'Diario del relitto',  'description' => 'Sai cosa è successo a chi c\'era prima.', 'locked' => true],
        ['key' => 'traps',     'name' => 'Trappole da caccia',  'description' => 'Difendono e cacciano senza il tuo presidio.', 'locked' => true],
        ['key' => 'raft',      'name' => 'Zattera',             'description' => 'Metti qualcuno in mare per salvarlo.', 'locked' => true],
        ['key' => 'still',     'name' => 'Alambicco',           'description' => 'Distilli acqua che non hai.', 'locked' => true],
    ],

];
