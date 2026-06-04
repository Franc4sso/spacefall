<?php

namespace Database\Seeders;

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * Phase 2 starter content (~5 themed events + filler). Italian player-facing
 * text, English keys/flags (the hard language split). Each row is validated
 * against the DSL schema before insert — malformed content fails the seeder.
 *
 * These exercise the engine surface: resource/day/flag requires, flag callbacks
 * (vented_the_technician), a spawn_event consequence chain, multi-branch
 * weighted outcomes, and choice hints. The full 50+ content pass is Phase 8.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $schema = new EventSchema(array_keys(config('game.resources')));

        foreach ($this->events() as $event) {
            $schema->validate($event);
            Event::updateOrCreate(['key' => $event['key']], $event);
        }
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        return [
            // --- Themed events ---------------------------------------------
            [
                'key' => 'power_flicker',
                'title' => 'Sbalzo di tensione',
                'body' => 'Le luci tremano. Un fusibile sta cedendo nel quadro.',
                'speaker' => null,
                'base_weight' => 20,
                'cooldown_days' => 3,
                'is_filler' => false,
                'requires' => null,
                'choices' => [
                    [
                        'label' => 'Spengo tutto il non essenziale',
                        'hint' => 'dovrebbe reggere',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'power', 'delta' => 6]],
                                'log' => 'Il quadro si stabilizza. Buio, ma stabile.'],
                        ],
                    ],
                    [
                        'label' => 'Lascio perdere, ho altro da fare',
                        'hint' => 'rischioso',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'power', 'delta' => -4]],
                                'log' => 'Per ora tiene.'],
                            ['weight' => 4, 'effects' => [
                                ['resource' => 'power', 'delta' => -8],
                                ['spawn_event' => ['key' => 'power_cascade', 'in_days' => 2]],
                            ], 'log' => 'Qualcosa frigge dietro la paratia. Non è finita.'],
                        ],
                    ],
                ],
            ],
            [
                // The scheduled consequence of ignoring power_flicker.
                'key' => 'power_cascade',
                'title' => 'Cascata di guasti',
                'body' => 'Il fusibile ignorato ha tirato giù mezzo settore.',
                'speaker' => null,
                'base_weight' => 10,
                'cooldown_days' => 0,
                'is_filler' => false,
                // Scheduled-only: a never-set flag keeps it out of normal
                // selection. The Selector force-picks scheduled events by key
                // *without* checking requires, so spawn_event still fires it.
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    [
                        'label' => 'Sacrifico ossigeno per riavviare la rete',
                        'hint' => 'caro ma necessario',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'oxygen', 'delta' => -10],
                                ['resource' => 'power', 'delta' => 15],
                            ], 'log' => 'La rete riparte. L\'aria si fa pesante.'],
                        ],
                    ],
                    [
                        'label' => 'Tengo l\'aria, perdo il settore',
                        'hint' => 'doloroso',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'power', 'delta' => -12]],
                                'log' => 'Il settore resta morto. Si gestisce così.'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'technician_panic',
                'title' => 'Il tecnico è fuori di sé',
                'body' => 'Urla che l\'aria è contaminata. Forse delira, forse no.',
                'speaker' => null,
                'base_weight' => 12,
                'cooldown_days' => 5,
                'is_filler' => false,
                'requires' => ['day' => ['op' => '>=', 'value' => 2]],
                'choices' => [
                    [
                        'label' => 'Lo chiudo nella camera stagna',
                        'hint' => 'lo zittisce',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'morale', 'delta' => -8],
                                ['set_flag' => 'vented_the_technician', 'value' => true],
                            ], 'log' => 'Silenzio. Nessuno ti guarda negli occhi.'],
                        ],
                    ],
                    [
                        'label' => 'Lo ascolto',
                        'hint' => 'incerto',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 4]],
                                'log' => 'Si calma. Forse aveva solo paura.'],
                        ],
                    ],
                ],
            ],
            [
                // Callback: only appears if you vented the technician earlier.
                'key' => 'technician_ghost',
                'title' => 'La camera stagna',
                'body' => 'Passi davanti al portello. Dentro non c\'è più niente da sentire.',
                'speaker' => null,
                'base_weight' => 30,
                'cooldown_days' => 0,
                'is_filler' => false,
                'requires' => ['flag' => 'vented_the_technician', 'is' => true],
                'choices' => [
                    [
                        'label' => 'Tiro dritto',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -6]],
                                'log' => 'Il peso resta. Cammini più veloce.'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'ration_night',
                'title' => 'Razioni',
                'body' => 'Il cibo non basta per tutti, stanotte.',
                'speaker' => null,
                'base_weight' => 16,
                'cooldown_days' => 2,
                'is_filler' => false,
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 50],
                'choices' => [
                    [
                        'label' => 'Mangio io, mi serve la lucidità',
                        'hint' => 'egoista ma sensato',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'morale', 'delta' => -10],
                                ['resource' => 'food', 'delta' => -4],
                            ], 'log' => 'Gli altri non dicono niente. È peggio.'],
                        ],
                    ],
                    [
                        'label' => 'Salto il pasto',
                        'hint' => 'nobile, sfinente',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'morale', 'delta' => 6],
                                ['resource' => 'oxygen', 'delta' => -2],
                            ], 'log' => 'La testa gira, ma stanotte si dorme in pace.'],
                        ],
                    ],
                ],
            ],

            // --- Item-gated event (items unlock CHOICES, not just stats) ----
            [
                'key' => 'hull_breach',
                'title' => 'Microbreccia',
                'body' => 'Un sibilo sottile. Da qualche parte lo scafo perde.',
                'speaker' => null,
                'base_weight' => 14,
                'cooldown_days' => 4,
                'is_filler' => false,
                'requires' => null,
                'choices' => [
                    [
                        // Only available if you packed the welder — a different
                        // route opens up depending on your pick-5.
                        'label' => 'Saldo la breccia',
                        'requires' => ['has_item' => 'welder'],
                        'hint' => 'dovrebbe reggere',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'hull', 'delta' => 12]],
                                'log' => 'Saldatura netta. Lo scafo tiene.'],
                        ],
                    ],
                    [
                        'label' => 'Tappo alla bell\'e meglio',
                        'hint' => 'rischioso',
                        'outcomes' => [
                            ['weight' => 5, 'effects' => [['resource' => 'hull', 'delta' => -6]],
                                'log' => 'Regge a malapena.'],
                            ['weight' => 5, 'effects' => [
                                ['resource' => 'hull', 'delta' => -10],
                                ['resource' => 'oxygen', 'delta' => -6],
                            ], 'log' => 'Il tappo salta nella notte.'],
                        ],
                    ],
                ],
            ],

            // --- Stress-driven self-initiated behaviour (scheduled-only) ---
            [
                'key' => 'survivor_strained',
                'title' => 'Nervi tesi',
                'body' => 'Qualcuno sbatte un boccale sul tavolo. La tensione si sente.',
                'speaker' => null,
                'base_weight' => 10,
                'cooldown_days' => 0,
                'is_filler' => false,
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    [
                        'label' => 'Faccio finta di niente',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -4]],
                                'log' => 'La cosa cova sotto la cenere.'],
                        ],
                    ],
                    [
                        'label' => 'Parlo con lui',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 3]],
                                'log' => 'Si sfoga. Per ora basta.'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'survivor_breaks',
                'title' => 'Crollo',
                'body' => 'Uno dei tuoi ha smesso di rispondere agli ordini.',
                'speaker' => null,
                'base_weight' => 10,
                'cooldown_days' => 0,
                'is_filler' => false,
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    [
                        'label' => 'Lo lascio stare',
                        'hint' => 'rischioso',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -10]],
                                'log' => 'Si chiude in cabina. Non esce per ore.'],
                        ],
                    ],
                    [
                        'label' => 'Lo costringo a lavorare',
                        'hint' => 'molto pericoloso',
                        'outcomes' => [
                            ['weight' => 3, 'effects' => [['resource' => 'power', 'delta' => 5]],
                                'log' => 'Obbedisce, a denti stretti.'],
                            ['weight' => 2, 'effects' => [
                                ['resource' => 'morale', 'delta' => -15],
                                ['damage_system' => 'power_grid', 'amount' => 15],
                            ], 'log' => 'Sbaglia tutto. Forse di proposito.'],
                        ],
                    ],
                ],
            ],

            // --- Guaranteed filler (always eligible, low stakes) -----------
            [
                'key' => 'filler_silence',
                'title' => 'Silenzio',
                'body' => 'Un ronzio lontano. La stazione respira da sola.',
                'speaker' => null,
                'base_weight' => 5,
                'cooldown_days' => 1,
                'is_filler' => true,
                'requires' => null,
                'choices' => [
                    [
                        'label' => 'Controllo i sistemi',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 1]],
                                'log' => 'Tutto a posto, per ora.'],
                        ],
                    ],
                    [
                        'label' => 'Riposo',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => -1]],
                                'log' => 'Chiudi gli occhi un istante.'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'filler_log',
                'title' => 'Vecchio registro',
                'body' => 'Trovi un appunto di qualcuno che non c\'è più.',
                'speaker' => null,
                'base_weight' => 5,
                'cooldown_days' => 1,
                'is_filler' => true,
                'requires' => null,
                'choices' => [
                    [
                        'label' => 'Lo leggo',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 2]],
                                'log' => 'Qualcuno, prima di te, ce l\'aveva quasi fatta.'],
                        ],
                    ],
                    [
                        'label' => 'Lo butto',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -1]],
                                'log' => 'Meglio non sapere.'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
