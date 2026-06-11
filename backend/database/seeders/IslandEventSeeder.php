<?php

namespace Database\Seeders;

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * Island theme content — plane-crash survival (LOST-style). Mirrors the SHAPE
 * of ContentEventSeeder (space) exactly; only keys/text are island-flavoured.
 *
 * Italian player-facing text, English keys/flags. Resources: water/food/fire/
 * shelter/morale. Systems: water_still/signal_fire/shelter_frame. Roster:
 * Nadia (engineer), Bruno (doctor), Carla (pilot).
 *
 * The RESCUE CHAIN (rescue_1_discovery → rescue_2_repair → rescue_3_supply →
 * rescue_4_launch) is the island analogue of space's escape chain. It wires
 * the exact flag/event keys the island config references so the rescue win and
 * the epilogue beats fire:
 *   - rescue_2_repair  sets flag escape_repaired   (config victory_beats[_event])
 *   - rescue_3_supply  sets flag escape_fueled     (config victory_beats[_event])
 *   - rescue_4_launch  sets flag rescue_launched   (gates win_rescue_launched)
 *                      and one of rescue_captain_stayed / rescue_captain_chose
 *                      (epilogue rescue_outcome_lines).
 */
class IslandEventSeeder extends Seeder
{
    public function run(): void
    {
        $schema = new EventSchema(array_keys(config('themes.island.resources')));

        foreach ($this->events() as $event) {
            $schema->validate($event);
            Event::updateOrCreate(['key' => $event['key'], 'theme' => $event['theme']], $event);
        }
    }

    /** Defaults keep every row terse and tagged with the island theme. */
    private function ev(array $e): array
    {
        return array_merge([
            'theme' => 'island',
            'speaker' => null,
            'base_weight' => 12,
            'cooldown_days' => 4,
            'is_filler' => false,
            'requires' => null,
            'weight_modifiers' => null,
        ], $e);
    }

    private function one(string $label, array $effects, string $log, ?string $hint = null, ?array $requires = null): array
    {
        $choice = ['label' => $label, 'hint' => $hint, 'outcomes' => [['weight' => 1, 'effects' => $effects, 'log' => $log]]];
        if ($requires !== null) {
            $choice['requires'] = $requires;
        }

        return $choice;
    }

    private function gamble(string $label, array $good, string $goodLog, array $bad, string $badLog, int $goodW, int $badW, ?string $hint = null): array
    {
        return ['label' => $label, 'hint' => $hint, 'outcomes' => [
            ['weight' => $goodW, 'effects' => $good, 'log' => $goodLog],
            ['weight' => $badW, 'effects' => $bad, 'log' => $badLog],
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        return array_merge(
            $this->resourceEvents(),
            $this->systemEvents(),
            $this->characterEvents(),
            $this->itemEvents(),
            $this->phaseEvents(),
            $this->hungerEvents(),
            $this->dilemmaEvents(),
            $this->survivorArc(),
            $this->moralEvents(),
            $this->dominoEvents(),
            $this->silentEvents(),
            $this->fillerEvents(),
            $this->rescueChain(),
        );
    }

    // ---- The rescue chain (mirrors space's escape arc stage-for-stage) ------
    private function rescueChain(): array
    {
        return [
            // Stage 1 — discovery: a wrecked lifeboat on the far shore.
            $this->ev([
                'key' => 'rescue_1_discovery',
                'title' => 'La scialuppa',
                'body' => "Oltre la scogliera, incastrata tra gli scogli, c'è la scialuppa di salvataggio dell'aereo. Sfondata, ma forse recuperabile. Rimetterla in mare costerebbe giorni e provviste che forse non avete — ma è una via di fuga vera.",
                'requires' => ['all' => [
                    ['day' => ['op' => '>=', 'value' => 6]],
                    ['not' => ['flag' => 'rescue_found', 'is' => true]],
                ]],
                'base_weight' => 9,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ci proviamo. È la nostra via fuori.', [['resource' => 'fire', 'delta' => -4], ['set_flag' => 'rescue_found', 'value' => true], ['spawn_event' => ['key' => 'rescue_2_repair', 'in_days' => 3]]], 'Trascinate lo scafo a riva. È messo male, ma c\'è.'),
                    $this->one('Non possiamo permettercelo ora', [['resource' => 'morale', 'delta' => -2]], 'La lasciate agli scogli. Forse un altro giorno.'),
                ],
            ]),
            // Stage 2 — repair: sets escape_repaired (config victory beat).
            $this->ev([
                'key' => 'rescue_2_repair',
                'title' => 'Rimettere in sesto la scialuppa',
                'body' => "Lo scafo ha bisogno di legname e di telo che servono anche all'accampamento. Ogni asse che ci metti è un'asse che togli al riparo di oggi.",
                'requires' => ['flag' => 'rescue_found', 'is' => true],
                'base_weight' => 10,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ci lavoro sul serio', [['resource' => 'shelter', 'delta' => -6], ['resource' => 'fire', 'delta' => -4], ['set_flag' => 'escape_repaired', 'value' => true], ['spawn_event' => ['key' => 'rescue_3_supply', 'in_days' => 3]]], 'Mani nel legno e nella resina. Lo scafo torna a galleggiare.'),
                    $this->one('Solo il minimo, per ora', [['resource' => 'shelter', 'delta' => -3]], 'Un rattoppo. La scialuppa resta a metà.'),
                ],
            ]),
            // Stage 3 — supply: sets escape_fueled (config victory beat).
            $this->ev([
                'key' => 'rescue_3_supply',
                'title' => 'Provviste per la traversata',
                'body' => "La scialuppa è pronta ma vuota. Serve acqua e cibo per giorni di mare aperto, e l'unico modo è prosciugare le riserve dell'accampamento. È un punto di non ritorno: dopo, restare qui sarà più dura.",
                'requires' => ['flag' => 'escape_repaired', 'is' => true],
                'base_weight' => 10,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Carico tutto. Partiamo.', [['resource' => 'water', 'delta' => -10], ['resource' => 'food', 'delta' => -8], ['set_flag' => 'escape_fueled', 'value' => true], ['spawn_event' => ['key' => 'rescue_4_launch', 'in_days' => 2]]], 'Le casse salgono a bordo. L\'accampamento, dietro, si svuota.'),
                    $this->one('Non ancora. Troppo rischioso.', [['resource' => 'morale', 'delta' => -3]], 'La scialuppa resta sulla sabbia. Per ora.'),
                ],
            ]),
            // Stage 4 — launch: sets rescue_launched + captain dilemma.
            $this->ev([
                'key' => 'rescue_4_launch',
                'title' => 'Due posti',
                'body' => "La scialuppa regge due persone in mare aperto. Siete di più. Qualcuno deve restare sull'isola — e affrontarla da solo. La scelta è tua.",
                'requires' => ['flag' => 'escape_fueled', 'is' => true],
                'base_weight' => 12,
                'cooldown_days' => 999,
                'choices' => [
                    $this->one('Salgono i più giovani. Io resto.', [['set_flag' => 'rescue_launched', 'value' => true], ['set_flag' => 'rescue_captain_stayed', 'value' => true], ['resource' => 'morale', 'delta' => -8]], 'Spingi la scialuppa in acqua dalla riva. Parte senza di te.'),
                    $this->one('Decido io chi merita di salvarsi.', [['set_flag' => 'rescue_launched', 'value' => true], ['set_flag' => 'rescue_captain_chose', 'value' => true], ['resource' => 'morale', 'delta' => -4]], 'Due salgono. Gli altri ti guardano dalla spiaggia che si allontana.'),
                ],
            ]),
        ];
    }

    // ---- Survivor arc: Bruno's thread (mirrors space's Cole arc) ------------
    // Sets cole_found_exit / cole_left / cole_heroics — the exact epilogue
    // witness_flags the island config references.
    private function survivorArc(): array
    {
        $done = ['set_flag' => 'survivor_thread_done', 'value' => true];

        return [
            $this->ev([
                'key' => 'survivor_finds_path', 'title' => 'Bruno ha trovato qualcosa',
                'speaker' => 'Bruno',
                'body' => "Bruno ti mostra un sentiero appena visibile nell'entroterra. «C'è un valico. Una via verso l'altro versante. Non sarà comoda, ma è qualcosa di diverso da questa spiaggia. Vale la pena guardarci?»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'survivor_thread_done', 'is' => true]],
                    ['day' => ['op' => '>=', 'value' => 7]],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Seguiamo il sentiero', [['set_flag' => 'cole_found_exit', 'value' => true], ['modify_standing' => ['who' => 'Bruno', 'delta' => 15]], ['resource' => 'water', 'delta' => -5]], 'Bruno si illumina. Per la prima volta sembra avere uno scopo.'),
                    $this->one('Restiamo all\'accampamento', [['set_flag' => 'survivor_resentful', 'value' => true], ['modify_standing' => ['who' => 'Bruno', 'delta' => -12]]], 'Bruno ripiega la mappa, lentamente. «Certo. Come vuoi tu.»'),
                ],
            ]),
            $this->ev([
                'key' => 'survivor_leaves', 'title' => 'Il giaciglio di Bruno è vuoto',
                'speaker' => 'Bruno',
                'body' => "Bruno non è all'accampamento. Lo trovi al limitare della giungla, uno zaino già pronto. «Non aspetterò di morire qui mentre tu giochi a fare l'eroe. Mi prendo la mia possibilità.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'survivor_thread_done', 'is' => true]],
                    ['flag' => 'survivor_resentful', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Fermalo. Abbiamo bisogno di tutti.', [['modify_standing' => ['who' => 'Bruno', 'delta' => 20]], ['resource' => 'morale', 'delta' => -5], $done], 'Lo convinci a restare. A fatica. Resta una crepa.', [['character' => 'all', 'stress' => 12], ['modify_trust' => -15], $done], 'Degenera in rissa. Tutti hanno visto. Niente sarà come prima.', 5, 5, 'rischioso'),
                    $this->one('Lascialo andare.', [['kill' => 'Bruno'], ['resource' => 'morale', 'delta' => -10], ['set_flag' => 'cole_left', 'value' => true], $done], 'Sparisce tra gli alberi. Non saprai mai se ce l\'ha fatta.'),
                ],
            ]),
            $this->ev([
                'key' => 'survivor_heroics', 'title' => 'Bruno prende il timone',
                'speaker' => 'Bruno',
                'body' => "La tempesta scaglia onde sull'accampamento. Bruno è già nell'acqua a reggere le funi del riparo. «So che ho paura di tutto. Ma questo — questo lo so fare. Reggetevi a qualcosa.» Le sue mani, per una volta, non tremano.",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'survivor_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Bruno', 'op' => '>=', 'value' => 40]],
                    ['day' => ['op' => '>=', 'value' => 12]],
                    ['resource' => 'shelter', 'op' => '<', 'value' => 45],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Affidati a lui', [['resource' => 'shelter', 'delta' => 25], ['resource' => 'morale', 'delta' => 12], ['set_flag' => 'cole_heroics', 'value' => true], ['modify_standing' => ['who' => 'Bruno', 'delta' => 15]], $done], 'La manovra è folle e perfetta. Bruno ride, incredulo di se stesso.', [['resource' => 'shelter', 'delta' => -5], ['character' => 'Bruno', 'stress' => 20], $done], 'Quasi. Reggete tutti, a malapena. Bruno è scosso ma vivo.', 7, 3, 'incerto'),
                ],
            ]),
        ];
    }

    // ---- Phase-flavoured events (castaway / deterioration / reckoning) ------
    private function phaseEvents(): array
    {
        return [
            $this->ev([
                'key' => 'iso_wreckage', 'title' => 'Tra i rottami', 'speaker' => 'Nadia',
                'body' => "Nei resti della fusoliera, una valigia intatta: vestiti di qualcuno che non ce l'ha fatta. Nadia ti guarda: 'La apriamo o la lasciamo stare?'",
                'requires' => ['phase' => 'castaway'],
                'base_weight' => 8, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('La apriamo, serve tutto', [['resource' => 'shelter', 'delta' => 4]], 'Stoffa per il riparo. Chiunque fosse, ora aiuta voi.'),
                    $this->one('La lascio stare', [['resource' => 'morale', 'delta' => 1]], 'Alcune cose è meglio non toccarle.'),
                ],
            ]),
            $this->ev([
                'key' => 'iso_first_night', 'title' => 'La prima notte', 'speaker' => null,
                'body' => "Il buio sull'isola è totale. Versi che non riconosci, il mare che non tace mai. Nessuno dorme davvero.",
                'requires' => ['phase' => 'castaway'],
                'base_weight' => 7, 'cooldown_days' => 8,
                'choices' => [
                    $this->one('Monto di guardia io', [['character' => 'all', 'stress' => -4], ['resource' => 'morale', 'delta' => -2]], 'Loro dormono un po\'. Tu no.'),
                    $this->one('Ci stringiamo attorno al fuoco', [['resource' => 'fire', 'delta' => -3], ['resource' => 'morale', 'delta' => 3]], 'Il falò tiene lontano il buio, per stanotte.'),
                ],
            ]),
            $this->ev([
                'key' => 'det_storm_season', 'title' => 'Arriva la stagione delle piogge', 'speaker' => 'Carla',
                'body' => "Carla indica l'orizzonte: nuvole nere che si ammassano. 'Da qui in poi sarà sempre peggio. Il riparo regge o lo rinforziamo adesso?'",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 12, 'cooldown_days' => 4,
                'choices' => [
                    $this->one('Rinforziamo adesso', [['resource' => 'shelter', 'delta' => 10], ['resource' => 'fire', 'delta' => -4], ['character' => 'all', 'stress' => 4]], 'Lavoro duro sotto il primo acquazzone. Ma reggerà.'),
                    $this->one('Reggerà così', [['damage_system' => 'shelter_frame', 'amount' => 15]], 'Speri. La pioggia trova ogni crepa.'),
                ],
            ]),
            $this->ev([
                'key' => 'det_thirst_strain', 'title' => 'L\'acqua scarseggia', 'speaker' => null,
                'body' => "La pozza d'acqua dolce si è abbassata. Stavolta qualcuno lo dice ad alta voce, e l'aria tra voi cambia.",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 11, 'cooldown_days' => 5,
                'choices' => [
                    $this->gamble('Impongo il razionamento', [['character' => 'all', 'stress' => 6]], 'Mugugnano, ma obbediscono.', [['character' => 'all', 'stress' => 12], ['resource' => 'morale', 'delta' => -6]], 'Qualcuno se ne va sbattendo. La frattura si allarga.', 6, 4, 'rischioso'),
                    $this->one('Divido la mia parte', [['resource' => 'morale', 'delta' => 2], ['resource' => 'water', 'delta' => -4]], 'Un gesto che pesa. Loro lo notano.'),
                ],
            ]),
            $this->ev([
                'key' => 'rec_no_return', 'title' => 'Quello che non torna', 'speaker' => 'Nadia',
                'body' => "Nadia posa gli attrezzi. 'L'alambicco non si ripara, non con quello che abbiamo. Possiamo solo decidere come spenderlo, prima che se ne vada del tutto.'",
                'requires' => ['phase' => 'reckoning'],
                'base_weight' => 13, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Spremiamo tutto adesso', [['resource' => 'water', 'delta' => 20], ['damage_system' => 'water_still', 'amount' => 60]], 'Un\'ultima riserva d\'acqua. Poi la sete.'),
                    $this->one('Lo centelliniamo', [['resource' => 'water', 'delta' => -4]], 'Razioni d\'acqua. Si tira avanti, per ora.'),
                ],
            ]),
        ];
    }

    // ---- Resource-triggered crises ------------------------------------------
    private function resourceEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_water_low', 'title' => 'La borraccia è vuota', 'speaker' => 'Nadia',
                'body' => 'L\'acqua dolce sta finendo. Le labbra si seccano.',
                'requires' => ['resource' => 'water', 'op' => '<', 'value' => 60],
                'choices' => [
                    $this->one('Cerco una nuova sorgente', [['resource' => 'water', 'delta' => 8]], 'Una pozza tra le rocce. Per stavolta.'),
                    $this->gamble('Bevo l\'acqua piovana stagnante', [['resource' => 'water', 'delta' => 10]], 'Regge lo stomaco.', [['resource' => 'water', 'delta' => -4], ['character' => 'random', 'stress' => 10]], 'Qualcuno sta male per ore.', 6, 4, 'azzardo'),
                ],
            ]),
            $this->ev([
                'key' => 'c_fire_dying', 'title' => 'Il falò si abbassa', 'speaker' => 'Carla',
                'body' => 'La legna è umida, le fiamme arrancano. Si batte i denti.',
                'requires' => ['resource' => 'fire', 'op' => '<', 'value' => 50],
                'choices' => [
                    $this->one('Raccolgo legna asciutta', [['resource' => 'fire', 'delta' => 8], ['character' => 'all', 'stress' => 3]], 'Le fiamme tornano alte.'),
                    $this->one('Ci si stringe e si aspetta l\'alba', [['character' => 'all', 'stress' => 6]], 'Lunga notte.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_spoiled_catch', 'title' => 'Pesce andato a male', 'speaker' => 'Bruno',
                'body' => 'Metà del pescato di ieri puzza già sotto il sole.',
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 60],
                    ['resource' => 'food', 'op' => '>', 'value' => 25],
                ]],
                'choices' => [
                    $this->one('Butto il marcio', [['resource' => 'food', 'delta' => -6], ['resource' => 'morale', 'delta' => 2]], 'Meno cibo, ma sano.'),
                    $this->gamble('Si mangia lo stesso', [['resource' => 'food', 'delta' => 2]], 'Regge lo stomaco.', [['resource' => 'food', 'delta' => -4], ['character' => 'random', 'stress' => 10]], 'Qualcuno passa la notte piegato in due.', 5, 5, 'azzardo'),
                ],
            ]),
            $this->ev([
                'key' => 'c_morale_high', 'title' => 'Troppa euforia', 'speaker' => 'Carla',
                'body' => 'Ridono forte. Forse troppo, per gente bloccata su un\'isola.',
                'requires' => ['resource' => 'morale', 'op' => '>=', 'value' => 80],
                'choices' => [
                    $this->one('Riporto tutti coi piedi a terra', [['resource' => 'morale', 'delta' => -12]], 'Il silenzio cala. Meglio prudenti.'),
                    $this->one('Lascio correre', [['resource' => 'morale', 'delta' => 4], ['resource' => 'shelter', 'delta' => -4]], 'Qualcuno si fa male per scommessa.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_shelter_creak', 'title' => 'La capanna scricchiola', 'speaker' => 'Nadia',
                'body' => 'Una trave geme sotto il vento.',
                'requires' => ['resource' => 'shelter', 'op' => '<', 'value' => 70],
                'choices' => [
                    $this->one('Rinforzo la struttura', [['resource' => 'shelter', 'delta' => 8], ['resource' => 'fire', 'delta' => -3]], 'Tiene meglio.'),
                    $this->one('Annoto e vado avanti', [['spawn_event' => ['key' => 'c_shelter_give', 'in_days' => 3]]], 'Ci pensi domani. Forse.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_shelter_give', 'title' => 'La capanna cede', 'speaker' => 'Nadia',
                'body' => 'Lo scricchiolio ignorato è diventato uno squarcio nel tetto.',
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Rattoppo d\'emergenza', [['resource' => 'shelter', 'delta' => -8], ['resource' => 'fire', 'delta' => -5]], 'Fermato, a caro prezzo.'),
                    $this->one('Abbandono quell\'angolo', [['resource' => 'shelter', 'delta' => -4], ['resource' => 'morale', 'delta' => -6]], 'Un pezzo di riparo perso.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_high_tide', 'title' => 'Alta marea', 'speaker' => 'Carla',
                'body' => 'Il mare sale più del solito. Le provviste rischiano di bagnarsi.',
                'requires' => ['resource' => 'shelter', 'op' => '>=', 'value' => 40],
                'choices' => [
                    $this->one('Sposto tutto più in alto', [['resource' => 'food', 'delta' => 4], ['character' => 'all', 'stress' => 3]], 'Salvato il salvabile.'),
                    $this->gamble('Resisterà', [['resource' => 'morale', 'delta' => 3]], 'La marea si ferma in tempo.', [['resource' => 'food', 'delta' => -8]], 'L\'acqua si porta via metà delle scorte.', 5, 5, 'incerto'),
                ],
            ]),
        ];
    }

    // ---- System-triggered ----------------------------------------------------
    private function systemEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_still_failing', 'title' => 'L\'alambicco perde colpi', 'speaker' => 'Nadia',
                'body' => 'Il distillatore d\'acqua gocciola appena.',
                'requires' => ['system' => 'water_still', 'field' => 'efficiency', 'op' => '<', 'value' => 60],
                'choices' => [
                    $this->one('Pulisco i condotti', [['resource' => 'fire', 'delta' => -5]], 'Torna a distillare.'),
                    $this->one('Aggiusto con la cassetta attrezzi', [['resource' => 'water', 'delta' => 10]], 'Soluzione elegante.', requires: ['has_item' => 'toolkit']),
                    $this->one('Lascio andare', [['spawn_event' => ['key' => 'c_still_collapse', 'in_days' => 2]]], 'Reggerà ancora un po\'. Speri.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_still_collapse', 'title' => 'A secco', 'speaker' => 'Bruno',
                'body' => 'L\'alambicco ignorato si è fermato del tutto.',
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Lo rimonto da capo', [['resource' => 'fire', 'delta' => -10], ['resource' => 'water', 'delta' => 8]], 'Riparte gocciolando.'),
                    $this->one('Beviamo dalla pioggia', [['character' => 'all', 'stress' => 12], ['resource' => 'water', 'delta' => -4]], 'Sete e nervi tesi.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_signal_smoke', 'title' => 'Il fumo non sale', 'speaker' => 'Nadia',
                'body' => 'Il segnale di fumo si è ridotto a un filo. Nessuno lo vedrebbe dal mare.',
                'requires' => ['system' => 'signal_fire', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                'choices' => [
                    $this->one('Ravvivo il falò di segnalazione', [['resource' => 'fire', 'delta' => -6]], 'Una colonna nera torna a salire.'),
                    $this->gamble('Aggiungo foglie verdi per il fumo', [['resource' => 'fire', 'delta' => 4]], 'Fumo denso, visibile da lontano.', [['character' => 'random', 'stress' => 14], ['resource' => 'fire', 'delta' => -4]], 'Una vampata improvvisa. Brutta.', 5, 5, 'pericoloso'),
                ],
            ]),
            $this->ev([
                'key' => 'c_false_sail', 'title' => 'Una vela all\'orizzonte', 'speaker' => 'Carla',
                'body' => 'Qualcuno giura di aver visto una nave. Forse è solo una nuvola.',
                'requires' => ['has_item' => 'binoculars'],
                'choices' => [
                    $this->one('Controllo col binocolo', [['resource' => 'morale', 'delta' => -2]], 'Niente. Una nuvola. Tempo perso, nervi salvi.'),
                    $this->one('Accendo tutto per segnalare', [['resource' => 'fire', 'delta' => -8], ['resource' => 'morale', 'delta' => 4]], 'Bruci legna per niente. Ma almeno avete sperato.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_reorganize', 'title' => 'Riorganizziamo l\'accampamento', 'speaker' => 'Nadia',
                'body' => 'Spostare tutto potrebbe sistemare le cose. O peggiorarle.',
                'requires' => ['day' => ['op' => '>=', 'value' => 6]],
                'choices' => [
                    $this->gamble('Rifacciamo tutto', [['resource' => 'shelter', 'delta' => 10], ['resource' => 'water', 'delta' => 5]], 'Tutto torna a posto, meglio di prima.', [['resource' => 'shelter', 'delta' => -12]], 'Qualcosa va storto nel rimontaggio.', 6, 4, 'azzardo calcolato'),
                    $this->one('Meglio non rischiare', [['resource' => 'morale', 'delta' => -2]], 'Si tira avanti così.'),
                ],
            ]),
        ];
    }

    // ---- Character / role-triggered -----------------------------------------
    private function characterEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_nadia_idea', 'title' => 'Nadia ha un\'idea', 'speaker' => 'Nadia',
                'body' => 'Dice di poter ricavare acqua dalla rugiada con un telo teso.',
                'requires' => ['has_role' => 'engineer'],
                'choices' => [
                    $this->gamble('La lascio provare', [['resource' => 'water', 'delta' => 14]], 'Funziona. Ovvio.', [['resource' => 'water', 'delta' => -4], ['character' => 'random', 'stress' => 8]], 'Il telo crolla, sprecando tutto.', 7, 3, 'dovrebbe reggere'),
                    $this->one('Troppo rischioso', [['resource' => 'morale', 'delta' => -3]], 'Ci resta male.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_doctor_round', 'title' => 'Giro di controllo', 'speaker' => 'Bruno',
                'body' => 'Bruno vuole controllare tutti. Costa tempo ed energie.',
                'requires' => ['has_role' => 'doctor'],
                'choices' => [
                    $this->one('Sì, controllo generale', [['resource' => 'fire', 'delta' => -3], ['character' => 'all', 'stress' => -8]], 'Tutti un po\' più saldi.'),
                    $this->one('Non c\'è tempo', [['character' => 'random', 'stress' => 6]], 'Un malanno cova.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_castaway_found', 'title' => 'Una voce tra gli alberi', 'speaker' => null,
                'body' => 'Un altro superstite del volo, ferito ma vivo, all\'altro capo della spiaggia.',
                'requires' => ['day' => ['op' => '>=', 'value' => 6]],
                'choices' => [
                    $this->one('Vado a prenderlo', [['recruit' => ['role' => 'survivor']], ['resource' => 'water', 'delta' => -5], ['resource' => 'food', 'delta' => -5]], 'Una bocca in più, due mani in più.'),
                    $this->one('Non posso rischiare', [['resource' => 'morale', 'delta' => -4], ['set_flag' => 'left_someone', 'value' => true]], 'La voce tace. Non te lo perdoni.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_left_someone_ghost', 'title' => 'La spiaggia muta', 'speaker' => 'Bruno',
                'body' => 'Quel tratto di spiaggia resta vuoto. Lo controlli ancora, ogni tanto.',
                'requires' => ['flag' => 'left_someone', 'is' => true],
                'cooldown_days' => 6,
                'choices' => [
                    $this->one('Smetto di guardare', [['resource' => 'morale', 'delta' => -5]], 'Più silenzio. Più peso.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_camp_fight', 'title' => 'Scoppia una lite', 'speaker' => 'Carla',
                'body' => 'Due dei tuoi sono uno contro l\'altro.',
                'requires' => ['day' => ['op' => '>=', 'value' => 4]],
                'choices' => [
                    $this->one('Li separo', [['character' => 'all', 'stress' => -4], ['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => 5]]], 'Tregua fredda.'),
                    $this->one('Che se la sbrighino', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -15]], ['character' => 'random', 'stress' => 10]], 'Resta del rancore.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_fever', 'title' => 'Uno brucia di febbre', 'speaker' => 'Bruno',
                'body' => 'Febbre alta, forse un\'infezione. Senza cure peggiora.',
                'requires' => ['day' => ['op' => '>=', 'value' => 5]],
                'choices' => [
                    $this->one('Lo curo col kit', [['character' => 'highest_stress', 'stress' => -15]], 'Si riprende.', requires: ['has_item' => 'medkit']),
                    $this->gamble('Riposo e speranza', [['character' => 'highest_stress', 'stress' => -5]], 'Passa da sé.', [['kill' => 'highest_stress']], 'Non passa la notte.', 6, 4, 'non promette bene'),
                ],
            ]),
        ];
    }

    // ---- Item-triggered (items open routes) ---------------------------------
    private function itemEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_machete_clear', 'title' => 'La giungla si chiude', 'speaker' => 'Nadia',
                'body' => 'Un tratto fitto nasconde forse frutta, forse acqua. Col machete ci si entra.',
                'requires' => ['has_item' => 'machete'],
                'choices' => [
                    $this->one('Apro un varco', [['resource' => 'food', 'delta' => 12]], 'Frutta matura oltre i rovi.', 'dovrebbe reggere'),
                    $this->one('Troppo fitto anche col machete', [['resource' => 'morale', 'delta' => -2]], 'Resta impenetrabile.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_radio_signal', 'title' => 'Statica', 'speaker' => 'Carla',
                'body' => 'La radio da campo coglie qualcosa nel rumore.',
                'requires' => ['has_item' => 'radio'],
                'choices' => [
                    $this->one('Trasmetto un SOS', [['resource' => 'fire', 'delta' => -6], ['resource' => 'morale', 'delta' => 6], ['set_flag' => 'sos_sent', 'value' => true]], 'Forse qualcuno ascolta.'),
                    $this->one('Risparmio le batterie', [['resource' => 'morale', 'delta' => -3]], 'Il silenzio pesa.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_seedbank_plant', 'title' => 'Germogli', 'speaker' => 'Bruno',
                'body' => 'Il sacchetto di semi può diventare un orto. Con pazienza.',
                'requires' => ['has_item' => 'seedbank'],
                'choices' => [
                    $this->one('Pianto subito', [['resource' => 'food', 'delta' => 16], ['resource' => 'water', 'delta' => -5], ['set_flag' => 'tended_crops', 'value' => true]], 'Verde fragile sotto il sole.', 'dovrebbe reggere'),
                    $this->one('Non è il momento', [], 'I semi aspettano.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_speargun_fish', 'title' => 'Ombre nella laguna', 'speaker' => 'Carla',
                'body' => 'Pesci grossi tra i coralli. Col fucile subacqueo si mangia stasera.',
                'requires' => ['has_item' => 'speargun'],
                'choices' => [
                    $this->gamble('Vado a pescare', [['resource' => 'food', 'delta' => 18]], 'Bottino pieno. Si mangia bene.', [['character' => 'random', 'stress' => 10], ['resource' => 'food', 'delta' => 4]], 'Una corrente traditrice. Poco pesce, tanto spavento.', 6, 4, 'incerto'),
                    $this->one('La laguna è infida oggi', [], 'Meglio la fame della corrente.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_logbook_read', 'title' => 'Il diario del relitto', 'speaker' => 'Nadia',
                'body' => 'Tra i rottami, il diario di chi è precipitato qui prima di voi.',
                'requires' => ['has_item' => 'logbook'],
                'choices' => [
                    $this->one('Lo leggo', [['resource' => 'morale', 'delta' => -3], ['set_flag' => 'knows_the_past', 'value' => true], ['spawn_event' => ['key' => 'echo_knows_the_past', 'in_days' => 4]]], 'Sai com\'è finita, per loro.'),
                    $this->one('Meglio non sapere', [['resource' => 'morale', 'delta' => 2]], 'Richiudi il diario.'),
                ],
            ]),
            $this->ev([
                'key' => 'echo_knows_the_past',
                'title' => 'Quello che sapevi',
                'body' => "Un frutto che il diario chiamava velenoso spunta proprio dove cercavate cibo. Stavolta lo sai. Lo eviti prima che qualcuno lo morda.",
                'requires' => ['flag' => 'knows_the_past', 'is' => true],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Lo segno come veleno, da registro', [['resource' => 'food', 'delta' => 6], ['resource' => 'morale', 'delta' => 5]], 'Il sapere dei morti vi tiene in vita. Per oggi.'),
                ],
            ]),
        ];
    }

    // ---- Hunger: opportunities, rationing, sacrifice ------------------------
    private function hungerEvents(): array
    {
        return [
            $this->ev([
                'key' => 'food_harvest', 'title' => 'Il raccolto è pronto', 'speaker' => 'Bruno',
                'body' => "I germogli dell'orto hanno dato frutto. C'è di che riempire qualche stomaco, se raccogli ora.",
                'requires' => ['all' => [['has_item' => 'seedbank'], ['resource' => 'food', 'op' => '<', 'value' => 60], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 9, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Raccolgo tutto', [['resource' => 'food', 'delta' => 26], ['character' => 'all', 'hunger' => -10]], 'Mani sporche di terra, dispensa più piena.'),
                    $this->one('Lascio crescere ancora', [['resource' => 'morale', 'delta' => -2]], 'Pazienza. Forse domani rende di più.'),
                ],
            ]),
            $this->ev([
                'key' => 'food_spearfish', 'title' => 'Banco di pesci', 'speaker' => 'Carla',
                'body' => "La marea ha portato un banco vicino alla riva. Col fucile subacqueo se ne procura parecchio.",
                'requires' => ['all' => [['has_item' => 'speargun'], ['resource' => 'food', 'op' => '<', 'value' => 50], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 8, 'cooldown_days' => 4,
                'choices' => [
                    $this->gamble('Vado a pesca', [['resource' => 'food', 'delta' => 20], ['character' => 'all', 'hunger' => -8]], 'Reti piene. Si mangia.', [['character' => 'random', 'stress' => 12], ['resource' => 'food', 'delta' => 4]], 'Una corrente quasi ti porta via. Poca roba, tanto spavento.', 6, 4, 'rischioso'),
                    $this->one('Troppo pericoloso', [], 'Meglio la fame della corrente.'),
                ],
            ]),
            $this->ev([
                'key' => 'food_emergency_rations', 'title' => 'Le razioni d\'emergenza', 'speaker' => null,
                'body' => "Hai tenuto da parte le razioni sigillate per il giorno peggiore. Forse è oggi. Una volta aperte, sono finite.",
                'requires' => ['all' => [['has_item' => 'rations'], ['resource' => 'food', 'op' => '<', 'value' => 30], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 12, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Apro le scorte', [['resource' => 'food', 'delta' => 40], ['character' => 'all', 'hunger' => -25], ['consume_item' => 'rations']], 'Stomaci pieni, ultimo cuscinetto bruciato.'),
                    $this->one('Non ancora', [['character' => 'all', 'stress' => 4]], 'Le tieni. Stringi i denti un altro po\'.'),
                ],
            ]),
            $this->ev([
                'key' => 'food_ration', 'title' => 'Il pasto', 'speaker' => 'Bruno',
                'body' => "Il cibo cala e gli stomaci brontolano. Come lo distribuisci stasera?",
                'requires' => ['crew_hunger' => ['op' => '>=', 'value' => 25]],
                'base_weight' => 8, 'cooldown_days' => 1,
                'choices' => [
                    $this->one('Razione piena per tutti', [['resource' => 'food', 'delta' => -16], ['character' => 'all', 'hunger' => -28], ['resource' => 'morale', 'delta' => 5]], 'Stomaci pieni, dispensa più leggera.', 'dovrebbe reggere'),
                    $this->one('Mezza razione', [['resource' => 'food', 'delta' => -7], ['character' => 'all', 'hunger' => -12], ['character' => 'all', 'stress' => 4]], 'Nessuno è sazio, ma il cibo dura.', 'incerto'),
                    array_merge(
                        $this->one('Si salta il pasto stasera', [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => -6]], 'Pance vuote. La dispensa resta intatta — per ora.', 'molto pericoloso'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),
            $this->ev([
                'key' => 'food_triage', 'title' => 'Non basta per tutti', 'speaker' => 'Bruno',
                'body' => "Quel poco che resta sfama uno, non tutti. Bruno aspetta che tu decida. Non ti invidia.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 22],
                    ['crew_hunger' => ['op' => '>=', 'value' => 45]],
                ]],
                'base_weight' => 10, 'cooldown_days' => 2,
                'choices' => [
                    array_merge(
                        $this->one('Sfama chi ci tiene in vita (l\'ingegnere)', [['resource' => 'food', 'delta' => -8], ['character' => 'Nadia', 'hunger' => -45], ['modify_standing' => ['who' => 'Nadia', 'delta' => 8]]], 'Nadia mangia. Gli altri guardano. I sistemi reggeranno.', requires: ['has_role' => 'engineer']),
                        ['tags' => ['il_freddo']]
                    ),
                    $this->one('Sfama il più affamato', [['resource' => 'food', 'delta' => -8], ['character' => 'hungriest', 'hunger' => -45]], 'Dai da mangiare a chi sta peggio. È umano, se non efficiente.'),
                ],
            ]),
            $this->ev([
                'key' => 'food_sacrifice', 'title' => 'Non c\'è abbastanza per tutti', 'speaker' => null,
                'body' => "La dispensa è vuota e qualcuno non vedrà l'alba a stomaco pieno. C'è un pensiero che nessuno osa dire ad alta voce. Tocca a te dirlo, o rifiutarlo.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 6],
                    ['crew_hunger' => ['op' => '>=', 'value' => 70]],
                ]],
                'base_weight' => 14, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Uno perché gli altri vivano', [['kill' => 'hungriest'], ['resource' => 'food', 'delta' => 30], ['character' => 'all', 'hunger' => -30], ['resource' => 'morale', 'delta' => -30], ['modify_trust' => -35], ['set_flag' => 'cannibalism', 'value' => true]], 'È fatto. Nessuno ti guarderà più come prima. Nemmeno tu.'),
                        ['tags' => ['sacrifice_crew', 'il_freddo']]
                    ),
                    $this->gamble('Si stringe i denti, tutti insieme', [['character' => 'all', 'stress' => 12], ['resource' => 'morale', 'delta' => 6], ['modify_trust' => 10]], 'Vi tenete in piedi a vicenda. Stanotte non muore nessuno.', [['kill' => 'hungriest'], ['resource' => 'morale', 'delta' => -10]], 'Il più debole non passa la notte. Almeno non l\'hai scelto tu.', 6, 4, 'molto pericoloso'),
                ],
            ]),
            $this->ev([
                'key' => 'food_cache', 'title' => 'Una cassa portata dalla marea', 'speaker' => 'Carla',
                'body' => "Sulla battigia, una cassa dell'aereo intatta: razioni che credevate perse. Oggi la fortuna gira.",
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 45],
                'base_weight' => 4, 'cooldown_days' => 14,
                'choices' => [
                    $this->one('La porto all\'accampamento', [['resource' => 'food', 'delta' => 28], ['resource' => 'morale', 'delta' => 6]], 'Un respiro di sollievo, raro.'),
                ],
            ]),
        ];
    }

    // ---- Hard dilemmas (two legitimate options, costs in different currencies)
    private function dilemmaEvents(): array
    {
        return [
            $this->ev([
                'key' => 'dilemma_signal_choice', 'title' => 'Un solo segnale', 'speaker' => null,
                'body' => "Avete legna per un unico, grande falò di segnalazione, poi le riserve sono finite. Un SOS al mare — forse una nave passa, forse no. O tenere il fuoco basso e duraturo per scaldarvi le notti a venire.",
                'requires' => ['all' => [
                    ['has_item' => 'radio'],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->gamble('Lancia il grande segnale', [['resource' => 'morale', 'delta' => 15], ['set_flag' => 'sos_sent', 'value' => true]], 'La colonna di fumo sale altissima. Ora si aspetta.', [['resource' => 'morale', 'delta' => 8], ['resource' => 'fire', 'delta' => -10]], 'Brucia tutto. Nessuno risponde, e il falò è a secco.', 6, 4, 'incerto'),
                        []
                    ),
                    $this->one('Tieni il fuoco per le notti', [['set_flag' => 'research_complete', 'value' => true], ['resource' => 'morale', 'delta' => -8], ['character' => 'all', 'stress' => 6]], 'Niente segnale. Ma annoti tutto dell\'isola: un giorno servirà a qualcuno.'),
                ],
            ]),
            $this->ev([
                'key' => 'dilemma_rationing', 'title' => 'Come tagli le razioni', 'speaker' => null,
                'body' => "Il cibo non basta per tutti alla razione piena. Tagli uguale per tutti, e tutti si indeboliscono. O togli ai più fragili per tenere in forza chi lavora. Non c'è una scelta pulita.",
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 35],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Taglio uguale per tutti', [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => 3], ['modify_trust' => 5]], 'Nessuno è contento. Ma nessuno è stato abbandonato.'),
                    array_merge(
                        $this->one('Tolgo ai più deboli', [['resource' => 'food', 'delta' => 8], ['character' => 'highest_stress', 'stress' => 20], ['modify_trust' => -15]], 'L\'accampamento continua a reggere. Qualcuno ti guarda diverso.'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),
            $this->ev([
                'key' => 'dilemma_reef_dive', 'title' => 'Chi scende alla barriera', 'speaker' => null,
                'body' => "Sotto la barriera c'è il relitto, e dentro forse provviste. Vai tu — e se non risali, chi guida il gruppo? O mandi qualcuno, e tutti ti vedono scegliere chi rischia al posto tuo.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 40],
                    ['day' => ['op' => '>=', 'value' => 8]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Vai tu', [['resource' => 'food', 'delta' => 22], ['modify_trust' => 20], ['character' => 'all', 'stress' => -5]], 'Risali con le braccia piene. L\'accampamento ti guarda diversamente — meglio.', [['resource' => 'food', 'delta' => 18], ['resource' => 'water', 'delta' => -10], ['character' => 'all', 'stress' => 8]], 'Risali a fatica, mezzo annegato. È costato caro.', 7, 3, 'rischioso'),
                    array_merge(
                        $this->gamble('Mandi qualcuno', [['resource' => 'food', 'delta' => 20], ['character' => 'random', 'stress' => 20], ['modify_trust' => -10]], 'Risale tremando, e non ti ringrazia.', [['resource' => 'food', 'delta' => 16], ['kill' => 'random'], ['set_flag' => 'sent_to_drown', 'value' => true]], 'Le provviste risalgono. La persona che hai mandato no.', 7, 3, 'molto pericoloso'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),
            $this->ev([
                'key' => 'dilemma_stay_behind', 'title' => 'Restare indietro', 'speaker' => null,
                'body' => "C'è una via verso l'altro versante, dove forse c'è soccorso, ma il gruppo è troppo lento e affamato per arrivarci unito. Se uno resta indietro a distrarre i pericoli, gli altri ce la fanno. Quello potresti essere tu.",
                'requires' => ['all' => [
                    ['day' => ['op' => '>=', 'value' => 14]],
                    ['any' => [
                        ['resource' => 'water', 'op' => '<', 'value' => 30],
                        ['resource' => 'food', 'op' => '<', 'value' => 30],
                        ['resource' => 'fire', 'op' => '<', 'value' => 30],
                    ]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Resto io. Andate.', [['set_flag' => 'made_the_sacrifice', 'value' => true], ['resource' => 'morale', 'delta' => -6], ['modify_trust' => 25]], 'Li guardi sparire oltre il crinale. Hai fatto la tua scelta.'),
                    $this->one('Restiamo uniti, costi quel che costi', [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => 4]], 'Nessuno indietro. Più lenti, ma insieme.'),
                ],
            ]),
        ];
    }

    // ---- Moral dilemmas / ate_alone ------------------------------------------
    private function moralEvents(): array
    {
        return [
            $this->ev([
                'key' => 'moral_ate_alone', 'title' => 'Di nascosto', 'speaker' => null,
                'body' => "Hai trovato un frutto, uno solo. Potresti dividerlo, o mangiarlo voltando le spalle agli altri. Nessuno ti vede. Per ora.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 25],
                    ['crew_hunger' => ['op' => '>=', 'value' => 40]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Lo mangio da solo', [['character' => 'all', 'hunger' => 4], ['set_flag' => 'ate_alone', 'value' => true], ['spawn_event' => ['key' => 'echo_ate_alone', 'in_days' => 3]]], 'Lo mandi giù in fretta, voltato. Più forte tu, soli loro.'),
                        ['tags' => ['il_freddo']]
                    ),
                    $this->one('Lo divido con tutti', [['resource' => 'morale', 'delta' => 5], ['character' => 'all', 'hunger' => -4]], 'Un boccone a testa. Niente, e tutto.'),
                ],
            ]),
            $this->ev([
                'key' => 'echo_ate_alone', 'title' => 'Hanno visto', 'speaker' => 'Nadia',
                'body' => "Giorni fa hai mangiato voltando le spalle agli altri. Non l'hanno dimenticato. «Pensavo fossimo una squadra», dice Nadia, senza alzare la voce. È peggio così.",
                'requires' => ['all' => [['flag' => 'ate_alone', 'is' => true], ['alive' => 'Nadia']]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Dovevo reggere. Per tutti voi.', [['resource' => 'morale', 'delta' => -4], ['modify_standing' => ['who' => 'Nadia', 'delta' => -8]]], 'Nadia annuisce piano. Non ci crede.'),
                    $this->one('Avete ragione. Non succederà più.', [['resource' => 'morale', 'delta' => 4], ['modify_standing' => ['who' => 'Nadia', 'delta' => 6]]], 'Una crepa ricucita a fatica.'),
                ],
            ]),
            $this->ev([
                'key' => 'moral_log', 'title' => 'Il diario di bordo', 'speaker' => null,
                'body' => "Tieni un diario di quello che succede sull'isola. Stanotte è successo qualcosa di cui non vai fiero. Puoi scriverlo com'è, o aggiustarlo.",
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Scrivo la verità', [['resource' => 'morale', 'delta' => 5], ['modify_trust' => 10]], 'La verità resta scritta. Un giorno qualcuno la leggerà.'),
                        ['tags' => ['honest']]
                    ),
                    array_merge(
                        $this->one('Aggiusto i fatti', [['set_flag' => 'log_falsified', 'value' => true]], 'Le parole mentono meglio della memoria.'),
                        ['tags' => ['lone_decision']]
                    ),
                ],
            ]),
        ];
    }

    // ---- Domino chains (ignored choice → future crisis, incl. mutiny) -------
    private function dominoEvents(): array
    {
        return [
            $this->ev([
                'key' => 'leak_warning', 'title' => 'Una falla nella scorta d\'acqua',
                'body' => "Il barile dell'acqua perde da una fessura. Niente di urgente, per ora.",
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Riparo subito', [['resource' => 'fire', 'delta' => -8]], 'La fessura è sigillata. È costato un po\' di legna.'),
                    array_merge(
                        $this->one('Tengo d\'occhio e basta', [['spawn_event' => ['key' => 'water_crisis', 'in_days' => 6]]], 'Segnato. Probabilmente si stabilizzerà.'),
                        ['tags' => ['ignored_warning']]
                    ),
                ],
            ]),
            $this->ev([
                'key' => 'water_crisis', 'title' => 'CRISI DELL\'ACQUA',
                'body' => "La piccola falla che hai ignorato non si è stabilizzata. Il barile è quasi vuoto. Non c'è via d'uscita pulita.",
                'requires' => ['chosen_tag' => 'ignored_warning'],
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Brucio legna per distillare in fretta', [['resource' => 'fire', 'delta' => -20], ['resource' => 'water', 'delta' => 12]], 'Recuperate un po\' d\'acqua. Il falò ne paga il prezzo.'),
                    $this->one('Razioniamo all\'osso', [['character' => 'all', 'stress' => 18], ['resource' => 'water', 'delta' => -6]], 'Gole secche. Nervi a pezzi.'),
                ],
            ]),
            $this->ev([
                'key' => 'ration_cut_decision', 'title' => 'Le razioni non bastano',
                'body' => "Il cibo finisce più in fretta del previsto. Devi decidere come gestire la distribuzione.",
                'base_weight' => 9, 'cooldown_days' => 999,
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 50],
                'choices' => [
                    $this->one('Taglio uguale per tutti', [['resource' => 'morale', 'delta' => -5]], 'Nessuno è contento. Almeno nessuno è trattato diversamente.'),
                    array_merge(
                        $this->one('Priorità a chi lavora di più', [['resource' => 'morale', 'delta' => -15], ['modify_trust' => -20], ['spawn_event' => ['key' => 'camp_revolt', 'in_days' => 5]]], "La decisione ha un senso logico. Il gruppo non è d'accordo."),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),
            $this->ev([
                'key' => 'camp_revolt', 'title' => 'La rivolta dell\'accampamento',
                'body' => "Quello che hai fatto con le razioni ha bollito sotto la cenere. Ora è esploso. Due si rifiutano di lavorare finché il sistema non cambia.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Cedo e ridistribuisco', [['modify_trust' => 15], ['resource' => 'morale', 'delta' => 10]], 'La tensione cala. Hai ceduto, ma il gruppo torna a respirare.'),
                    $this->one('Mantengo la linea', [['modify_trust' => -25], ['resource' => 'morale', 'delta' => -15], ['spawn_event' => ['key' => 'mutiny_trigger', 'in_days' => 3]]], 'Silenzio. Del tipo sbagliato.'),
                ],
            ]),
            $this->ev([
                'key' => 'mutiny_trigger', 'title' => 'AMMUTINAMENTO',
                'body' => "Hanno aspettato che dormissi. Quando ti svegli, il gruppo ha deciso senza di te. Ti dicono dove dormirai e cosa mangerai. Il comando non è più tuo.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Negozio', [['modify_trust' => 40], ['resource' => 'morale', 'delta' => 5]], 'Trovi un accordo duro. La guida è condivisa. L\'accampamento respira ancora.'),
                    $this->one('Cedo la guida', [['set_flag' => 'mutiny_occurred', 'value' => true], ['resource' => 'morale', 'delta' => 10]], 'Lasci andare. Forse è la cosa più saggia che hai fatto.'),
                ],
            ]),
        ];
    }

    // ---- Silent cards (narrative-only / scheduled spawns) -------------------
    private function silentEvents(): array
    {
        return [
            $this->ev([
                'key' => 'death_notice', 'title' => 'In memoria', 'speaker' => null,
                'body' => "Un nome in meno all'appello. La spiaggia sembra più grande, e più vuota. Ti fermi un momento. Poi si va avanti — non c'è altro da fare.",
                'requires' => ['flag' => '__never', 'is' => true],
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Un momento di silenzio', [['resource' => 'morale', 'delta' => -3]], 'Poi torni al lavoro.'),
                ],
            ]),
            $this->ev([
                'key' => 'hunger_warning', 'title' => 'Pelle e ossa', 'speaker' => null,
                'body' => "Qualcuno è ridotto a pelle e ossa. Senza cibo, presto, non si rialzerà. Lo vedi negli occhi di tutti: il tempo stringe.",
                'requires' => ['flag' => '__never', 'is' => true],
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Lo so. Faccio quel che posso.', [['resource' => 'morale', 'delta' => -2]], 'Le parole non riempiono lo stomaco.'),
                ],
            ]),
            $this->ev([
                'key' => 'food_ration_spawn', 'title' => 'Stomaci vuoti', 'speaker' => 'Bruno',
                'body' => "La fame si fa sentire. Bisogna decidere come dividere quel che c'è.",
                'requires' => ['flag' => '__never', 'is' => true],
                'base_weight' => 0, 'cooldown_days' => 0,
                'choices' => [
                    $this->one('Dividiamo il poco che c\'è', [['character' => 'all', 'hunger' => -10], ['resource' => 'food', 'delta' => -6]], 'Un boccone a testa.'),
                ],
            ]),
            $this->ev([
                'key' => 'silent_ocean', 'title' => 'L\'oceano',
                'body' => "Carla è ferma sulla battigia da venti minuti, lo sguardo all'orizzonte. Non si gira quando arrivi. Il mare non risponde, ma almeno non mente.",
                'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 8,
                'choices' => [],
            ]),
            $this->ev([
                'key' => 'silent_jungle_sounds', 'title' => 'I versi della giungla',
                'body' => "Di notte l'isola ha un suono tutto suo. Non sai se è rassicurante o inquietante. Hai smesso di interrogarti su queste cose.",
                'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 10,
                'choices' => [],
            ]),
            $this->ev([
                'key' => 'phase_enter_deterioration', 'title' => 'Qualcosa è cambiato', 'speaker' => null,
                'body' => "Lo senti nell'aria: l'umidità che marcisce il legno, le scorte che calano più in fretta. Da qui in avanti, ogni giorno costa di più.",
                'requires' => ['phase' => 'deterioration'],
                'base_weight' => 1, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Stringo i denti', [], 'Non c\'è altro da fare. Si va avanti.'),
                ],
            ]),
            $this->ev([
                'key' => 'phase_enter_reckoning', 'title' => 'Non c\'è più tempo', 'speaker' => null,
                'body' => "I margini si sono esauriti. Ogni scelta adesso pesa il doppio, e gli errori non si recuperano più. Qualunque cosa succeda, succede ora.",
                'requires' => ['phase' => 'reckoning'],
                'base_weight' => 1, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Affronto quello che viene', [], 'Qualunque cosa sia.'),
                ],
            ]),
        ];
    }

    // ---- Filler (always eligible, low stakes — keeps the loop flowing) ------
    private function fillerEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_filler_horizon', 'title' => 'L\'orizzonte', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Fuori, solo mare e cielo indifferenti.',
                'choices' => [
                    $this->one('Mi fermo a guardare', [['resource' => 'morale', 'delta' => 2]], 'Un istante di pace.'),
                    $this->one('Torno al lavoro', [['resource' => 'fire', 'delta' => 1]], 'C\'è sempre qualcosa da fare.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_coconut', 'title' => 'Latte di cocco', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Tiepido e dolciastro, ma disseta.',
                'choices' => [
                    $this->one('Ne offro a tutti', [['character' => 'all', 'stress' => -3]], 'Piccolo lusso condiviso.'),
                    $this->one('Me lo tengo', [['resource' => 'morale', 'delta' => 1]], 'Un momento solo tuo.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_drill', 'title' => 'Esercitazione', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => 'Una prova: cosa fare se arriva la nave, o la tempesta.',
                'choices' => [
                    $this->one('La facciamo', [['character' => 'all', 'stress' => 2], ['resource' => 'shelter', 'delta' => 2]], 'Pronti, se servirà.'),
                    $this->one('Salto, c\'è altro', [], 'Magari domani.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_mend', 'title' => 'Piccole riparazioni', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Una giornata di rattoppi al riparo e agli attrezzi.',
                'choices' => [
                    $this->one('Sistemo il sistemabile', [['resource' => 'shelter', 'delta' => 2]], 'Niente di che, ma utile.'),
                    $this->one('Riposo', [['character' => 'all', 'stress' => -2]], 'Le mani ferme per un giorno.'),
                ],
            ]),
        ];
    }
}
