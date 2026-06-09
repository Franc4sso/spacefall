<?php

namespace Database\Seeders;

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * First content-injection batch (~20 cards). Fills thematic gaps (comms,
 * propulsion, dilemmas, atmosphere, rationing, hull, system/resource crises)
 * with BROAD gates so they enlarge the common pool, and REAL dilemmas — every
 * multi-choice card has no dominant option; each choice pays a price on a
 * different axis. Keys are prefixed `fc_`. Italian player text, terse + bleak.
 */
final class FillContentEventSeeder extends Seeder
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
        return array_merge(
            $this->commsEvents(),
            $this->propulsionEvents(),
            $this->dilemmaEvents(),
            $this->crisisEvents(),
            $this->atmosphereEvents(),
        );
    }

    // ---- Atmosphere (single-choice narrative beats; texture, not decisions) -
    private function atmosphereEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fc_atmo_drawing', 'title' => 'Un disegno', 'speaker' => null,
                'body' => "Su una paratia, graffito da chi c'era prima: una casa, un sole, una figura piccola. Nessuno l'aveva mai notato.",
                'base_weight' => 4, 'cooldown_days' => 10,
                'choices' => [
                    $this->one('Resti a guardarlo', [['resource' => 'morale', 'delta' => -2]], 'Poi torni al lavoro. Più lento.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_atmo_radio_voice', 'title' => 'Una voce nella statica', 'speaker' => null,
                'body' => "Per un istante, nella statica, una voce che dice il tuo nome. Poi più niente. Forse non era niente.",
                'base_weight' => 4, 'cooldown_days' => 12,
                'choices' => [
                    $this->one('Spegni la radio', [['character' => 'random', 'stress' => 3]], 'Il silenzio adesso pesa.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_atmo_clock', 'title' => 'L\'orologio fermo', 'speaker' => 'Bex',
                'body' => "L'orologio di bordo si è fermato a un'ora qualsiasi. Bex dice che è meglio così: i giorni qui non andrebbero contati.",
                'base_weight' => 3, 'cooldown_days' => 14,
                'choices' => [
                    $this->one('Annuisci', [['resource' => 'morale', 'delta' => 2]], 'Un piccolo accordo silenzioso sul non sapere.'),
                ],
            ]),
        ];
    }

    // ---- Comms (broad-gated: signal/contact dilemmas) ----------------------
    private function commsEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fc_comms_garbled', 'title' => 'Trasmissione disturbata', 'speaker' => null,
                'body' => "La radio capta qualcosa — voce o statica, non si capisce. Pulirla richiede energia che non hai da sprecare; ignorarla ti lascia il dubbio.",
                'requires' => ['has_item' => 'comms'],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Spingo gli amplificatori', [['resource' => 'power', 'delta' => -10], ['resource' => 'morale', 'delta' => 5]], 'Una parola, forse un nome. L\'equipaggio si aggrappa alla speranza.'),
                    $this->one('Spengo, non possiamo permettercelo', [['resource' => 'morale', 'delta' => -6], ['set_flag' => 'fc_ignored_signal', 'value' => true]], 'Il silenzio torna. Qualcosa che non saprai mai.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_comms_one_message', 'title' => 'Un solo messaggio', 'speaker' => 'Bex',
                'body' => "Resta energia per UNA trasmissione lunga. Bex vuole chiamare i soccorsi; mandarla brucia la riserva che terrebbe acceso il supporto vitale stanotte.",
                'requires' => ['all' => [['has_item' => 'comms'], ['resource' => 'power', 'op' => '<', 'value' => 60]]],
                'base_weight' => 10, 'cooldown_days' => 9,
                'choices' => [
                    $this->one('Chiama i soccorsi', [['resource' => 'power', 'delta' => -18], ['resource' => 'morale', 'delta' => 10]], 'Il messaggio parte nel buio. Stanotte si trema, ma si spera.'),
                    $this->one('Tieni l\'energia per stanotte', [['resource' => 'morale', 'delta' => -10], ['modify_standing' => ['who' => 'Bex', 'delta' => -10]]], 'Bex non discute. È peggio.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_comms_loop', 'title' => 'Il messaggio in loop', 'speaker' => null,
                'body' => "Un segnale automatico si ripete da ore: coordinate, o un avvertimento. Decifrarlo tiene Anna lontana dalle riparazioni; ignorarlo pesa.",
                'requires' => ['has_item' => 'comms'],
                'base_weight' => 9, 'cooldown_days' => 8,
                'choices' => [
                    $this->one('Anna lo decifra', [['character' => 'Anna', 'stress' => 10], ['damage_system' => 'power_grid', 'amount' => 8]], 'Coordinate. Forse utili. La rete intanto è rimasta indietro.'),
                    $this->one('Lascialo andare', [['resource' => 'morale', 'delta' => -5], ['set_flag' => 'fc_ignored_signal', 'value' => true]], 'Continua a ripetersi. Smetti di sentirlo.'),
                ],
            ]),
        ];
    }

    // ---- Propulsion (broad-gated: thrust vs structure vs time) -------------
    private function propulsionEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fc_engine_overheat', 'title' => 'Il motore scotta', 'speaker' => 'Cole',
                'body' => "Un propulsore va in temperatura. Cole può spingerlo ancora per guadagnare margine, o spegnerlo e perderlo.",
                'requires' => ['resource' => 'power', 'op' => '<', 'value' => 70],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Spingi ancora', [['resource' => 'power', 'delta' => 8], ['damage_system' => 'hull_integrity', 'amount' => 12]], 'Guadagni spinta. Lo scafo geme.'),
                    $this->one('Spegni e raffredda', [['resource' => 'power', 'delta' => -10], ['character' => 'Cole', 'stress' => 6]], 'Salvi il propulsore. Resti più lento, più esposto.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_thruster_drift', 'title' => 'Deriva', 'speaker' => null,
                'body' => "Un thruster sbilanciato vi fa derivare. Correggere a mano costa ossigeno (tute, EVA); lasciar correre danneggia lo scafo a ogni rotazione.",
                'requires' => ['resource' => 'hull', 'op' => '<', 'value' => 70],
                'base_weight' => 10, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Correzione manuale, EVA', [['resource' => 'oxygen', 'delta' => -12], ['resource' => 'hull', 'delta' => 8]], 'Rientrate gelati ma allineati.'),
                    $this->one('Lascia derivare', [['damage_system' => 'hull_integrity', 'amount' => 10], ['character' => 'all', 'stress' => 5]], 'Ogni giro è un colpo. Tutti lo sentono.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_course_choice', 'title' => 'Due rotte', 'speaker' => 'Anna',
                'body' => "Anna traccia due rotte: una breve che passa vicino a un campo di detriti, una lunga e sicura che brucia scorte. Nessuna è gratis.",
                'requires' => ['day' => ['op' => '>=', 'value' => 6]],
                'base_weight' => 9, 'cooldown_days' => 12,
                'choices' => [
                    $this->one('Rotta breve, tra i detriti', [['resource' => 'hull', 'delta' => -14], ['resource' => 'food', 'delta' => 6]], 'Passate. Lo scafo porta i segni.'),
                    $this->one('Rotta lunga e sicura', [['resource' => 'food', 'delta' => -16], ['character' => 'all', 'stress' => 6]], 'Più giorni, più fame. Ma interi.'),
                ],
            ]),
        ];
    }

    // ---- Moral dilemmas (no right answer; cost on a human axis) ------------
    private function dilemmaEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fc_who_sleeps_warm', 'title' => 'Chi dorme al caldo', 'speaker' => null,
                'body' => "Una sola cuccetta resta vicino al condotto caldo. Darla a chi lavora di più tiene in piedi le riparazioni; darla a chi sta peggio tiene in piedi il morale.",
                'requires' => ['resource' => 'morale', 'op' => '<', 'value' => 60],
                'base_weight' => 10, 'cooldown_days' => 8,
                'choices' => [
                    $this->one('A chi lavora di più (Anna)', [['resource' => 'morale', 'delta' => -8], ['modify_standing' => ['who' => 'Anna', 'delta' => 8]], ['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -8]]], 'Pragmatico. Bex non ti guarda.'),
                    $this->one('A chi sta peggio', [['resource' => 'morale', 'delta' => 8], ['damage_system' => 'power_grid', 'amount' => 10]], 'Umano. Le riparazioni rallentano.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_confession', 'title' => 'Una confessione', 'speaker' => 'Cole',
                'body' => "Cole ti confida in privato un errore che ha messo tutti in pericolo. Dirlo all'equipaggio è onesto ma lo distrugge; tacere ti rende complice.",
                'requires' => ['day' => ['op' => '>=', 'value' => 5]],
                'base_weight' => 9, 'cooldown_days' => 14,
                'choices' => [
                    array_merge($this->one('Dillo a tutti', [['modify_trust' => 10], ['character' => 'Cole', 'stress' => 15], ['modify_standing' => ['who' => 'Cole', 'delta' => -15]]], 'La verità pulisce l\'aria. Cole annega.'), ['tags' => ['honest']]),
                    array_merge($this->one('Tieni il segreto', [['modify_trust' => -8], ['set_flag' => 'fc_kept_secret', 'value' => true]], 'Resta tra voi due. Un peso in più da portare.'), ['tags' => ['lone_decision']]),
                ],
            ]),
            $this->ev([
                'key' => 'fc_ration_the_sick', 'title' => 'Le medicine che restano', 'speaker' => 'Bex',
                'body' => "Restano poche dosi. Bex chiede di usarle ora per chi soffre; tenerle per un'emergenza peggiore è prudente ma crudele adesso.",
                'requires' => ['has_role' => 'doctor'],
                'base_weight' => 9, 'cooldown_days' => 12,
                'choices' => [
                    array_merge($this->one('Usale adesso', [['resource' => 'morale', 'delta' => 8], ['consume_item' => 'medkit']], 'Sollievo, ora. Il kit è vuoto.'), ['tags' => ['generous']]),
                    array_merge($this->one('Tienile per il peggio', [['character' => 'all', 'stress' => 8], ['modify_standing' => ['who' => 'Bex', 'delta' => -10]]], 'Razionale. Bex stringe i denti.'), ['tags' => ['il_freddo']]),
                ],
            ]),
        ];
    }

    // ---- System/resource crises (broad gates; enlarge the common pool) -----
    private function crisisEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fc_condensation', 'title' => 'Condensa nei circuiti', 'speaker' => null,
                'body' => "L'umidità minaccia un quadro elettrico. Asciugarlo col calore costa ossigeno; isolarlo a freddo costa una presa di corrente.",
                'requires' => ['resource' => 'power', 'op' => '<', 'value' => 75],
                'base_weight' => 12, 'cooldown_days' => 5,
                'choices' => [
                    $this->one('Asciuga col calore', [['resource' => 'oxygen', 'delta' => -8], ['resource' => 'power', 'delta' => 6]], 'Circuiti salvi. Aria più pesante.'),
                    $this->one('Isola a freddo', [['resource' => 'power', 'delta' => -10], ['character' => 'all', 'stress' => 4]], 'Una sezione spenta. Si lavora al buio.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_filter_clog', 'title' => 'Filtri intasati', 'speaker' => 'Anna',
                'body' => "I filtri dell'aria perdono colpi. Pulirli a fondo ferma tutto per ore; un lavoro veloce regge poco e logora chi lo fa.",
                'requires' => ['resource' => 'oxygen', 'op' => '<', 'value' => 70],
                'base_weight' => 12, 'cooldown_days' => 5,
                'choices' => [
                    $this->one('Pulizia a fondo', [['resource' => 'oxygen', 'delta' => 10], ['resource' => 'power', 'delta' => -8]], 'Aria pulita. Mezza giornata persa.'),
                    $this->one('Rattoppo veloce', [['resource' => 'oxygen', 'delta' => 4], ['character' => 'Anna', 'stress' => 8], ['set_flag' => 'fc_patched_filters', 'value' => true]], 'Regge. Per ora. Anna lo sa.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_coolant_leak', 'title' => 'Perdita di refrigerante', 'speaker' => null,
                'body' => "Il refrigerante cala. Rabboccarlo dalle riserve vita prosciuga l'acqua; lasciar correre fa surriscaldare la rete.",
                'requires' => ['day' => ['op' => '>=', 'value' => 4]],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Rabbocca dalle riserve', [['resource' => 'food', 'delta' => -10], ['resource' => 'power', 'delta' => 6]], 'La rete respira. Le scorte calano.'),
                    $this->one('Lascia surriscaldare', [['damage_system' => 'power_grid', 'amount' => 14]], 'Regge il magazzino. Frigge la rete.'),
                ],
            ]),
            $this->ev([
                'key' => 'fc_night_cold', 'title' => 'Notte sotto zero', 'speaker' => 'Cole',
                'body' => "Il riscaldamento non basta per tutta la stazione. Scaldi le cabine (morale) o la serra/magazzino (scorte): una delle due gela.",
                'requires' => ['resource' => 'power', 'op' => '<', 'value' => 65],
                'base_weight' => 11, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Scalda le cabine', [['resource' => 'morale', 'delta' => 6], ['resource' => 'food', 'delta' => -10]], 'Si dorme al caldo. Qualcosa, in magazzino, si guasta.'),
                    $this->one('Scalda il magazzino', [['resource' => 'food', 'delta' => 4], ['character' => 'all', 'stress' => 6]], 'Le scorte tengono. La notte è lunga e fredda.'),
                ],
            ]),
        ];
    }

    private function ev(array $e): array
    {
        return array_merge([
            'speaker' => null, 'base_weight' => 12, 'cooldown_days' => 4,
            'is_filler' => false, 'requires' => null, 'weight_modifiers' => null,
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
}
