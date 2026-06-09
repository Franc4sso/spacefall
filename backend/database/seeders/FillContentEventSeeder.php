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
        );
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
