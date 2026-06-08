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
                        'label' => 'Salgo il bus e risaldo il fusibile',
                        'hint' => 'regge, ma scotta',
                        'requires' => ['has_item' => 'welder'],
                        'requires_item' => 'welder',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'power', 'delta' => 10]],
                                'log' => 'Saldi il contatto. Il quadro torna fermo. La punta ti resta calda in mano.'],
                        ],
                    ],
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
                        'label' => 'Scansiono l\'aria per dargli torto',
                        'hint' => 'la verità, comunque sia',
                        'requires' => ['has_item' => 'scanner'],
                        'requires_item' => 'scanner',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'morale', 'delta' => 6]],
                                'log' => 'Lo scanner non trova niente. Glielo mostri. Respira, finalmente.'],
                            ['weight' => 4, 'effects' => [
                                ['resource' => 'morale', 'delta' => -4],
                                ['spawn_event' => ['key' => 'c_oxygen_leak', 'in_days' => 1]],
                            ], 'log' => 'Lo scanner lampeggia rosso. Aveva ragione. C\'è una perdita.'],
                        ],
                    ],
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
                // A rare haunting, not a constant nag: low weight + cooldown so
                // the guilt callback surfaces once in a while, not every day.
                'base_weight' => 3,
                'cooldown_days' => 6,
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
                        'label' => 'Mando il drone a frugare',
                        'hint' => 'potrebbe non tornare',
                        'requires' => ['has_item' => 'drone'],
                        'requires_item' => 'drone',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'food', 'delta' => 14]],
                                'log' => 'Il drone torna con due casse dimenticate in un condotto.'],
                            ['weight' => 4, 'effects' => [
                                ['consume_item' => 'drone'],
                                ['resource' => 'food', 'delta' => 4],
                            ], 'log' => 'Un sibilo, poi silenzio. Il drone non torna. Solo qualche scatoletta.'],
                        ],
                    ],
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

            // --- Cross-run memory (profile-scoped flags persist BETWEEN runs) -
            [
                'key' => 'reactor_gamble',
                'title' => 'Sovraccarico',
                'body' => 'Potresti spingere il reattore oltre i limiti. Una volta sola.',
                'speaker' => null,
                'base_weight' => 9,
                'cooldown_days' => 99,
                'is_filler' => false,
                'requires' => ['day' => ['op' => '>=', 'value' => 5]],
                'choices' => [
                    [
                        'label' => 'Lo spingo al massimo',
                        'hint' => 'irreversibile',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'power', 'delta' => 25],
                                // PROFILE-scoped: this is remembered in FUTURE runs.
                                ['set_flag' => 'blew_a_reactor', 'scope' => 'profile', 'value' => true],
                            ], 'log' => 'Il reattore regge. Stavolta.'],
                        ],
                    ],
                    [
                        'label' => 'Troppo rischio',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 2]],
                                'log' => 'Lasci stare. Per ora.'],
                        ],
                    ],
                ],
            ],
            [
                // Appears only in a run AFTER one where you blew a reactor.
                'key' => 'old_scorch',
                'title' => 'Bruciature familiari',
                'body' => 'Le pareti portano i segni di un reattore spinto troppo. Sai com\'è andata.',
                'speaker' => null,
                'base_weight' => 12,
                'cooldown_days' => 0,
                'is_filler' => false,
                'requires' => ['flag' => 'blew_a_reactor', 'scope' => 'profile', 'is' => true],
                'choices' => [
                    [
                        'label' => 'Stavolta più cauto',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 3]],
                                'log' => 'La memoria di ieri ti tiene le mani ferme.'],
                        ],
                    ],
                ],
            ],

            // --- Win-enabling events (set the flags that gate win endings) --
            [
                'key' => 'research_breakthrough',
                'title' => 'Dati anomali',
                'body' => 'Lo scanner ha registrato qualcosa che nessuno aveva mai visto.',
                'speaker' => null,
                'base_weight' => 10,
                'cooldown_days' => 99,
                'is_filler' => false,
                // Needs the scanner and time — a research route, not a freebie.
                'requires' => ['all' => [
                    ['has_item' => 'scanner'],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'choices' => [
                    [
                        'label' => 'Completo l\'analisi (a costo di energia)',
                        'hint' => 'ne vale la pena?',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'power', 'delta' => -20],
                                ['set_flag' => 'research_complete', 'value' => true],
                                ['grant_research_points' => 10],
                            ], 'log' => 'I dati sono completi. Ora vanno trasmessi.'],
                        ],
                    ],
                    [
                        'label' => 'Non è il momento',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -2]],
                                'log' => 'Forse un\'altra volta. Se ci sarà.'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'the_sacrifice',
                'title' => 'Una via d\'uscita per uno solo',
                'body' => 'C\'è abbastanza per far partire una capsula. Una sola.',
                'speaker' => null,
                'base_weight' => 8,
                'cooldown_days' => 99,
                'is_filler' => false,
                'requires' => ['day' => ['op' => '>=', 'value' => 8]],
                'choices' => [
                    [
                        'label' => 'Resto indietro, parta chi può',
                        'hint' => 'definitivo',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['set_flag' => 'made_the_sacrifice', 'value' => true],
                            ], 'log' => 'Chiudi il portello dall\'interno. Loro ce la faranno.'],
                        ],
                    ],
                    [
                        'label' => 'Non ancora',
                        'hint' => null,
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 3]],
                                'log' => 'Non stanotte. Forse mai.'],
                        ],
                    ],
                ],
            ],

            // --- Rationing (60 Seconds weight: one swipe, shared scarcity) --
            [
                'key' => 'ration_crisis',
                'title' => 'Chi mangia stanotte',
                'body' => 'Una sola porzione calda. Tutti la guardano.',
                'speaker' => null,
                'base_weight' => 22,
                'cooldown_days' => 2,
                'is_filler' => false,
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 30],
                'choices' => [
                    [
                        'label' => 'Esco a caccia col fucile',
                        'hint' => 'rischioso, ma carne vera',
                        'requires' => ['has_item' => 'rifle'],
                        'requires_item' => 'rifle',
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [
                                ['resource' => 'food', 'delta' => 18],
                                ['resource' => 'morale', 'delta' => 4],
                            ], 'log' => 'Torni con qualcosa. Stanotte si mangia caldo.'],
                            ['weight' => 4, 'effects' => [
                                ['character' => 'random', 'stress' => 14],
                                ['resource' => 'food', 'delta' => 3],
                            ], 'log' => 'Torni a mani quasi vuote, e con un graffio che brucia.'],
                        ],
                    ],
                    [
                        'label' => 'Dividiamo in parti uguali',
                        'hint' => 'dovrebbe reggere',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'food', 'delta' => -3],
                                ['resource' => 'morale', 'delta' => 5],
                            ], 'log' => 'Poco per ciascuno, ma nessuno resta a digiuno.'],
                        ],
                    ],
                    [
                        // Stress on ALL living survivors — weighs more with a
                        // bigger crew. The rationing primitive in action.
                        'label' => 'Si salta il turno, si stringe la cinghia',
                        'hint' => 'duro per tutti',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['character' => 'all', 'stress' => 12],
                            ], 'log' => 'Pance vuote. Nessuno parla a cena.'],
                        ],
                    ],
                    [
                        'label' => 'Mangio solo io, devo reggere',
                        'hint' => 'crudo ma lucido',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [
                                ['resource' => 'morale', 'delta' => -14],
                                ['character' => 'highest_stress', 'stress' => 15],
                                ['set_flag' => 'ate_alone', 'value' => true],
                            ], 'log' => 'Mangi voltando le spalle. Il rancore resta.'],
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
                        // The always-survivable option: calming a survivor never
                        // pushes a resource toward zero (keeps the card fair).
                        'label' => 'Gli parlo con calma',
                        'hint' => 'dovrebbe reggere',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['character' => 'highest_stress', 'stress' => -20]],
                                'log' => 'Pian piano si calma. Respira.'],
                        ],
                    ],
                    [
                        'label' => 'Lo lascio stare',
                        'hint' => 'rischioso',
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -8]],
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
                                ['resource' => 'morale', 'delta' => -12],
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
