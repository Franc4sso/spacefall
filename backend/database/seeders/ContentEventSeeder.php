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
            $this->dominoEvents(),
            $this->trapEvents(),
            $this->silentEvents(),
            $this->moralEvents(),
            $this->annaThread(),
            $this->bexThread(),
            $this->coleThread(),
            $this->crosstalkEvents(),
            $this->dilemmaEvents(),
            $this->hungerOpportunityEvents(),
            $this->hungerRationEvents(),
            $this->hungerCrisisEvents(),
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
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_withdrawn', 'is' => true]],
                ]],
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
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_broken', 'is' => true]],
                ]],
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

    // ---- Filo di Anna (ingegnere): competenza che non chiede permesso -------
    private function annaThread(): array
    {
        $done = ['set_flag' => 'anna_thread_done', 'value' => true];

        return [
            // 1. Lo fa comunque — ribaltamento: ha già iniziato.
            $this->ev([
                'key' => 'anna_does_it_anyway', 'title' => 'Anna non ha aspettato',
                'body' => "Trovi Anna a metà di una riparazione che non le hai autorizzato. «Stava cedendo. Non c'era tempo per chiederti il permesso.» Ormai è fatta.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['system' => 'power_grid', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                        ['system' => 'hull_integrity', 'field' => 'efficiency', 'op' => '<', 'value' => 55],
                    ]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['system' => 'power_grid', 'field' => 'efficiency', 'op' => '<', 'value' => 35], 'factor' => 3.0],
                ],
                'choices' => [
                    [
                        'label' => 'Coprila — è la migliore che abbiamo',
                        'hint' => 'incerto',
                        'tags' => ['cautious'],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'power', 'delta' => 12], ['modify_standing' => ['who' => 'Anna', 'delta' => 15]], $done], 'log' => 'Funziona. Ti guarda con gratitudine.'],
                            ['weight' => 4, 'effects' => [['damage_system' => 'power_grid', 'amount' => 10], ['character' => 'Anna', 'stress' => 10], $done], 'log' => 'Stavolta no. Ma ci ha provato per tutti.'],
                        ],
                    ],
                    [
                        'label' => 'Mettila a rapporto davanti a tutti',
                        'hint' => null,
                        'tags' => ['lone_decision'],
                        'outcomes' => [
                            ['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -25]], ['set_flag' => 'anna_overruled', 'value' => true], ['resource' => 'morale', 'delta' => -4], $done], 'log' => 'Anna incassa in silenzio. Qualcosa si raffredda.'],
                        ],
                    ],
                ],
            ]),

            // 2. Si spegne — stress altissimo + scavalcata/sfruttata. Imposta anna_withdrawn.
            $this->ev([
                'key' => 'anna_withdraws', 'title' => 'Anna si è fermata',
                'body' => "Anna è seduta a terra accanto a una paratia aperta, le mani ferme. «Faccio tutto io. Sbaglio io. Pago io. Ho finito.» Non è una minaccia. È stanchezza vera.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['flag' => 'anna_overruled', 'is' => true],
                        ['standing' => ['who' => 'Anna', 'op' => '<=', 'value' => -20]],
                    ]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala respirare. Te la cavi senza di lei.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'anna_withdrawn', 'value' => true], ['character' => 'Anna', 'stress' => -20], $done], 'log' => 'Anna si ritira nel suo silenzio. La prossima crisi tecnica è tua.']],
                    ],
                    [
                        'label' => 'Siediti accanto a lei. Ascolta.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => 30]], ['character' => 'Anna', 'stress' => -10], ['resource' => 'morale', 'delta' => -3], $done], 'log' => 'Non risolvi niente. Ma lei resta. A volte basta.']],
                    ],
                ],
            ]),

            // 3. La scommessa — scafo/energia critici + oggetto tecnico. Consuma l'oggetto.
            $this->ev([
                'key' => 'anna_gambit', 'title' => 'La scommessa di Anna',
                'body' => "Anna posa l'attrezzo sul tavolo come una carta da gioco. «Una possibilità. La sfrutto tutta o niente. Se va, siamo a posto per giorni. Se non va, l'ho bruciata.» Decidi tu.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['any' => [
                        ['has_item' => 'welder'], ['has_item' => 'toolkit'],
                        ['has_item' => 'fabricator'], ['has_item' => 'manual'],
                    ]],
                    ['any' => [
                        ['resource' => 'power', 'op' => '<', 'value' => 40],
                        ['resource' => 'hull', 'op' => '<', 'value' => 40],
                    ]],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala scommettere',
                        'hint' => 'rischioso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'power', 'delta' => 25], ['resource' => 'hull', 'delta' => 20], ['modify_standing' => ['who' => 'Anna', 'delta' => 20]], $done], 'log' => 'Il colpo riesce. Anna sorride per la prima volta da giorni.'],
                            ['weight' => 4, 'effects' => [['consume_item' => 'welder'], ['consume_item' => 'toolkit'], ['consume_item' => 'fabricator'], ['consume_item' => 'manual'], ['character' => 'Anna', 'stress' => 15], $done], 'log' => "L'attrezzo si fonde tra le sue mani. Niente. Ci aveva creduto."],
                        ],
                    ],
                    [
                        'label' => 'Troppo rischio. Si tiene l\'attrezzo.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -8]], ['resource' => 'morale', 'delta' => -2], $done], 'log' => 'Anna rimette via tutto. «Come vuoi.»']],
                    ],
                ],
            ]),

            // 4. Il salvataggio silenzioso — standing alto + situazione non disperata.
            $this->ev([
                'key' => 'anna_quiet_save', 'title' => 'Quello che Anna ha fatto',
                'body' => "Scopri solo dopo cosa ha fatto Anna: ha reinstradato l'energia da sola, di notte, per tenere caldo il settore dove dormiva chi era più sfinito. «Non dovevi saperlo. L'avrei fatto comunque.»",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['not' => ['flag' => 'anna_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Anna', 'op' => '>=', 'value' => 35]],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Ringraziala. Davvero.',
                        'hint' => null,
                        'tags' => ['generous'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 10], ['character' => 'all', 'stress' => -6], ['modify_standing' => ['who' => 'Anna', 'delta' => 10]], $done], 'log' => "L'equipaggio si stringe un po' di più. Funziona, per oggi."]],
                    ],
                ],
            ]),
        ];
    }

    // ---- Filo di Bex (medico): la coscienza che conta il prezzo ------------
    private function bexThread(): array
    {
        $done = ['set_flag' => 'bex_thread_done', 'value' => true];

        return [
            // 1. La verità scomoda — hai fatto scelte fredde o nascosto qualcosa.
            $this->ev([
                'key' => 'bex_confronts', 'title' => 'Bex non ci sta',
                'body' => "Bex ti ferma davanti a tutti. «So cosa hai scelto. Lo sappiamo tutti. Volevo solo che lo dicessi ad alta voce, almeno una volta.» Il corridoio è silenzioso.",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['any' => [
                        ['chosen_tag' => 'sacrifice_crew'],
                        ['chosen_tag' => 'il_freddo'],
                        ['flag' => 'log_falsified', 'is' => true],
                    ]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Prenditi la responsabilità, davanti a tutti',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['honest'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => 8], ['modify_trust' => 12], ['modify_standing' => ['who' => 'Bex', 'delta' => 25]], ['set_flag' => 'bex_confronted', 'value' => true], $done], 'log' => "Lo dici. Bex annuisce, lentamente. L'aria cambia."]],
                    ],
                    [
                        'label' => 'Sono scelte da comandante. Punto.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'morale', 'delta' => -12], ['modify_trust' => -15], ['modify_standing' => ['who' => 'Bex', 'delta' => -20]], ['set_flag' => 'bex_confronted', 'value' => true], $done], 'log' => 'Bex ti fissa un secondo di troppo, poi se ne va.']],
                    ],
                ],
            ]),

            // 2. Il crollo — stress altissimo + una morte. Imposta bex_broken.
            $this->ev([
                'key' => 'bex_breaks', 'title' => 'Bex ha le mani che tremano',
                'body' => "Bex fissa lo strumentario senza vederlo. «Continuo a rivedere chi non sono riuscita a salvare. Non posso operare così. Non oggi.» Non sta esagerando.",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['flag' => 'bex_saw_death', 'is' => true],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['resource' => 'morale', 'op' => '<', 'value' => 30], 'factor' => 2.5],
                ],
                'choices' => [
                    [
                        'label' => 'Sollevala dai turni. Ne ha bisogno.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'bex_broken', 'value' => true], ['character' => 'Bex', 'stress' => -25], $done], 'log' => 'Bex si ritira. Finché non torna, la medicina è un lusso che non hai.']],
                    ],
                    [
                        'label' => 'Abbiamo bisogno di te. Resisti.',
                        'hint' => 'rischioso',
                        'tags' => ['sacrifice_crew'],
                        'outcomes' => [
                            ['weight' => 5, 'effects' => [['character' => 'Bex', 'stress' => 15], ['modify_standing' => ['who' => 'Bex', 'delta' => -10]], $done], 'log' => 'Bex stringe i denti e continua. Ti costerà.'],
                            ['weight' => 5, 'effects' => [['character' => 'Bex', 'stress' => 25], ['resource' => 'morale', 'delta' => -8], $done], 'log' => 'Regge per un\'ora, poi cede del tutto. Era troppo.'],
                        ],
                    ],
                ],
            ]),

            // 3. La diagnosi — standing alto + medkit/scanner. Annulla uno spawn negativo.
            $this->ev([
                'key' => 'bex_catch', 'title' => 'Bex ha notato qualcosa',
                'body' => "Bex ti prende da parte. «Uno di noi sta covando qualcosa. Sintomi minimi, ma li riconosco. Se intervengo ora, con quello che abbiamo, lo fermo prima che diventi un problema per tutti.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Bex', 'op' => '>=', 'value' => 30]],
                    ['any' => [['has_item' => 'medkit'], ['has_item' => 'scanner']]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Fidati di lei. Intervieni ora.',
                        'hint' => 'dovrebbe reggere',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'all', 'stress' => -5], ['set_flag' => 'illness_caught', 'value' => true], ['modify_standing' => ['who' => 'Bex', 'delta' => 10]], $done], 'log' => 'Bex agisce in silenzio. Un disastro che non vedrai mai succedere.']],
                    ],
                    [
                        'label' => 'Non abbiamo risorse da sprecare su un sospetto',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['spawn_event' => ['key' => 'c_sick_survivor', 'in_days' => 3]], ['modify_standing' => ['who' => 'Bex', 'delta' => -12]], $done], 'log' => '«Spero di sbagliarmi», dice Bex. Non si sbaglia quasi mai.']],
                    ],
                ],
            ]),

            // 4. Il suo sacrificio — tardi + disperato + standing alto. Può morire.
            $this->ev([
                'key' => 'bex_sacrifice', 'title' => 'Bex non esita',
                'body' => "C'è da entrare nel settore contaminato per tirare fuori chi è rimasto bloccato. Bex si sta già infilando la maschera. «Sono il medico. È letteralmente il mio lavoro. Non discutere.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'],
                    ['not' => ['flag' => 'bex_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Bex', 'op' => '>=', 'value' => 40]],
                    ['day' => ['op' => '>=', 'value' => 14]],
                    ['resource' => 'oxygen', 'op' => '<', 'value' => 45],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lasciala andare. È la sua scelta.',
                        'hint' => 'molto pericoloso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'oxygen', 'delta' => 15], ['resource' => 'morale', 'delta' => 8], ['modify_standing' => ['who' => 'Bex', 'delta' => 15]], $done], 'log' => 'Bex torna, sfinita ma viva, trascinando chi era bloccato.'],
                            ['weight' => 4, 'effects' => [['kill' => 'Bex'], ['resource' => 'morale', 'delta' => -15], $done], 'log' => 'Bex non torna. Ha salvato qualcuno. Non se stessa.'],
                        ],
                    ],
                    [
                        'label' => 'No. Vai tu al posto suo.',
                        'hint' => 'rischioso',
                        'tags' => ['cautious'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 10], ['character' => 'all', 'stress' => 8], ['modify_standing' => ['who' => 'Bex', 'delta' => 20]], $done], 'log' => 'Esci tu. Bex ti aspetta al portello, e non te lo dimentica.']],
                    ],
                ],
            ]),
        ];
    }

    // ---- Filo di Cole (pilota): il sopravvissuto con un occhio sull'uscita --
    private function coleThread(): array
    {
        $done = ['set_flag' => 'cole_thread_done', 'value' => true];

        return [
            // 1. La via d'uscita — metà partita. Indaga (apre rotta) o ignora (lo risenti).
            $this->ev([
                'key' => 'cole_finds_exit', 'title' => 'Cole ha trovato qualcosa',
                'body' => "Cole ti mostra uno schema di volo. «C'è una finestra. Una rotta. Non sarà comoda, ma è una via fuori da questa scatola di latta. Vale la pena guardarci dentro?»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['day' => ['op' => '>=', 'value' => 8]],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Indaghiamo questa rotta',
                        'hint' => 'incerto',
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'cole_found_exit', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => 15]], ['resource' => 'power', 'delta' => -6]], 'log' => 'Cole si illumina. Per la prima volta sembra avere uno scopo.']],
                    ],
                    [
                        'label' => 'Concentriamoci sulla stazione, non sulla fuga',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'cole_resentful', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => -12]]], 'log' => 'Cole ripiega lo schema, lentamente. «Certo. Come vuoi tu.»']],
                    ],
                ],
            ]),

            // 2. La fuga — stress alto + risentito + oggetto di sopravvivenza.
            $this->ev([
                'key' => 'cole_defection', 'title' => 'Il posto di Cole è vuoto',
                'body' => "Cole non è alla sua postazione. Lo trovi al modulo di fuga, uno zaino già pronto. «Non aspetterò di morire qui mentre tu giochi a fare l'eroe. Mi prendo la mia possibilità.»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['flag' => 'cole_resentful', 'is' => true],
                    ['any' => [['has_item' => 'spacesuit'], ['has_item' => 'reactor_cell'], ['has_item' => 'rations']]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'weight_modifiers' => [
                    ['when' => ['standing' => ['who' => 'Cole', 'op' => '<=', 'value' => -25]], 'factor' => 2.0],
                ],
                'choices' => [
                    [
                        'label' => 'Fermalo. Abbiamo bisogno di tutti.',
                        'hint' => 'rischioso',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 5, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 20]], ['resource' => 'morale', 'delta' => -5], $done], 'log' => 'Lo convinci a restare. A fatica. Resta una crepa.'],
                            ['weight' => 5, 'effects' => [['character' => 'all', 'stress' => 12], ['modify_trust' => -15], $done], 'log' => 'Degenera in rissa. Tutti hanno visto. Niente sarà come prima.'],
                        ],
                    ],
                    [
                        'label' => 'Lascialo andare.',
                        'hint' => null,
                        'tags' => ['sacrifice_crew'],
                        'outcomes' => [['weight' => 1, 'effects' => [['kill' => 'Cole'], ['consume_item' => 'spacesuit'], ['resource' => 'morale', 'delta' => -10], ['set_flag' => 'cole_left', 'value' => true], $done], 'log' => 'Il modulo si stacca nel buio. Non saprai mai se ce l\'ha fatta.']],
                    ],
                ],
            ]),

            // 3. Il momento di coraggio — standing alto + finale disperato.
            $this->ev([
                'key' => 'cole_heroics', 'title' => 'Cole prende i comandi',
                'body' => "La stazione sta perdendo assetto. Cole è già al sedile di pilotaggio. «So che ho paura di tutto. Ma questo — questo lo so fare. Reggetevi a qualcosa.» Le sue mani, per una volta, non tremano.",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['standing' => ['who' => 'Cole', 'op' => '>=', 'value' => 40]],
                    ['day' => ['op' => '>=', 'value' => 14]],
                    ['resource' => 'hull', 'op' => '<', 'value' => 45],
                ]],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Affidati a lui',
                        'hint' => 'incerto',
                        'tags' => [],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 25], ['resource' => 'morale', 'delta' => 12], ['set_flag' => 'cole_heroics', 'value' => true], ['modify_standing' => ['who' => 'Cole', 'delta' => 15]], $done], 'log' => 'La manovra è folle e perfetta. Cole ride, incredulo di se stesso.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => -5], ['character' => 'Cole', 'stress' => 20], $done], 'log' => 'Quasi. Reggete tutti, a malapena. Cole è scosso ma vivo.'],
                        ],
                    ],
                ],
            ]),

            // 4. Il prezzo della paura — la sua paura ha causato una morte.
            $this->ev([
                'key' => 'cole_guilt', 'title' => 'Il peso di Cole',
                'body' => "Cole non si perdona. «Mi sono bloccato. Se mi fossi mosso un secondo prima, forse... » Non finisce la frase. Aspetta che tu dica qualcosa.",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'],
                    ['not' => ['flag' => 'cole_thread_done', 'is' => true]],
                    ['flag' => 'cole_caused_death', 'is' => true],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Non è stata colpa tua',
                        'hint' => null,
                        'tags' => ['generous'],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'Cole', 'stress' => -20], ['modify_standing' => ['who' => 'Cole', 'delta' => 20]], $done], 'log' => 'Cole annuisce, gli occhi lucidi. Forse ci crederà, un giorno.']],
                    ],
                    [
                        'label' => 'Devi conviverci. Come tutti noi.',
                        'hint' => null,
                        'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'Cole', 'stress' => 10], ['resource' => 'morale', 'delta' => -3], $done], 'log' => 'Cole incassa. È una verità dura, e lo sa.']],
                    ],
                ],
            ]),
        ];
    }

    // ---- Intreccio: i personaggi reagiscono l'uno all'altro -----------------
    private function crosstalkEvents(): array
    {
        return [
            // Bex commenta Anna che ha agito da sola.
            $this->ev([
                'key' => 'cross_bex_on_anna', 'title' => 'Bex parla di Anna',
                'body' => "Bex ti raggiunge a bassa voce. «Anna fa di testa sua. Stavolta è andata bene. Ma un giorno una delle sue 'soluzioni' la ucciderà, e nessuno l'avrà fermata.»",
                'requires' => ['all' => [
                    ['has_role' => 'doctor'], ['has_role' => 'engineer'],
                    ['flag' => 'anna_overruled', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Parlerò con Anna',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Bex', 'delta' => 8]], ['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => 10]]], 'log' => 'Bex sembra sollevata che qualcuno la ascolti.']],
                    ],
                    [
                        'label' => 'Anna sa quello che fa',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Bex', 'delta' => -8]], ['relationship' => ['a' => 'Anna', 'b' => 'Bex', 'delta' => -10]]], 'log' => '«Certo. Lo sa sempre», dice Bex, e se ne va.']],
                    ],
                ],
            ]),

            // Cole reagisce se Bex ti ha contestato pubblicamente.
            $this->ev([
                'key' => 'cross_cole_on_bex', 'title' => 'Cole ci scherza su',
                'body' => "Cole abbassa la voce, mezzo sorriso nervoso. «Bex ti ha messo con le spalle al muro, eh? Almeno qualcuno qui dice le cose come stanno. Anche se non serve a niente.»",
                'requires' => ['all' => [
                    ['has_role' => 'pilot'], ['has_role' => 'doctor'],
                    ['flag' => 'bex_confronted', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Anche tu hai qualcosa da dirmi?',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 5]], ['character' => 'Cole', 'stress' => -5]], 'log' => '«No, no. Io guido e basta», alza le mani Cole.']],
                    ],
                    [
                        'label' => 'Torna alla tua postazione',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => -8]], ['set_flag' => 'cole_resentful', 'value' => true]], 'log' => 'Cole si chiude. Hai chiuso una porta che era socchiusa.']],
                    ],
                ],
            ]),

            // Anna giudica come gestisci Cole.
            $this->ev([
                'key' => 'cross_anna_on_cole', 'title' => 'Anna dice la sua su Cole',
                'body' => "Anna ti ferma vicino ai motori. «Cole è spaventato, non stupido. Lo stai trattando come un peso morto. Continua così e quando ti servirà davvero, non ci sarà.»",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'], ['has_role' => 'pilot'],
                    ['flag' => 'cole_resentful', 'is' => true],
                ]],
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Hai ragione. Cambierò.',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Cole', 'delta' => 12]], ['modify_standing' => ['who' => 'Anna', 'delta' => 6]]], 'log' => 'Anna annuisce. «Bene. Allora forse ce la caviamo.»']],
                    ],
                    [
                        'label' => 'Ognuno porti il suo peso',
                        'hint' => null, 'tags' => ['il_freddo'],
                        'outcomes' => [['weight' => 1, 'effects' => [['modify_standing' => ['who' => 'Anna', 'delta' => -10]], ['resource' => 'morale', 'delta' => -4]], 'log' => '«Come vuoi. Ma te l\'ho detto», dice Anna.']],
                    ],
                ],
            ]),
        ];
    }

    // ---- Bivii ardui: due opzioni legittime, costi in valute diverse --------
    private function dilemmaEvents(): array
    {
        return [
            // L'ultima cella d'ossigeno — Anna intrappolata vs ferito curato da Bex.
            $this->ev([
                'key' => 'dilemma_oxygen_cell', 'title' => "L'ultima cella d'ossigeno",
                'body' => "Due settori perdono aria. Anna si è sigillata in uno per ripararlo. Bex tiene in vita un ferito nell'altro. Puoi pressurizzarne uno solo. L'altro lo perdi.",
                'requires' => ['all' => [
                    ['has_role' => 'engineer'],
                    ['resource' => 'oxygen', 'op' => '<', 'value' => 60],
                    ['day' => ['op' => '>=', 'value' => 6]],
                ]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Salva il settore di Anna',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 10], ['modify_standing' => ['who' => 'Anna', 'delta' => 15]], ['modify_standing' => ['who' => 'Bex', 'delta' => -25]], ['resource' => 'morale', 'delta' => -12]], 'log' => 'Anna è salva. Il ferito no. Bex non ti guarda più.',
                            'reactions' => [['who' => 'Bex', 'tone' => 'anger', 'line' => 'Era vivo. Potevo salvarlo.']]]],
                    ],
                    [
                        'label' => 'Salva il ferito di Bex',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'oxygen', 'delta' => 5], ['modify_standing' => ['who' => 'Bex', 'delta' => 15]], ['modify_standing' => ['who' => 'Anna', 'delta' => -20]], ['character' => 'Anna', 'stress' => 30], ['damage_system' => 'life_support', 'amount' => 15]], 'log' => 'Tiri fuori Anna all\'ultimo, sotto shock. Il settore è perso.',
                            'reactions' => [['who' => 'Anna', 'tone' => 'complicated', 'line' => 'Hai scelto. Lo capisco. Credo.']]]],
                    ],
                ],
            ]),

            // Il razionamento — equo vs efficiente.
            $this->ev([
                'key' => 'dilemma_rationing', 'title' => 'Come tagli le razioni',
                'body' => "Il cibo non basta per tutti alla razione piena. Tagli uguale per tutti, e tutti si indeboliscono. O togli ai più fragili per tenere in forza chi lavora. Non c'è una scelta pulita.",
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 35],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Taglio uguale per tutti',
                        'hint' => null, 'tags' => [],
                        'outcomes' => [['weight' => 1, 'effects' => [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => 3], ['modify_trust' => 5]], 'log' => 'Nessuno è contento. Ma nessuno è stato abbandonato.']],
                    ],
                    [
                        'label' => 'Tolgo ai più deboli',
                        'hint' => null, 'tags' => ['sacrifice_crew'],
                        'outcomes' => [['weight' => 1, 'effects' => [['resource' => 'food', 'delta' => 8], ['character' => 'highest_stress', 'stress' => 20], ['modify_trust' => -15]], 'log' => 'La stazione continua a funzionare. Qualcuno ti guarda diverso.']],
                    ],
                ],
            ]),

            // La trasmissione — SOS (rischio) vs dati di ricerca (significato).
            $this->ev([
                'key' => 'dilemma_transmission', 'title' => 'Un solo messaggio',
                'body' => "L'antenna ha energia per una trasmissione sola, poi tace. Un SOS — forse qualcuno viene, forse riveli la tua posizione a ciò che è là fuori. O i dati di ricerca, che danno un senso a tutto questo, ma non porteranno nessun soccorso.",
                'requires' => ['all' => [
                    ['has_item' => 'comms'],
                    ['day' => ['op' => '>=', 'value' => 10]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Lancia l\'SOS',
                        'hint' => 'incerto', 'tags' => [],
                        'outcomes' => [
                            ['weight' => 6, 'effects' => [['resource' => 'morale', 'delta' => 15], ['set_flag' => 'sos_sent', 'value' => true]], 'log' => 'Il segnale parte nel buio. Ora si aspetta.'],
                            ['weight' => 4, 'effects' => [['resource' => 'morale', 'delta' => 8], ['spawn_event' => ['key' => 'c_real_threat', 'in_days' => 2]]], 'log' => 'Il segnale parte. Forse non eri l\'unico ad ascoltare.'],
                        ],
                    ],
                    [
                        'label' => 'Trasmetti i dati di ricerca',
                        'hint' => null, 'tags' => ['lone_decision'],
                        'outcomes' => [['weight' => 1, 'effects' => [['set_flag' => 'research_complete', 'value' => true], ['resource' => 'morale', 'delta' => -8], ['character' => 'all', 'stress' => 6]], 'log' => 'I dati volano via. Qualcuno, un giorno, saprà. Voi resterete soli.']],
                    ],
                ],
            ]),

            // Chi tiene il pannello — vai tu (rischi il comando) o mandi un altro.
            $this->ev([
                'key' => 'dilemma_panel', 'title' => 'Chi tiene il pannello',
                'body' => "Una breccia. Qualcuno deve tenere un pannello dall'esterno mentre lo scafo vibra. Vai tu — e se non torni, chi guida? O mandi qualcuno dell'equipaggio, e tutti ti vedono scegliere chi rischia al posto tuo.",
                'requires' => ['all' => [
                    ['resource' => 'hull', 'op' => '<', 'value' => 40],
                    ['day' => ['op' => '>=', 'value' => 8]],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    [
                        'label' => 'Vai tu',
                        'hint' => 'rischioso', 'tags' => ['cautious'],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 25], ['modify_trust' => 20], ['character' => 'all', 'stress' => -5]], 'log' => 'Torni dentro intirizzito. L\'equipaggio ti guarda diversamente — meglio.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => 20], ['resource' => 'oxygen', 'delta' => -12], ['character' => 'all', 'stress' => 8]], 'log' => 'Tieni il pannello, ma quasi non rientri. È costato caro.'],
                        ],
                    ],
                    [
                        'label' => 'Mandi qualcuno',
                        'hint' => null, 'tags' => ['sacrifice_crew'],
                        'outcomes' => [
                            ['weight' => 7, 'effects' => [['resource' => 'hull', 'delta' => 22], ['character' => 'random', 'stress' => 20], ['modify_trust' => -10]], 'log' => 'Regge. Chi è uscito rientra tremando, e non ti ringrazia.'],
                            ['weight' => 3, 'effects' => [['resource' => 'hull', 'delta' => 18], ['kill' => 'random'], ['set_flag' => 'cole_caused_death', 'value' => true], ['set_flag' => 'bex_saw_death', 'value' => true]], 'log' => 'Lo scafo regge. La persona che hai mandato no.'],
                        ],
                    ],
                ],
            ]),
        ];
    }

    // ---- Fame: opportunità (fonti di cibo guidate dagli oggetti) -----------
    private function hungerOpportunityEvents(): array
    {
        return [
            // Orto: fonte rinnovabile, cooldown lungo.
            $this->ev([
                'key' => 'food_harvest', 'title' => 'Il raccolto è pronto', 'speaker' => 'Bex',
                'body' => "I germogli dell'orto hanno dato frutto. C'è di che riempire qualche stomaco, se raccogli ora.",
                'requires' => ['all' => [['has_item' => 'seedbank'], ['resource' => 'food', 'op' => '<', 'value' => 60], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 9, 'cooldown_days' => 6,
                'choices' => [
                    $this->one('Raccolgo tutto', [['resource' => 'food', 'delta' => 26], ['character' => 'all', 'hunger' => -10]], 'Mani sporche di terra, dispensa più piena.'),
                    $this->one('Lascio crescere ancora un po\'', [['resource' => 'morale', 'delta' => -2]], 'Pazienza. Forse domani rende di più.'),
                ],
            ]),

            // Caccia col fucile: +cibo ma rischio.
            $this->ev([
                'key' => 'food_hunt', 'title' => 'Qualcosa si muove nei condotti', 'speaker' => 'Cole',
                'body' => "Non sei solo su questa stazione. Qualcosa scricchiola nei condotti — e la carne è carne. Col fucile potresti procurartene.",
                'requires' => ['all' => [['has_item' => 'rifle'], ['resource' => 'food', 'op' => '<', 'value' => 50], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 8, 'cooldown_days' => 4,
                'choices' => [
                    $this->gamble('Vado a caccia', [['resource' => 'food', 'delta' => 20], ['character' => 'all', 'hunger' => -8]], 'Preda abbattuta. Si mangia.', [['character' => 'random', 'stress' => 12], ['resource' => 'food', 'delta' => 4]], 'Ti è quasi saltato addosso. Poca roba, tanto spavento.', 6, 4, 'rischioso'),
                    $this->one('Troppo pericoloso', [], 'Meglio la fame del morso.'),
                ],
            ]),

            // Drone: +cibo ma rischio di perderlo.
            $this->ev([
                'key' => 'food_drone_scavenge', 'title' => 'Settore dispensa sigillato', 'speaker' => 'Anna',
                'body' => "C'è un magazzino viveri oltre la paratia crollata. Il drone può entrarci — se non resta incastrato là dentro.",
                'requires' => ['all' => [['has_item' => 'drone'], ['resource' => 'food', 'op' => '<', 'value' => 50], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 8, 'cooldown_days' => 5,
                'choices' => [
                    $this->gamble('Mando il drone', [['resource' => 'food', 'delta' => 22], ['character' => 'all', 'hunger' => -6]], 'Torna carico. Scorte recuperate.', [['consume_item' => 'drone'], ['resource' => 'food', 'delta' => 6]], 'Il drone non torna. Solo qualche scatoletta nel vano di carico.', 6, 4, 'incerto'),
                    $this->one('Non rischio il drone', [], 'Resta sigillato. Per ora.'),
                ],
            ]),

            // Razioni d'emergenza: grosso colpo una tantum, consuma l'oggetto.
            $this->ev([
                'key' => 'food_emergency_rations', 'title' => 'Le scorte d\'emergenza', 'speaker' => null,
                'body' => "Hai tenuto da parte le razioni sigillate per il giorno peggiore. Forse è oggi. Una volta aperte, sono finite.",
                'requires' => ['all' => [['has_item' => 'rations'], ['resource' => 'food', 'op' => '<', 'value' => 30], ['crew_hunger' => ['op' => '>=', 'value' => 15]]]],
                'base_weight' => 12, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Apro le scorte', [['resource' => 'food', 'delta' => 40], ['character' => 'all', 'hunger' => -25], ['consume_item' => 'rations']], 'Stomaci pieni, ultimo cuscinetto bruciato.'),
                    $this->one('Non ancora', [['character' => 'all', 'stress' => 4]], 'Le tieni. Stringi i denti un altro po\'.'),
                ],
            ]),
        ];
    }

    // ---- Fame: razionamento e triage (chi mangia) --------------------------
    private function hungerRationEvents(): array
    {
        return [
            // Razionamento: come distribuisci quel che c'è.
            $this->ev([
                'key' => 'food_ration', 'title' => 'Il pasto', 'speaker' => 'Bex',
                'body' => "Il cibo cala e gli stomaci brontolano. Come lo distribuisci stasera?",
                // Fires whenever the crew is hungry, at any food level — you can
                // always choose to eat (spending the larder). When food runs
                // scarce the triage/sacrifice cards (gated on low food, higher
                // weight) take over for the hard choices.
                // Surfaced reliably by hunger spawn_bands (DayProcessor schedules
                // it when the crew crosses into the hunger band). Modest pool
                // weight + short cooldown; the scheduling carries the cadence.
                'requires' => ['crew_hunger' => ['op' => '>=', 'value' => 25]],
                'base_weight' => 8, 'cooldown_days' => 1,
                'choices' => [
                    $this->one('Razione piena per tutti', [['resource' => 'food', 'delta' => -16], ['character' => 'all', 'hunger' => -28], ['resource' => 'morale', 'delta' => 5]], 'Stomaci pieni, dispensa più leggera.'),
                    $this->one('Mezza razione', [['resource' => 'food', 'delta' => -7], ['character' => 'all', 'hunger' => -12], ['character' => 'all', 'stress' => 4]], 'Nessuno è sazio, ma il cibo dura.'),
                    array_merge(
                        $this->one('Si salta il pasto stasera', [['character' => 'all', 'stress' => 10], ['resource' => 'morale', 'delta' => -6]], 'Pance vuote. La dispensa resta intatta — per ora.'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),

            // Triage: non c'è per tutti — chi mangia.
            $this->ev([
                'key' => 'food_triage', 'title' => 'Non basta per tutti', 'speaker' => 'Bex',
                'body' => "Quel poco che resta sfama uno, non tutti. Bex aspetta che tu decida. Non ti invidia.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 22],
                    ['crew_hunger' => ['op' => '>=', 'value' => 45]],
                ]],
                'base_weight' => 10, 'cooldown_days' => 2,
                'choices' => [
                    array_merge(
                        $this->one('Sfama chi ci tiene in vita (l\'ingegnere)', [['resource' => 'food', 'delta' => -8], ['character' => 'Anna', 'hunger' => -45], ['modify_standing' => ['who' => 'Anna', 'delta' => 8]]], 'Anna mangia. Gli altri guardano. I sistemi reggeranno.', requires: ['has_role' => 'engineer']),
                        ['tags' => ['il_freddo']]
                    ),
                    $this->one('Sfama il più affamato', [['resource' => 'food', 'delta' => -8], ['character' => 'hungriest', 'hunger' => -45]], 'Dai da mangiare a chi sta peggio. È umano, se non efficiente.'),
                ],
            ]),
        ];
    }

    // ---- Fame: sacrificio e oscillazioni -----------------------------------
    private function hungerCrisisEvents(): array
    {
        return [
            // Il sacrificio — esito di fallimento, raro e pesante. Sopravvivibile
            // sull'altra scelta (stringere i denti ha un ramo che salva tutti).
            $this->ev([
                'key' => 'food_sacrifice', 'title' => 'Non c\'è abbastanza per tutti', 'speaker' => null,
                'body' => "La dispensa è vuota e qualcuno non vedrà l'alba a stomaco vuoto. C'è un pensiero che nessuno osa dire ad alta voce. Tocca a te dirlo, o rifiutarlo.",
                'requires' => ['all' => [
                    ['resource' => 'food', 'op' => '<', 'value' => 6],
                    ['crew_hunger' => ['op' => '>=', 'value' => 70]],
                ]],
                'base_weight' => 14, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Uno perché gli altri vivano', [['kill' => 'hungriest'], ['resource' => 'food', 'delta' => 30], ['character' => 'all', 'hunger' => -30], ['resource' => 'morale', 'delta' => -30], ['modify_trust' => -35], ['modify_standing' => ['who' => 'Anna', 'delta' => -25]], ['modify_standing' => ['who' => 'Bex', 'delta' => -25]], ['modify_standing' => ['who' => 'Cole', 'delta' => -25]], ['set_flag' => 'cannibalism', 'value' => true], ['set_flag' => 'bex_saw_death', 'value' => true]], 'È fatto. Nessuno ti guarderà più come prima. Nemmeno tu.'),
                        ['tags' => ['sacrifice_crew', 'il_freddo']]
                    ),
                    $this->gamble('Si stringe i denti, tutti insieme', [['character' => 'all', 'stress' => 12], ['resource' => 'morale', 'delta' => 6], ['modify_trust' => 10]], 'Vi tenete in piedi a vicenda. Stanotte non muore nessuno.', [['kill' => 'hungriest'], ['resource' => 'morale', 'delta' => -10]], 'Il più debole non passa la notte. Almeno non l\'hai scelto tu.', 6, 4, 'molto pericoloso'),
                ],
            ]),

            // Manna: una cache trovata.
            $this->ev([
                'key' => 'food_cache', 'title' => 'Una scorta dimenticata', 'speaker' => 'Cole',
                'body' => "Dietro un pannello allentato, casse di razioni che qualcuno aveva nascosto e dimenticato. Oggi la fortuna gira.",
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 45],
                'base_weight' => 4, 'cooldown_days' => 14,
                'choices' => [
                    $this->one('Le porto in dispensa', [['resource' => 'food', 'delta' => 28], ['resource' => 'morale', 'delta' => 6]], 'Un respiro di sollievo, raro.'),
                ],
            ]),

            // Mazzata: cibo contaminato (morde di più quando hai ancora scorte).
            $this->ev([
                'key' => 'food_spoilage', 'title' => 'Qualcosa è andato a male', 'speaker' => 'Bex',
                'body' => "Una guarnizione del frigo ha ceduto. Parte delle scorte è da buttare prima che faccia ammalare tutti.",
                'requires' => ['resource' => 'food', 'op' => '>', 'value' => 25],
                'base_weight' => 5, 'cooldown_days' => 8,
                'choices' => [
                    $this->one('Butto il marcio', [['resource' => 'food', 'delta' => -14]], 'Meno scorte, ma sane.'),
                    $this->gamble('Salviamo il salvabile', [['resource' => 'food', 'delta' => -6]], 'Recuperato quasi tutto.', [['resource' => 'food', 'delta' => -10], ['character' => 'random', 'stress' => 10]], 'Qualcuno ci rimette lo stomaco.', 5, 5, 'azzardo'),
                ],
            ]),
        ];
    }

    // ---- Domino chains (ignored choice → future crisis) ---------------------
    private function dominoEvents(): array
    {
        return [
            $this->ev([
                'key' => 'fuel_leak_warning', 'title' => 'Perdita di carburante',
                'body' => "Un sensore segnala una piccola perdita nel serbatoio principale. Niente di urgente, per ora.",
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ripara subito', [['resource' => 'power', 'delta' => -12]], 'La perdita è sigillata. Costi energetici elevati.'),
                    array_merge(
                        $this->one('Monitora e basta', [['spawn_event' => ['key' => 'fuel_crisis', 'in_days' => 6]]], 'Segnato nel registro. Probabilmente si stabilizzerà.'),
                        ['tags' => ['ignored_warning']]
                    ),
                ],
            ]),

            $this->ev([
                'key' => 'fuel_crisis', 'title' => 'CRISI PROPULSORI',
                'body' => "La piccola perdita che hai ignorato giorni fa non si è stabilizzata. Ora il sistema propulsivo sta cedendo. Non c'è via d'uscita pulita.",
                'requires' => ['chosen_tag' => 'ignored_warning'],
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Sacrifica energia per stabilizzare', [['resource' => 'power', 'delta' => -30], ['damage_system' => 'power_grid', 'amount' => 20]], 'I propulsori reggono. Energia quasi esaurita.'),
                    $this->one('Abbandona il settore propulsivo', [['damage_system' => 'hull_integrity', 'amount' => 25], ['resource' => 'hull', 'delta' => -20]], 'Settore abbandonato. Lo scafo ha subito danni strutturali.'),
                ],
            ]),

            $this->ev([
                'key' => 'doctor_exhausted', 'title' => 'Il medico è a pezzi',
                'body' => "Bex non dorme da tre giorni. Ti chiede un turno di riposo. Puoi permettertelo?",
                'requires' => ['has_role' => 'doctor'],
                'base_weight' => 7, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Concedile il riposo', [['character' => 'Bex', 'stress' => -25]], 'Bex si riposa. Ci vorrà un giorno.'),
                    array_merge(
                        $this->one('Non possiamo fermarci ora', [['character' => 'Bex', 'stress' => 20], ['spawn_event' => ['key' => 'patient_lost', 'in_days' => 4]]], 'Bex annuisce e torna al lavoro, silenziosa.'),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),

            $this->ev([
                'key' => 'patient_lost', 'title' => 'Troppo tardi',
                'body' => "Il paziente che Bex stava seguendo non ce l'ha fatta. Bex ti guarda. Non dice niente. Non deve.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Prendi la responsabilità', [['resource' => 'morale', 'delta' => -8], ['modify_trust' => 10]], "L'equipaggio apprezza la tua onestà. Il peso rimane."),
                    $this->one('Era inevitabile', [['resource' => 'morale', 'delta' => -20], ['modify_trust' => -15]], 'Bex si allontana. Qualcosa si è rotto.'),
                ],
            ]),

            $this->ev([
                'key' => 'ration_cut_decision', 'title' => 'Le razioni non bastano',
                'body' => "Il cibo sta finendo più in fretta del previsto. Devi decidere come gestire la distribuzione.",
                'base_weight' => 9, 'cooldown_days' => 999,
                'requires' => ['resource' => 'food', 'op' => '<', 'value' => 50],
                'choices' => [
                    $this->one('Taglio uguale per tutti', [['resource' => 'morale', 'delta' => -5]], 'Nessuno è contento. Almeno nessuno è trattato diversamente.'),
                    array_merge(
                        $this->one('Priorità a chi lavora di più', [['resource' => 'morale', 'delta' => -15], ['modify_trust' => -20], ['spawn_event' => ['key' => 'ration_revolt', 'in_days' => 5]]], "La decisione ha un senso logico. L'equipaggio non è d'accordo."),
                        ['tags' => ['sacrifice_crew']]
                    ),
                ],
            ]),

            $this->ev([
                'key' => 'ration_revolt', 'title' => 'La rivolta delle razioni',
                'body' => "Quello che hai fatto con le razioni ha bollito sotto la superficie. Ora è esploso. Due membri si rifiutano di lavorare finché il sistema non cambia.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Cedi e ridistribuisci', [['modify_trust' => 15], ['resource' => 'morale', 'delta' => 10]], 'La tensione cala. Hai ceduto, ma l\'equipaggio torna a respirare.'),
                    $this->one('Mantieni la linea', [['modify_trust' => -25], ['resource' => 'morale', 'delta' => -15], ['set_flag' => 'mutiny_occurred', 'value' => true]], 'Silenzio. Del tipo sbagliato.'),
                ],
            ]),

            $this->ev([
                'key' => 'mutiny_trigger', 'title' => 'AMMUTINAMENTO',
                'body' => "Hanno aspettato che dormissi. Quando ti svegli, i codici di accesso sono stati cambiati. L'equipaggio controlla la stazione. Tu no.",
                'base_weight' => 0, 'cooldown_days' => 999,
                'choices' => [
                    // Survivable path: negotiate your way back, no mutiny flag.
                    $this->one('Negozia', [['modify_trust' => 40], ['resource' => 'morale', 'delta' => -15]], 'Trovi un accordo duro. Il controllo è condiviso. La stazione respira ancora.'),
                    // Lose path: surrender leads to mutiny_end.
                    $this->one('Cedi il controllo', [['set_flag' => 'mutiny_occurred', 'value' => true], ['resource' => 'morale', 'delta' => 10]], 'Lasci andare. Forse è la cosa più saggia che hai fatto.'),
                ],
            ]),
        ];
    }

    // ---- Trap events (both options costly) ----------------------------------
    private function trapEvents(): array
    {
        return [
            $this->ev([
                'key' => 'trap_cascade_failure', 'title' => 'CASCATA DI GUASTI',
                'body' => "Hai ignorato troppi segnali. Ora sono tutti diventati reali, contemporaneamente. Non c'è una buona opzione.",
                'requires' => ['chosen_tag' => 'ignored_warning'],
                'base_weight' => 15, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Salva il sistema vita', [['damage_system' => 'power_grid', 'amount' => 40], ['damage_system' => 'hull_integrity', 'amount' => 20]], 'Il supporto vitale regge. Tutto il resto no.'),
                    $this->one('Salva la propulsione', [['damage_system' => 'life_support', 'amount' => 35], ['resource' => 'oxygen', 'delta' => -20]], "Potete muovervi. Ma l'aria si sta rarefacendo."),
                ],
            ]),

            $this->ev([
                'key' => 'trap_morale_collapse', 'title' => 'IL PUNTO DI ROTTURA',
                'body' => "L'equipaggio ha raggiunto il limite. Non è rabbia, è vuoto. Devi scegliere come usare le ultime riserve di fiducia che hai.",
                'requires' => ['resource' => 'morale', 'op' => '<', 'value' => 20],
                'base_weight' => 20, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Consuma le ultime riserve di cibo per un pasto vero', [['resource' => 'food', 'delta' => -30], ['resource' => 'morale', 'delta' => 25]], 'Un pasto. Un momento di umanità. Costerà.'),
                    $this->one('Discorso motivazionale — le parole costano poco', [['resource' => 'morale', 'delta' => 5], ['modify_trust' => -10]], 'Le parole cadono nel vuoto. Sanno che non credi nemmeno tu.'),
                ],
            ]),

            $this->ev([
                'key' => 'trap_hull_critical', 'title' => 'LO SCAFO STA CEDENDO',
                'body' => "Una breccia nel settore 7. Puoi tappare il buco, ma qualcuno deve tenere in posizione il pannello dall'esterno, in tuta EVA, mentre lo scafo vibra.",
                'requires' => ['resource' => 'hull', 'op' => '<', 'value' => 25],
                'base_weight' => 18, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Vai tu — tuta EVA nell\'inventario', [['resource' => 'hull', 'delta' => 30], ['character' => 'random', 'stress' => 15]], 'Esci. Fa freddo. La riparazione regge.', null, ['has_item' => 'spacesuit']),
                        ['tags' => ['cautious'], 'requires_item' => 'spacesuit']
                    ),
                    $this->one("Manda qualcuno dell'equipaggio", [['resource' => 'hull', 'delta' => 20], ['kill' => 'random']], 'Lo scafo regge. Qualcuno non torna.'),
                    $this->one('Sigilla il settore e abbandonalo', [['resource' => 'hull', 'delta' => -15], ['damage_system' => 'hull_integrity', 'amount' => 30]], 'Perdi il settore. Lo scafo perde stabilità strutturale.'),
                ],
            ]),
        ];
    }

    // ---- Silent cards (narrative-only, auto-advance) ------------------------
    private function silentEvents(): array
    {
        return [
            $this->ev([
                'key' => 'silent_window', 'title' => 'Una finestra nello spazio',
                'body' => "Anna è ferma davanti al pannello di osservazione da venti minuti. Non si gira quando entri. Le stelle non rispondono, ma almeno non mentono.",
                'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 8,
                'choices' => [],
            ]),

            $this->ev([
                'key' => 'silent_engine_hum', 'title' => 'Il ronzio dei motori',
                'body' => "Di notte la stazione ha un suono diverso. Non sai se è rassicurante o inquietante. Hai smesso di interrogarti su queste cose.",
                'is_filler' => true, 'base_weight' => 3, 'cooldown_days' => 10,
                'choices' => [],
            ]),
        ];
    }

    // ---- Moral dilemmas (conflicting values, no clean answer) ---------------
    private function moralEvents(): array
    {
        return [
            $this->ev([
                'key' => 'moral_last_dose', 'title' => "L'ultima dose",
                'body' => "Ci sono due feriti. Una dose di antidolorifico. Bex ti guarda. Non è una decisione medica — è una decisione umana.",
                'requires' => ['has_role' => 'doctor'],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('A chi ha più probabilità di sopravvivere', [['character' => 'random', 'stress' => 10]], 'Una scelta razionale. Difficile da guardare in faccia.'),
                        ['tags' => ['il_freddo']]
                    ),
                    array_merge(
                        $this->one('A chi soffre di più', [['resource' => 'morale', 'delta' => 8]], 'Non è efficiente. Ma è giusto.'),
                        ['tags' => ['generous']]
                    ),
                ],
            ]),

            $this->ev([
                'key' => 'moral_log_falsification', 'title' => 'Il registro dei danni',
                'body' => "Il rapporto ufficiale sui danni allo scafo deve essere inviato. La verità è molto peggio di quello che puoi ammettere. Puoi falsificare i dati.",
                'base_weight' => 5, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Invia i dati reali', [['resource' => 'morale', 'delta' => 5], ['modify_trust' => 10]], 'La verità è trasmessa. Qualcuno da qualche parte lo saprà.'),
                        ['tags' => ['honest']]
                    ),
                    array_merge(
                        $this->one('Minimizza i danni nel rapporto', [['set_flag' => 'log_falsified', 'value' => true]], 'Il messaggio parte. Nessuno fa domande. Per ora.'),
                        ['tags' => ['lone_decision']]
                    ),
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
