<?php

namespace Database\Seeders;

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * Phase 8 content pass — the bulk of the event library (~40 events).
 *
 * Italian player-facing text (Reigns × 60 Seconds voice: short, dry, graspable
 * in two seconds), English keys/flags. Every row is validated against the DSL
 * schema before insert. Variety is deliberate: events triggered by resources,
 * by systems, by characters/traits, by relationships, by items, plus run- and
 * profile-scoped flag callbacks and spawn_event consequence chains.
 *
 * Speakers (Anna/Bex/Cole) drive the trait-distorted hints, so the same risk
 * reads differently depending on who's talking.
 */
class ContentEventSeeder extends Seeder
{
    public function run(): void
    {
        $schema = new EventSchema(array_keys(config('game.resources')));

        foreach ($this->events() as $event) {
            $schema->validate($event);
            Event::updateOrCreate(['key' => $event['key']], $event);
        }
    }

    /** Small helpers to keep the data terse. */
    private function ev(array $e): array
    {
        return array_merge([
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
            $this->relationshipEvents(),
            $this->itemEvents(),
            $this->memoryEvents(),
            $this->fillerEvents(),
        );
    }

    // ---- Resource-triggered -------------------------------------------------
    private function resourceEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_oxygen_leak', 'title' => 'Fischio nell\'aria', 'speaker' => 'Anna',
                'body' => 'Senti l\'ossigeno andarsene da qualche parte.',
                'requires' => ['resource' => 'oxygen', 'op' => '<', 'value' => 60],
                'choices' => [
                    $this->one('Cerco la perdita a tentoni', [['resource' => 'oxygen', 'delta' => 5]], 'La trovi. Per stavolta.'),
                    $this->gamble('Sigillo a naso', [['resource' => 'oxygen', 'delta' => 8]], 'Indovinato.', [['resource' => 'oxygen', 'delta' => -6]], 'Sbagliato condotto.', 6, 4),
                ],
            ]),
            $this->ev([
                'key' => 'c_cold_night', 'title' => 'Notte gelida', 'speaker' => 'Cole',
                'body' => 'Il riscaldamento arranca. Si batte i denti.',
                'requires' => ['resource' => 'power', 'op' => '<', 'value' => 50],
                'choices' => [
                    $this->one('Spengo tutto tranne il calore', [['resource' => 'power', 'delta' => -4], ['resource' => 'morale', 'delta' => 4]], 'Almeno non si gela.'),
                    $this->one('Si stringe i denti', [['character' => 'all', 'stress' => 6]], 'Lunga notte.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_mold', 'title' => 'Muffa nelle scorte', 'speaker' => 'Bex',
                'body' => 'Metà delle razioni sa di chiuso.',
                // A waste event, not a crisis: only when there's still food to
                // lose. Never appears in the lethal zone, so no choice can
                // starve you on this card.
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 60],
                    ['resource' => 'food', 'op' => '>', 'value' => 25],
                ]],
                'choices' => [
                    $this->one('Butto il marcio', [['resource' => 'food', 'delta' => -6], ['resource' => 'morale', 'delta' => 2]], 'Meno cibo, ma sano.'),
                    $this->gamble('Si mangia lo stesso', [['resource' => 'food', 'delta' => 2]], 'Regge lo stomaco.', [['resource' => 'food', 'delta' => -4], ['character' => 'random', 'stress' => 10]], 'Qualcuno sta male.', 5, 5, 'azzardo'),
                ],
            ]),
            $this->ev([
                'key' => 'c_morale_high', 'title' => 'Troppa euforia', 'speaker' => 'Cole',
                'body' => 'Ridono forte. Forse troppo, per gente in trappola.',
                'requires' => ['resource' => 'morale', 'op' => '>=', 'value' => 80],
                'choices' => [
                    $this->one('Riporto tutti coi piedi a terra', [['resource' => 'morale', 'delta' => -12]], 'Il silenzio cala. Meglio prudenti.'),
                    $this->one('Lascio correre', [['resource' => 'morale', 'delta' => 4], ['resource' => 'hull', 'delta' => -4]], 'Qualcuno combina un guaio per scommessa.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_water_ration', 'title' => 'Acqua razionata', 'speaker' => 'Bex',
                'body' => 'Il riciclo idrico non ce la fa per tutti.',
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 40],
                'choices' => [
                    $this->one('Razioni rigide', [['character' => 'all', 'stress' => 6], ['resource' => 'food', 'delta' => 4]], 'Bocche asciutte, scorte intatte.', 'dovrebbe reggere'),
                    $this->one('Bere a volontà oggi', [['resource' => 'food', 'delta' => -8], ['resource' => 'morale', 'delta' => 5]], 'Un sollievo breve.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_power_surge', 'title' => 'Picco di corrente', 'speaker' => 'Anna',
                'body' => 'La rete sputa un picco. Puoi sfruttarlo o subirlo.',
                'requires' => ['resource' => 'power', 'op' => '>=', 'value' => 60],
                'choices' => [
                    $this->one('Carico le batterie', [['resource' => 'power', 'delta' => 8]], 'Accumulato bene.'),
                    $this->gamble('Spingo i sistemi', [['resource' => 'oxygen', 'delta' => 6]], 'Riciclo a pieno regime.', [['damage_system' => 'power_grid', 'amount' => 12]], 'Un fusibile cede.', 6, 4, 'rischioso'),
                ],
            ]),
            $this->ev([
                'key' => 'c_hull_creak', 'title' => 'Lo scafo scricchiola', 'speaker' => 'Anna',
                'body' => 'Un gemito di metallo da poppa.',
                'requires' => ['resource' => 'hull', 'op' => '<', 'value' => 70],
                'choices' => [
                    $this->one('Rinforzo la paratia', [['resource' => 'hull', 'delta' => 8], ['resource' => 'power', 'delta' => -3]], 'Tiene meglio.'),
                    $this->one('Annoto e vado avanti', [['spawn_event' => ['key' => 'c_hull_give', 'in_days' => 3]]], 'Ci pensi domani. Forse.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_hull_give', 'title' => 'La paratia cede', 'speaker' => 'Anna',
                'body' => 'Lo scricchiolio ignorato è diventato una crepa.',
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Tamponamento d\'emergenza', [['resource' => 'hull', 'delta' => -8], ['resource' => 'oxygen', 'delta' => -5]], 'Fermata, a caro prezzo.'),
                    $this->one('Sigillo il settore', [['resource' => 'hull', 'delta' => -4], ['resource' => 'morale', 'delta' => -6]], 'Un pezzo di stazione perso.'),
                ],
            ]),
        ];
    }

    // ---- System-triggered ---------------------------------------------------
    private function systemEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_ls_failing', 'title' => 'Supporto vitale instabile', 'speaker' => 'Anna',
                'body' => 'I filtri dell\'aria perdono colpi.',
                'requires' => ['system' => 'life_support', 'field' => 'efficiency', 'op' => '<', 'value' => 60],
                'choices' => [
                    $this->one('Pulisco i filtri', [['resource' => 'power', 'delta' => -5]], 'Respiro più pulito.', requires: null),
                    $this->one('Bypass coi pezzi del fabbricatore', [['resource' => 'oxygen', 'delta' => 10]], 'Soluzione elegante.', requires: ['has_item' => 'fabricator']),
                    $this->one('Lascio andare', [['spawn_event' => ['key' => 'c_ls_collapse', 'in_days' => 2]]], 'Reggerà ancora un po\'. Speri.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_ls_collapse', 'title' => 'Aria viziata', 'speaker' => 'Bex',
                'body' => 'Il supporto vitale ignorato sta soffocando tutti.',
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Riavvio forzato', [['resource' => 'power', 'delta' => -15], ['resource' => 'oxygen', 'delta' => 8]], 'Riparte tossendo.'),
                    $this->one('Maschere per tutti', [['character' => 'all', 'stress' => 12], ['resource' => 'oxygen', 'delta' => -4]], 'Respiri corti, nervi tesi.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_grid_short', 'title' => 'Corto alla rete', 'speaker' => 'Anna',
                'body' => 'Una sezione della rete elettrica fa scintille.',
                'requires' => ['system' => 'power_grid', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                'choices' => [
                    $this->one('Isolo la sezione', [['resource' => 'power', 'delta' => -6]], 'Spento e sicuro.'),
                    $this->gamble('Riparo a caldo', [['resource' => 'power', 'delta' => 6]], 'Mani ferme, lavoro pulito.', [['character' => 'random', 'stress' => 14], ['resource' => 'power', 'delta' => -4]], 'Una scossa. Brutta.', 5, 5, 'pericoloso'),
                ],
            ]),
            $this->ev([
                'key' => 'c_sensor_ghost', 'title' => 'Falso allarme', 'speaker' => 'Cole',
                'body' => 'I sensori urlano per qualcosa che non c\'è.',
                'requires' => ['has_item' => 'sensors'],
                'choices' => [
                    $this->one('Mi fido e controllo', [['resource' => 'morale', 'delta' => -2]], 'Niente. Tempo perso, nervi salvi.'),
                    $this->one('Li ignoro', [['spawn_event' => ['key' => 'c_real_threat', 'in_days' => 2]]], 'Stavolta era niente. Stavolta.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_real_threat', 'title' => 'Non era un falso allarme', 'speaker' => 'Cole',
                'body' => 'Quello che hai ignorato è arrivato fin qui.',
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Affronto il guasto', [['damage_system' => 'hull_integrity', 'amount' => 10], ['resource' => 'hull', 'delta' => -6]], 'Contenuto a malapena.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_reboot', 'title' => 'Riavvio generale', 'speaker' => 'Anna',
                'body' => 'Un riavvio totale potrebbe sistemare i sistemi. O peggiorarli.',
                'requires' => ['day' => ['op' => '>=', 'value' => 6]],
                'choices' => [
                    $this->gamble('Riavvio tutto', [['resource' => 'power', 'delta' => 10], ['resource' => 'oxygen', 'delta' => 5]], 'Tutto torna in linea.', [['resource' => 'power', 'delta' => -12]], 'Qualcosa non riparte.', 6, 4, 'azzardo calcolato'),
                    $this->one('Meglio non rischiare', [['resource' => 'morale', 'delta' => -2]], 'Si tira avanti così.'),
                ],
            ]),
        ];
    }

    // ---- Character / trait-triggered ----------------------------------------
    private function characterEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_anna_idea', 'title' => 'Anna ha un\'idea', 'speaker' => 'Anna',
                'body' => 'Dice di poter recuperare energia da un condotto morto.',
                'requires' => ['has_role' => 'engineer'],
                'choices' => [
                    $this->gamble('La lascio provare', [['resource' => 'power', 'delta' => 14]], 'Funziona. Ovvio.', [['resource' => 'power', 'delta' => -4], ['character' => 'random', 'stress' => 8]], 'Salta tutto.', 7, 3, 'dovrebbe reggere'),
                    $this->one('Troppo rischioso', [['resource' => 'morale', 'delta' => -3]], 'Ci resta male.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_coward_freeze', 'title' => 'Qualcuno si blocca', 'speaker' => 'Cole',
                'body' => 'Davanti al portello, le gambe non rispondono.',
                'requires' => ['trait_present' => 'coward'],
                'choices' => [
                    $this->one('Lo copro io', [['character' => 'highest_stress', 'stress' => -10], ['resource' => 'oxygen', 'delta' => -3]], 'Respira. Grazie a te.'),
                    $this->one('Deve cavarsela', [['character' => 'highest_stress', 'stress' => 12]], 'Ce la fa, tremando.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_doctor_call', 'title' => 'Visita medica', 'speaker' => 'Bex',
                'body' => 'Bex vuole controllare tutti. Costa tempo ed energia.',
                'requires' => ['has_role' => 'doctor'],
                'choices' => [
                    $this->one('Sì, controllo generale', [['resource' => 'power', 'delta' => -4], ['character' => 'all', 'stress' => -8]], 'Tutti un po\' più saldi.'),
                    $this->one('Non c\'è tempo', [['character' => 'random', 'stress' => 6]], 'Un malanno cova.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_breakdown_recruit', 'title' => 'Una voce dalla radio', 'speaker' => null,
                'body' => 'Un altro sopravvissuto, due ponti più giù. Vivo.',
                'requires' => ['day' => ['op' => '>=', 'value' => 7]],
                'choices' => [
                    $this->one('Vado a prenderlo', [['recruit' => ['role' => 'survivor']], ['resource' => 'oxygen', 'delta' => -5], ['resource' => 'food', 'delta' => -5]], 'Una bocca in più, due mani in più.'),
                    $this->one('Non posso rischiare', [['resource' => 'morale', 'delta' => -10], ['set_flag' => 'left_someone', 'value' => true]], 'La radio tace. Non te lo perdoni.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_left_someone_ghost', 'title' => 'La radio muta', 'speaker' => 'Bex',
                'body' => 'Quel canale resta vuoto. Lo controlli ancora, ogni tanto.',
                'requires' => ['flag' => 'left_someone', 'is' => true],
                'cooldown_days' => 6,
                'choices' => [
                    $this->one('Spengo la radio', [['resource' => 'morale', 'delta' => -5]], 'Più silenzio. Più peso.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_stress_fight', 'title' => 'Scoppia una lite', 'speaker' => 'Cole',
                'body' => 'Due dei tuoi sono uno contro l\'altro.',
                'requires' => ['day' => ['op' => '>=', 'value' => 4]],
                'choices' => [
                    $this->one('Li separo', [['character' => 'all', 'stress' => -4], ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => 5]]], 'Tregua fredda.'),
                    $this->one('Che se la sbrighino', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -15]], ['character' => 'random', 'stress' => 10]], 'Resta del rancore.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_sick_survivor', 'title' => 'Uno sta male', 'speaker' => 'Bex',
                'body' => 'Febbre alta. Senza cure peggiora.',
                'requires' => ['day' => ['op' => '>=', 'value' => 5]],
                'choices' => [
                    $this->one('Lo curo col kit', [['character' => 'highest_stress', 'stress' => -15]], 'Si riprende.', requires: ['has_item' => 'medkit']),
                    $this->gamble('Riposo e speranza', [['character' => 'highest_stress', 'stress' => -5]], 'Passa da sé.', [['kill' => 'highest_stress']], 'Non passa la notte.', 6, 4, 'non promette bene'),
                ],
            ]),
        ];
    }

    // ---- Relationship-triggered ---------------------------------------------
    private function relationshipEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_hatred_sabotage', 'title' => 'Sabotaggio', 'speaker' => 'Cole',
                'body' => 'Qualcuno ha manomesso il lavoro di un altro. Di proposito.',
                'requires' => ['relationship' => ['state' => 'hatred']],
                'choices' => [
                    $this->one('Faccio chiarezza', [['character' => 'all', 'stress' => 5], ['resource' => 'morale', 'delta' => -4]], 'Verità amara, ma detta.'),
                    $this->one('Insabbio tutto', [['damage_system' => 'power_grid', 'amount' => 10], ['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -10]]], 'Il marcio resta sotto.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_bond_morale', 'title' => 'Un momento buono', 'speaker' => 'Bex',
                'body' => 'Due dei tuoi si fidano davvero. Si vede.',
                'requires' => ['relationship' => ['state' => 'bond']],
                'choices' => [
                    $this->one('Sfrutto il buon clima', [['resource' => 'morale', 'delta' => 8], ['character' => 'all', 'stress' => -6]], 'L\'aria si fa più leggera.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_tension_choice', 'title' => 'Tensione ai ferri corti', 'speaker' => 'Cole',
                'body' => 'Si parlano a monosillabi. Sta per saltare.',
                'requires' => ['relationship' => ['state' => 'tension']],
                'choices' => [
                    $this->one('Medio io', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => 12]]], 'Un passo indietro per entrambi.'),
                    $this->one('Non è affar mio', [['relationship' => ['a' => 'Anna', 'b' => 'Cole', 'delta' => -8]], ['character' => 'all', 'stress' => 4]], 'Peggiora.'),
                ],
            ]),
        ];
    }

    // ---- Item-triggered (items open routes) ---------------------------------
    private function itemEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_drone_scout', 'title' => 'Ricognizione', 'speaker' => 'Anna',
                'body' => 'Un settore sigillato. Il drone può entrarci.',
                'requires' => ['has_item' => 'drone'],
                'choices' => [
                    $this->one('Mando il drone', [['resource' => 'food', 'delta' => 12]], 'Scorte dimenticate, recuperate.', 'dovrebbe reggere'),
                    $this->one('Troppo pericoloso anche per il drone', [['resource' => 'morale', 'delta' => -2]], 'Resta sigillato.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_comms_signal', 'title' => 'Statica', 'speaker' => 'Cole',
                'body' => 'La radio coglie qualcosa nel rumore.',
                'requires' => ['has_item' => 'comms'],
                'choices' => [
                    $this->one('Trasmetto un SOS', [['resource' => 'power', 'delta' => -6], ['resource' => 'morale', 'delta' => 6]], 'Forse qualcuno ascolta.'),
                    $this->one('Risparmio energia', [['resource' => 'morale', 'delta' => -3]], 'Il silenzio pesa.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_seedbank_plant', 'title' => 'Germogli', 'speaker' => 'Bex',
                'body' => 'La banca semi può diventare un orto. Con pazienza.',
                'requires' => ['has_item' => 'seedbank'],
                'choices' => [
                    $this->one('Pianto subito', [['resource' => 'food', 'delta' => 16], ['resource' => 'power', 'delta' => -5]], 'Verde fragile sotto le luci.', 'dovrebbe reggere'),
                    $this->one('Non è il momento', [], 'I semi aspettano.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_flare_signal', 'title' => 'Buio fuori', 'speaker' => 'Cole',
                'body' => 'Un razzo di segnalazione attirerebbe attenzione. Buona o cattiva.',
                'requires' => ['has_item' => 'flare'],
                'choices' => [
                    $this->gamble('Lo lancio', [['resource' => 'morale', 'delta' => 10]], 'Una luce di speranza.', [['spawn_event' => ['key' => 'c_real_threat', 'in_days' => 1]]], 'Hai attirato la cosa sbagliata.', 6, 4, 'incerto'),
                    $this->one('Lo tengo per dopo', [], 'Non ancora.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_logbank_read', 'title' => 'Vecchi archivi', 'speaker' => 'Anna',
                'body' => 'L\'archivio di bordo conserva chi c\'era prima di te.',
                'requires' => ['has_item' => 'logbank'],
                'choices' => [
                    $this->one('Leggo i log', [['resource' => 'morale', 'delta' => -3], ['set_flag' => 'knows_the_past', 'value' => true]], 'Sai com\'è finita, per loro.'),
                    $this->one('Meglio non sapere', [['resource' => 'morale', 'delta' => 2]], 'Chiudi l\'archivio.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_rifle_standoff', 'title' => 'Tensione armata', 'speaker' => 'Cole',
                'body' => 'Le cose potrebbero degenerare. Hai un fucile.',
                'requires' => ['all' => [['has_item' => 'rifle'], ['relationship' => ['state' => 'hatred']]]],
                'choices' => [
                    $this->one('Mostro il ferro', [['character' => 'all', 'stress' => -5], ['resource' => 'morale', 'delta' => -6]], 'Tutti zitti. Per paura.'),
                    $this->one('Lo tengo nascosto', [['character' => 'random', 'stress' => 8]], 'La tensione resta in aria.'),
                ],
            ]),
        ];
    }

    // ---- Cross-card & cross-run memory callbacks ----------------------------
    private function memoryEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_promise', 'title' => 'Una promessa', 'speaker' => 'Bex',
                'body' => 'Giuri che ne uscirete tutti vivi. Le parole pesano.',
                'requires' => ['day' => ['op' => '>=', 'value' => 3]],
                'cooldown_days' => 99,
                'choices' => [
                    $this->one('Lo prometto', [['resource' => 'morale', 'delta' => 10], ['set_flag' => 'made_promise', 'value' => true]], 'Gli occhi si accendono.'),
                    $this->one('Non prometto niente', [['resource' => 'morale', 'delta' => -4]], 'Almeno sei onesto.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_promise_broken', 'title' => 'La promessa', 'speaker' => 'Bex',
                'body' => 'Avevi giurato. Ora qualcuno non c\'è più.',
                'requires' => ['all' => [['flag' => 'made_promise', 'is' => true]]],
                'cooldown_days' => 99,
                'choices' => [
                    $this->one('Reggo il peso', [['character' => 'all', 'stress' => 6], ['resource' => 'morale', 'delta' => -8]], 'Le promesse non si dimenticano.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_veteran', 'title' => 'Déjà-vu', 'speaker' => 'Anna',
                'body' => 'Hai già vissuto tutto questo, in un\'altra stazione, in un\'altra vita.',
                'requires' => ['flag' => 'blew_a_reactor', 'scope' => 'profile', 'is' => true],
                'cooldown_days' => 99,
                'choices' => [
                    $this->one('Faccio tesoro dell\'esperienza', [['resource' => 'morale', 'delta' => 6], ['resource' => 'power', 'delta' => 4]], 'Stavolta sai dove mettere le mani.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_first_blood', 'title' => 'Il primo', 'speaker' => null,
                'body' => 'Il primo dei tuoi a non farcela. Non sarà l\'ultimo.',
                'requires' => ['day' => ['op' => '>=', 'value' => 6]],
                'cooldown_days' => 99,
                'choices' => [
                    $this->one('Onoro il ricordo', [['character' => 'all', 'stress' => -6], ['set_flag' => 'lost_one', 'scope' => 'profile', 'value' => true]], 'Un minuto di silenzio nel vuoto.'),
                    $this->one('Vado avanti, freddo', [['resource' => 'morale', 'delta' => -10]], 'Nessuno ti guarda allo stesso modo.'),
                ],
            ]),
        ];
    }

    // ---- Filler (always eligible, low stakes — keeps the loop flowing) ------
    private function fillerEvents(): array
    {
        return [
            $this->ev([
                'key' => 'c_filler_stars', 'title' => 'Oblò', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Fuori, solo stelle indifferenti.',
                'choices' => [
                    $this->one('Mi fermo a guardare', [['resource' => 'morale', 'delta' => 2]], 'Un istante di pace.'),
                    $this->one('Torno al lavoro', [['resource' => 'power', 'delta' => 1]], 'C\'è sempre qualcosa da fare.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_coffee', 'title' => 'Caffè sintetico', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Sa di plastica, ma è caldo.',
                'choices' => [
                    $this->one('Ne offro a tutti', [['character' => 'all', 'stress' => -3]], 'Piccolo lusso condiviso.'),
                    $this->one('Me lo tengo', [['resource' => 'morale', 'delta' => 1]], 'Un momento solo tuo.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_drill', 'title' => 'Esercitazione', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => 'Una prova d\'emergenza per non perdere la mano.',
                'choices' => [
                    $this->one('La facciamo', [['character' => 'all', 'stress' => 2], ['resource' => 'hull', 'delta' => 2]], 'Pronti, se servirà.'),
                    $this->one('Salto, c\'è altro', [], 'Magari domani.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_repair', 'title' => 'Manutenzione', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => 'Una giornata di piccole riparazioni.',
                'choices' => [
                    $this->one('Sistemo il sistemabile', [['resource' => 'power', 'delta' => 2]], 'Niente di che, ma utile.'),
                    $this->one('Riposo', [['character' => 'all', 'stress' => -2]], 'Le mani ferme per un giorno.'),
                ],
            ]),
        ];
    }
}
