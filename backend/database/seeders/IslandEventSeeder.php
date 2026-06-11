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
            $this->survivorVoiceArcs(),
            $this->moralEvents(),
            $this->dominoEvents(),
            $this->silentEvents(),
            $this->fillerEvents(),
            $this->rescueChain(),
            $this->itemArcs(),
            $this->pairArcs(),
            $this->crossReactions(),
        );
    }

    // ---- Pair arcs: relationships between the three survivors ----------------
    // Mirrors space's pair_* events stage-for-stage. For each couple a CLASH
    // card (gated on both alive) where they fight under stress, and a BOND card
    // (gated on relationship state 'bond') where they've grown close. Conflicts
    // are written FROM their established voices (survivorVoiceArcs):
    //   Nadia  — brilliance as risk (her azzardi strain the group)
    //   Bruno  — hope as denial (downplays real danger)
    //   Carla  — fear, freezes in a crisis
    // Effects use relationship/modify_standing only; no flags, so the bond gate
    // is the relationship STATE, never a set_flag.
    private function pairArcs(): array
    {
        return [
            // === NADIA × BRUNO : il suo azzardo contro la sua prudenza ========
            $this->ev([
                'key' => 'pair_nadia_bruno_clash', 'title' => 'L\'azzardo e la cautela', 'speaker' => null,
                'body' => "Nadia vuole smontare la cassetta del pronto soccorso per recuperarne il metallo e l'alcol: «Mi serve per l'alambicco, e ne vale la pena.» Bruno le si pianta davanti. «Non tutto si può aggiustare con un azzardo, Nadia. Quella roba salva delle vite. La tua acqua può aspettare.» Si fissano. Aspettano te.",
                'requires' => ['all' => [['alive' => 'Nadia'], ['alive' => 'Bruno']]],
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Dai ragione a Nadia: rischiamo per l\'acqua', [['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'delta' => -12]], ['modify_standing' => ['who' => 'Nadia', 'delta' => 8]]], 'Bruno raccoglie quel che resta della cassetta senza una parola. Nadia ha già le mani nel metallo.'),
                    $this->one('Dai ragione a Bruno: la prudenza prima', [['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'delta' => -12]], ['modify_standing' => ['who' => 'Bruno', 'delta' => 8]]], 'Nadia richiude la cassetta di scatto. «Allora resteremo prudenti e assetati. Bravi.»'),
                    $this->one('Hanno ragione entrambi: trovate un compromesso', [['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'delta' => 8]], ['resource' => 'morale', 'delta' => -3]], 'Litigano ancora un po\', poi spartiscono: metà alla medicina, metà all\'alambicco. Nessuno è contento. Ma lavorano fianco a fianco.'),
                ],
            ]),
            $this->ev([
                'key' => 'pair_nadia_bruno_bond', 'title' => 'Due teste, una notte', 'speaker' => 'Bruno',
                'body' => "Li trovi svegli al fuoco, vicini. Nadia disegna circuiti nella cenere, Bruno tiene il ritmo con domande da profano che però la fanno ridere. «Mi ha spiegato come funziona il suo alambicco finché non ho capito,» ti dice Bruno. «E io le ho insegnato a suturare. Se uno di noi crolla, l'altro sa cosa fare.» Nadia annuisce, per una volta senza spigoli.",
                'requires' => ['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'state' => 'bond']],
                'base_weight' => 8, 'cooldown_days' => 10,
                'choices' => [
                    $this->one('Incoraggiali: insegnatevi tutto', [['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'delta' => 10]]], 'Passano la notte a scambiarsi i loro mestieri. Domani il gruppo ha due teste che pensano in due modi.'),
                    $this->one('Mettili alla prova: chi decide se siete in disaccordo?', [['relationship' => ['a' => 'Nadia', 'b' => 'Bruno', 'delta' => -6]], ['resource' => 'morale', 'delta' => 4]], 'La domanda li gela un istante. «Decide chi ha ragione,» dice Nadia. «Decide chi rischia di meno,» dice Bruno. Ridono, ma adesso lo sanno: un giorno dovranno scegliere.'),
                ],
            ]),

            // === NADIA × CARLA : la competenza contro la paura ================
            $this->ev([
                'key' => 'pair_nadia_carla_clash', 'title' => 'Chi si muove e chi si blocca', 'speaker' => null,
                'body' => "Una trave del riparo sta cedendo e Nadia è sotto a reggerla. «Carla, la corda, ADESSO!» Ma Carla è impietrita, le mani che tremano, lo sguardo perso. Nadia ce la fa da sola per un soffio, poi esplode: «Eri un pilota! Come fai a bloccarti così? Ogni volta tocca farlo a me!» Carla incassa, muta. Ti girano entrambe lo sguardo addosso.",
                'requires' => ['all' => [['alive' => 'Nadia'], ['alive' => 'Carla']]],
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Dai ragione a Nadia: Carla deve reagire', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -12]], ['modify_standing' => ['who' => 'Nadia', 'delta' => 8]]], 'Nadia annuisce, dura. Carla si allontana sola verso gli alberi, le spalle curve.'),
                    $this->one('Dai ragione a Carla: la paura non è una colpa', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -12]], ['modify_standing' => ['who' => 'Carla', 'delta' => 8]]], 'Carla ti cerca con gli occhi, grata. Nadia sbuffa e torna al lavoro: «Allora reggetela voi, la prossima trave.»'),
                    $this->one('Hanno ragione entrambe: il panico va capito, non punito', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => 8]], ['resource' => 'morale', 'delta' => -3]], 'Costringi Nadia a respirare e Carla a parlare. «Quando mi blocco, dammi un compito piccolo,» dice Carla piano. Nadia ci pensa. «...Va bene. La corda è un compito piccolo.»'),
                ],
            ]),
            $this->ev([
                'key' => 'pair_nadia_carla_bond', 'title' => 'La mano ferma', 'speaker' => 'Nadia',
                'body' => "Nadia ti mostra il faro di posizione, di nuovo acceso. «L'ho rimontato io, ma le mani che hanno tenuto fermi i contatti mentre saldavo erano le sue.» Indica Carla con il mento. «Non si è bloccata. Non una volta.» Carla quasi arrossisce. «Mi ha dato un compito alla volta. Così riesco.» Tra loro due, adesso, c'è un ritmo.",
                'requires' => ['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'state' => 'bond']],
                'base_weight' => 8, 'cooldown_days' => 10,
                'choices' => [
                    $this->one('Affidale insieme un lavoro difficile', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => 10]]], 'Nadia spezza ogni compito in passi piccoli, Carla li esegue uno a uno senza esitare. Funziona. Più di quanto entrambe sperassero.'),
                    $this->one('Metti Carla in una crisi senza Nadia accanto', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -6]], ['character' => 'Carla', 'stress' => 6]], 'Carla regge, a stento, sudando freddo. Nadia accorre dopo: «Perché l\'hai lasciata sola?» C\'è qualcosa di protettivo, ora, nel modo in cui difende Carla.'),
                ],
            ]),

            // === BRUNO × CARLA : la speranza contro il terrore ================
            $this->ev([
                'key' => 'pair_bruno_carla_clash', 'title' => 'Quando il sorriso non basta', 'speaker' => null,
                'body' => "Carla è seduta in disparte, le ginocchia strette al petto: è convinta che non vi salverà nessuno. Bruno le si avvicina col suo sorriso di sempre. «Su, andrà tutto bene! Domani il mare sarà calmo e—» «SMETTILA!» scatta Carla. «Non va tutto bene. Tu fai finta e basta. La mia paura non è uno scherzo da spazzare via con una battuta.» Bruno resta col sorriso a metà. Aspettano te.",
                'requires' => ['all' => [['alive' => 'Bruno'], ['alive' => 'Carla']]],
                'base_weight' => 9, 'cooldown_days' => 7,
                'choices' => [
                    $this->one('Dai ragione a Bruno: il morale ci tiene vivi', [['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'delta' => -10]], ['modify_standing' => ['who' => 'Bruno', 'delta' => 8]]], 'Bruno ritrova il sorriso. Carla si chiude ancora di più: si sente sola con la sua paura.'),
                    $this->one('Dai ragione a Carla: la sua paura è reale', [['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'delta' => -10]], ['modify_standing' => ['who' => 'Carla', 'delta' => 8]]], 'Bruno abbassa lo sguardo. «...Hai ragione. A volte sorrido per non guardare anch\'io.» Carla lo fissa, sorpresa da quella crepa.'),
                    $this->one('Hanno ragione entrambi: la speranza ascolta prima di consolare', [['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'delta' => 8]], ['resource' => 'morale', 'delta' => -3]], 'Bruno si siede accanto a Carla senza una battuta, e per una volta la lascia parlare. «Non ti dico che andrà bene,» dice piano. «Ti dico che ci sono.» Carla, lentamente, allenta le ginocchia.'),
                ],
            ]),
            $this->ev([
                'key' => 'pair_bruno_carla_bond', 'title' => 'Una paura ascoltata', 'speaker' => 'Bruno',
                'body' => "Bruno ti prende da parte. «Carla ha avuto un altro attacco di panico stanotte. Stavolta non ho fatto battute — sono rimasto seduto con lei finché non è passato. Mi ha detto di cosa ha davvero paura.» Guarda Carla, che dorme finalmente serena. «Non te lo dico, è suo. Ma adesso so come tirarla fuori, quando si blocca. E lei lo sa.»",
                'requires' => ['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'state' => 'bond']],
                'base_weight' => 8, 'cooldown_days' => 10,
                'choices' => [
                    $this->one('Rispetta il loro patto silenzioso', [['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'delta' => 10]]], 'Bruno è diventato l\'ancora di Carla, e Carla la coscienza di Bruno: lei gli ricorda di non mentire alla paura, lui le ricorda di respirare. Si reggono a vicenda.'),
                    $this->one('Chiedi a Bruno di dirti cosa la spaventa', [['relationship' => ['a' => 'Bruno', 'b' => 'Carla', 'delta' => -6]], ['resource' => 'morale', 'delta' => 4]], 'Bruno scuote la testa. «No. Se lo dico io, tradisco l\'unica cosa che si fida di me.» Tiene il segreto. Carla, quando lo scopre, lo guarda come non guarda nessun altro.'),
                ],
            ]),
        ];
    }

    // ---- Cross-reactions: jealousy when you keep favouring one survivor -----
    // Mirrors space's cross_* events: when you consistently FAVOUR one survivor
    // their standing climbs, and ANOTHER survivor remarks on it. The gate is the
    // SAME mechanism space uses for its standing-driven cross beats (see
    // ContentEventSeeder cross_*/standing gates at lines 1116/1207/1234/1332):
    //   ['standing' => ['who' => <favoured>, 'op' => '>=', 'value' => N]]
    // which ConditionEvaluator reads from state flag standing_<who> (set by the
    // modify_standing effect scattered through pairArcs/dilemmas). Both the
    // COMMENTER and the FAVOURED survivor must be alive. Choices ripple the
    // dynamic via relationship/modify_standing/morale only — no flags.
    //   cross_bruno_on_nadia : Bruno, hurt optimism, on a favoured Nadia
    //   cross_carla_on_bruno : Carla, timid resentment, on a favoured Bruno
    //   cross_nadia_on_carla : Nadia, blunt impatience, on a favoured Carla
    private function crossReactions(): array
    {
        return [
            // === BRUNO commenta una NADIA troppo coccolata ===================
            $this->ev([
                'key' => 'cross_bruno_on_nadia', 'title' => 'Bruno parla di Nadia', 'speaker' => 'Bruno',
                'body' => "Bruno ti raggiunge dove nessuno sente, e per una volta non sorride. «Lo so che Nadia è la più sveglia di noi. Glielo dici con gli occhi ogni volta. Ma c'è altra gente, qui. Io curo, Carla pilota, e cominciamo a sentirci come attrezzi che tieni nello zaino di scorta. Non è da te. O forse sì, e non me n'ero accorto.»",
                'requires' => ['all' => [
                    ['alive' => 'Bruno'], ['alive' => 'Nadia'],
                    ['standing' => ['who' => 'Nadia', 'op' => '>=', 'value' => 30]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Hai ragione, mi sono sbilanciato', [['modify_standing' => ['who' => 'Bruno', 'delta' => 8]], ['relationship' => ['a' => 'Bruno', 'b' => 'Nadia', 'delta' => -6]], ['resource' => 'morale', 'delta' => 3]], 'Bruno respira piano, come se gli avessi tolto un peso. «Grazie. Ne avevo bisogno.» Torna dai feriti più dritto di prima.'),
                    $this->one('Nadia ci tiene vivi: è meritato', [['modify_standing' => ['who' => 'Nadia', 'delta' => 6]], ['relationship' => ['a' => 'Bruno', 'b' => 'Nadia', 'delta' => -8]], ['resource' => 'morale', 'delta' => -3]], '«Certo. Ci tiene vivi.» Bruno annuisce e se ne va, e per la prima volta il suo ottimismo sembra una porta che si chiude.'),
                    $this->one('Hai ragione: ridistribuirò il peso', [['relationship' => ['a' => 'Bruno', 'b' => 'Nadia', 'delta' => 5]], ['modify_standing' => ['who' => 'Bruno', 'delta' => 4]], ['modify_standing' => ['who' => 'Nadia', 'delta' => -4]]], 'Da quel giorno chiedi anche a lui, davanti agli altri. Nadia alza un sopracciglio, ma Bruno cammina più leggero, e il gruppo respira.'),
                ],
            ]),

            // === CARLA commenta un BRUNO troppo ascoltato ====================
            $this->ev([
                'key' => 'cross_carla_on_bruno', 'title' => 'Carla parla di Bruno', 'speaker' => 'Carla',
                'body' => "Carla ti si avvicina di lato, le braccia strette al petto, la voce che le trema appena. «Non voglio fare scenate, davvero. Però… ascolti sempre Bruno. 'Andrà tutto bene, abbiate fede.' E io che dico di tenerci pronti al peggio sembro quella che porta sfortuna. Lo so che ho paura. Ma a volte ho anche ragione, e nessuno mi guarda.»",
                'requires' => ['all' => [
                    ['alive' => 'Carla'], ['alive' => 'Bruno'],
                    ['standing' => ['who' => 'Bruno', 'op' => '>=', 'value' => 30]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Ti ascolto: dimmi cosa vedi tu', [['modify_standing' => ['who' => 'Carla', 'delta' => 8]], ['relationship' => ['a' => 'Carla', 'b' => 'Bruno', 'delta' => -4]], ['resource' => 'morale', 'delta' => 2]], 'Carla scioglie le braccia, sorpresa. Ti spiega due rischi che nessuno aveva visto. Ha ragione su entrambi.'),
                    $this->one('Bruno ci tiene su il morale: serve', [['modify_standing' => ['who' => 'Bruno', 'delta' => 5]], ['relationship' => ['a' => 'Carla', 'b' => 'Bruno', 'delta' => -8]], ['resource' => 'morale', 'delta' => -2]], '«Già. Il morale.» Carla annuisce in fretta e si ritira nel suo angolo, dove la paura non disturba nessuno.'),
                    $this->one('Avete ragione tutti e due, insieme', [['relationship' => ['a' => 'Carla', 'b' => 'Bruno', 'delta' => 6]], ['modify_standing' => ['who' => 'Carla', 'delta' => 4]], ['modify_standing' => ['who' => 'Bruno', 'delta' => -3]]], 'Li metti a parlare: la speranza di Bruno e la prudenza di Carla, insieme, fanno un piano migliore. Carla, per una volta, alza la testa.'),
                ],
            ]),

            // === NADIA commenta una CARLA troppo protetta ====================
            $this->ev([
                'key' => 'cross_nadia_on_carla', 'title' => 'Nadia parla di Carla', 'speaker' => 'Nadia',
                'body' => "Nadia ti blocca con la chiave inglese ancora in mano, senza giri di parole. «La tratti con i guanti. Carla congela quando serve agire e tu la copri, la rassicuri, le togli i compiti pesanti. Io intanto lavoro doppio. Va bene proteggere chi ha paura. Ma se la paura non le costa mai niente, non smetterà mai di averne. E qualcuno di noi paga per questo. Io.»",
                'requires' => ['all' => [
                    ['alive' => 'Nadia'], ['alive' => 'Carla'],
                    ['standing' => ['who' => 'Carla', 'op' => '>=', 'value' => 30]],
                ]],
                'base_weight' => 6, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Hai ragione: smetterò di coprirla', [['modify_standing' => ['who' => 'Nadia', 'delta' => 8]], ['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -6]], ['character' => 'Carla', 'stress' => 6]], 'Nadia grugnisce, soddisfatta. Da domani Carla ha i suoi turni pesanti. Trema, ma li fa. Nadia smette di lavorare doppio.'),
                    $this->one('Carla ha bisogno di tempo: la proteggo', [['modify_standing' => ['who' => 'Carla', 'delta' => 4]], ['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => -8]], ['character' => 'Nadia', 'stress' => 6]], '«Tempo. Certo.» Nadia rimette via la chiave inglese di scatto e torna al doppio lavoro, e ogni colpo che batte è un rimprovero.'),
                    $this->one('Avete entrambe ragione: dividiamo il carico', [['relationship' => ['a' => 'Nadia', 'b' => 'Carla', 'delta' => 6]], ['modify_standing' => ['who' => 'Nadia', 'delta' => 3]], ['modify_standing' => ['who' => 'Carla', 'delta' => 3]]], 'Affianchi Carla a Nadia sui compiti duri: una insegna, l\'altra impara. Carla suda di paura, ma regge. E Nadia, per la prima volta, non lavora sola.'),
                ],
            ]),
        ];
    }

    // ---- Item arcs: 3-stage chains anchored to island items -----------------
    // Mirrors space's arc_seedbank pattern. Each stage gates on owning the item
    // + the prior stage's flag; choices set a flag and spawn the next stage.
    //   radio    → arc_radio_1/2/3   : alternate rescue path (answered/silent)
    //   seedbank → arc_garden_1/2/3  : plant a garden (arc_garden_bloomed)
    //   logbook  → arc_log_1/2/3     : the wreck's diary (arc_log_truth, hook)
    private function itemArcs(): array
    {
        return [
            // === RADIO: chiamata d'aiuto via etere ===========================
            $this->ev([
                'key' => 'arc_radio_1', 'title' => 'Una frequenza nel buio', 'speaker' => 'Nadia',
                'body' => "Nadia ha smontato e rimontato la radio da campo tre volte. \"Se la accordo bene, qualcuno là fuori può sentirci. Ma la batteria è quasi morta — ogni trasmissione la prosciuga.\" Vale la pena bruciarla per una chiamata vera?",
                'requires' => ['all' => [['has_item' => 'radio'], ['day' => ['op' => '>=', 'value' => 5]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Trasmetti un SOS completo', [['resource' => 'fire', 'delta' => -3], ['resource' => 'morale', 'delta' => 5], ['set_flag' => 'arc_radio_called', 'value' => true], ['spawn_event' => ['key' => 'arc_radio_2', 'in_days' => 3]]], 'La voce di Nadia parte verso il nulla. Coordinate, nomi, una preghiera.'),
                    $this->one('Solo un segnale breve, per risparmiare', [['resource' => 'morale', 'delta' => -2], ['set_flag' => 'arc_radio_called', 'value' => true], ['spawn_event' => ['key' => 'arc_radio_2', 'in_days' => 3]]], 'Tre impulsi secchi. Poco, ma è uscito qualcosa.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_radio_2', 'title' => 'La batteria muore', 'speaker' => 'Nadia',
                'body' => "L'indicatore di carica è rosso. \"Abbiamo una trasmissione, forse due, poi è ferraglia,\" dice Nadia. Carla suggerisce di cannibalizzare le torce per spremere ancora qualche volt. Costa, ma è l'ultima occasione di essere sentiti.",
                'requires' => ['flag' => 'arc_radio_called', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Sacrifica le torce per la radio', [['resource' => 'fire', 'delta' => -4], ['set_flag' => 'arc_radio_powered', 'value' => true], ['spawn_event' => ['key' => 'arc_radio_3', 'in_days' => 3]]], 'Nadia salda i contatti. La radio respira ancora un poco.'),
                    $this->one('Tieni le torce, rischia la radio', [['character' => 'Nadia', 'stress' => 5], ['set_flag' => 'arc_radio_powered', 'value' => false], ['spawn_event' => ['key' => 'arc_radio_3', 'in_days' => 3]]], 'La carica scende ancora. Una sola chiamata, se va bene.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_radio_3', 'title' => 'Statica, o una voce', 'speaker' => 'Nadia',
                'body' => "Nadia preme il pulsante un'ultima volta. Tutti trattengono il fiato attorno all'apparecchio.",
                'requires' => ['flag' => 'arc_radio_called', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Ascolta', [['resource' => 'morale', 'delta' => 15], ['set_flag' => 'arc_radio_answered', 'value' => true]], 'Dal rumore emerge una voce: vi hanno sentiti. Stanno cercando le coordinate. Per la prima volta, qualcuno là fuori sa che esistete.'),
                        ['requires' => ['flag' => 'arc_radio_powered', 'is' => true]]
                    ),
                    $this->one('Ascolta', [['resource' => 'morale', 'delta' => -8], ['set_flag' => 'arc_radio_silent', 'value' => true]], 'Solo statica. La carica si esaurisce a metà parola di Nadia. Il mare resta sordo.', null, ['not' => ['flag' => 'arc_radio_powered', 'is' => true]]),
                ],
            ]),

            // === SEEDBANK: l'orto (adattato dall'arc spaziale) ===============
            $this->ev([
                'key' => 'arc_garden_1', 'title' => 'Un fazzoletto di terra', 'speaker' => 'Bruno',
                'body' => "Bruno rigira il sacchetto di semi tra le mani, ostinatamente speranzoso. \"Potremmo coltivare davvero, non solo razionare. Costa acqua e fatica adesso — ma un giorno l'isola ci sfamerebbe.\" Oppure si tiene tutto in riserva.",
                'requires' => ['all' => [['has_item' => 'seedbank'], ['day' => ['op' => '>=', 'value' => 5]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Orto vero, adesso', [['resource' => 'water', 'delta' => -4], ['resource' => 'food', 'delta' => -2], ['set_flag' => 'arc_garden_stage1', 'value' => true], ['spawn_event' => ['key' => 'arc_garden_2', 'in_days' => 4]]], 'Bruno dissoda un tratto al riparo dal vento. Mani nella terra, finalmente.'),
                    $this->one('Solo qualche solco, per ora', [['resource' => 'morale', 'delta' => -3], ['set_flag' => 'arc_garden_stage1', 'value' => true], ['spawn_event' => ['key' => 'arc_garden_2', 'in_days' => 4]]], 'Verde fragile e simbolico. Meglio di niente, dice Bruno.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_garden_2', 'title' => 'L\'orto ha sete', 'speaker' => 'Bruno',
                'body' => "I germogli reggono, ma chiedono cure: acqua dolce, riparo dal sole, tempo che potreste spendere altrove. Bruno li annaffia col suo razionamento. Trascurarli adesso significa perderli.",
                'requires' => ['flag' => 'arc_garden_stage1', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Mi prendo cura dell\'orto', [['resource' => 'water', 'delta' => -3], ['set_flag' => 'arc_garden_stage2', 'value' => true], ['set_flag' => 'arc_garden_tended', 'value' => true], ['spawn_event' => ['key' => 'arc_garden_3', 'in_days' => 4]]], 'Foglie più larghe ogni mattina. Costa acqua, ma vive.'),
                    $this->one('Ho di meglio da fare', [['character' => 'Bruno', 'stress' => 7], ['set_flag' => 'arc_garden_stage2', 'value' => true], ['spawn_event' => ['key' => 'arc_garden_3', 'in_days' => 4]]], 'L\'orto aspetta sotto il sole. Bruno non te lo dice, ma ci rimane male.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_garden_3', 'title' => 'Il raccolto', 'speaker' => null,
                'body' => "Quello che avete seminato dà i suoi frutti — o quello che resta dell'orto sotto il sole dell'isola.",
                'requires' => ['flag' => 'arc_garden_stage2', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    array_merge(
                        $this->one('Raccogli', [['resource' => 'food', 'delta' => 30], ['resource' => 'morale', 'delta' => 10], ['set_flag' => 'arc_garden_bloomed', 'value' => true], ['set_flag' => 'tended_crops', 'value' => true]], 'L\'orto ha tenuto fede alla promessa di Bruno. Per una volta, abbondanza sull\'isola.'),
                        ['requires' => ['flag' => 'arc_garden_tended', 'is' => true]]
                    ),
                    $this->one('Raccogli quel che resta', [['resource' => 'food', 'delta' => 7], ['resource' => 'morale', 'delta' => -6]], 'Foglie secche, qualche frutto amaro. Non l\'avete curato abbastanza, e Bruno lo sa.', null, ['not' => ['flag' => 'arc_garden_tended', 'is' => true]]),
                ],
            ]),

            // === LOGBOOK: il diario del relitto (hook mistero, LOST-style) ====
            $this->ev([
                'key' => 'arc_log_1', 'title' => 'Le pagine del relitto', 'speaker' => 'Nadia',
                'body' => "Il diario recuperato tra i rottami non è dell'aereo. È più vecchio. Nadia lo sfoglia: nomi, date, una mappa rabbiosa. \"Qualcuno è naufragato qui prima di noi. E scriveva tutti i giorni — fino a un certo punto.\"",
                'requires' => ['all' => [['has_item' => 'logbook'], ['day' => ['op' => '>=', 'value' => 5]]]],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Leggiamo le prime pagine insieme', [['resource' => 'morale', 'delta' => -2], ['set_flag' => 'arc_log_read', 'value' => true], ['spawn_event' => ['key' => 'arc_log_2', 'in_days' => 4]]], 'Sopravvissuti come voi. All\'inizio organizzati, fiduciosi. Poi qualcosa cambia nella grafia.'),
                    $this->one('Cerco solo le parti utili (acqua, cibo)', [['resource' => 'food', 'delta' => 4], ['set_flag' => 'arc_log_read', 'value' => true], ['spawn_event' => ['key' => 'arc_log_2', 'in_days' => 4]]], 'Indicazioni preziose su sorgenti e frutti. Ma l\'occhio cade su righe che non volevi leggere.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_log_2', 'title' => 'Un dettaglio che non torna', 'speaker' => 'Bruno',
                'body' => "Una pagina conta i sopravvissuti: nove. La successiva, sei. Nessuna parola sui tre mancanti — solo un trattino accanto ai nomi. Bruno impallidisce. \"Non sono morti di fame. Lo avrebbero scritto.\" Carla vorrebbe smettere di leggere.",
                'requires' => ['flag' => 'arc_log_read', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Andiamo fino in fondo', [['character' => 'Carla', 'stress' => 6], ['set_flag' => 'arc_log_dug', 'value' => true], ['spawn_event' => ['key' => 'arc_log_3', 'in_days' => 4]]], 'Nadia continua a voce bassa. Le ultime pagine sono diverse. Più buie.'),
                    $this->one('Basta così, per stanotte', [['resource' => 'morale', 'delta' => -4], ['set_flag' => 'arc_log_dug', 'value' => true], ['spawn_event' => ['key' => 'arc_log_3', 'in_days' => 4]]], 'Chiudete il diario. Ma quei trattini restano negli occhi di tutti.'),
                ],
            ]),
            $this->ev([
                'key' => 'arc_log_3', 'title' => 'Una verità a metà', 'speaker' => 'Nadia',
                'body' => "L'ultima pagina leggibile: \"Non è la fame a ucciderci. È l'isola. C'è qualcosa qui che non—\" La frase si interrompe. Il resto è strappato. Nadia guarda gli altri. \"Forse il vento. Forse no. Ma loro lo sapevano, e non sono mai partiti.\"",
                'requires' => ['flag' => 'arc_log_dug', 'is' => true],
                'base_weight' => 10, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Conserviamo il diario. Ci serve sapere.', [['resource' => 'morale', 'delta' => -3], ['set_flag' => 'arc_log_truth', 'value' => true]], 'Una verità a metà pesa più di nessuna. Qualcosa, su quest\'isola, non è solo natura.'),
                    $this->one('Bruciamolo. Non ci serve questa paura.', [['resource' => 'morale', 'delta' => 4], ['set_flag' => 'arc_log_truth', 'value' => true]], 'Le fiamme prendono le pagine. Ma ciò che avete letto non brucia con loro.'),
                ],
            ]),
        ];
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

    // ---- Survivor voice arcs: Nadia / Bruno / Carla ------------------------
    // Three nominal 3-beat chains that DEFINE each survivor through their trait.
    // Beat 1 gated on alive + day>=4; beats 2-3 on the prior beat's flag and
    // chained via spawn_event. Terminal flags are read by the island epilogue
    // witness_flags (config/themes/island.php).
    private function survivorVoiceArcs(): array
    {
        return array_merge(
            $this->nadiaArc(),
            $this->brunoArc(),
            $this->carlaArc(),
            $this->survivorStressEvents(),
        );
    }

    // Nadia (genius): a brilliant, risky fix that strains the group before it
    // pays off — or overreaches.
    private function nadiaArc(): array
    {
        return [
            $this->ev([
                'key' => 'nadia_arc_1', 'title' => 'L\'idea di Nadia',
                'speaker' => 'Nadia',
                'body' => "Nadia ha smontato mezza fusoliera e ti mostra uno schema tracciato nella sabbia. «Posso ricavare un alambicco dai serbatoi dell'aereo. Acqua pulita, litri al giorno. Ma devo cannibalizzare il circuito che tiene acceso il faro di posizione. È un azzardo — e lo so.» I suoi occhi brillano come solo davanti a un problema difficile.",
                'requires' => ['all' => [
                    ['alive' => 'Nadia'],
                    ['day' => ['op' => '>=', 'value' => 4]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Falla. Ci fidiamo del tuo ingegno.', [['set_flag' => 'nadia_gambit', 'value' => true], ['modify_standing' => ['who' => 'Nadia', 'delta' => 12]], ['spawn_event' => ['key' => 'nadia_arc_2', 'in_days' => 3]]], 'Nadia è già al lavoro prima che tu finisca la frase. Pezzi ovunque.'),
                    $this->one('Troppo rischio. Lascia stare il faro.', [['modify_standing' => ['who' => 'Nadia', 'delta' => -8]], ['resource' => 'morale', 'delta' => -3]], 'Cancella lo schema con un piede. «Come vuoi. Resteremo assetati e prudenti.»'),
                ],
            ]),
            $this->ev([
                'key' => 'nadia_arc_2', 'title' => 'Il prezzo dell\'ingegno',
                'speaker' => 'Nadia',
                'body' => "L'alambicco gocciola acqua limpida, ma Nadia pretende sempre di più: legna per il fuoco di distillazione, mani che dovrebbero pescare o riparare il riparo. «Ancora un giorno e raddoppio la resa.» Bruno e Carla cominciano a guardarla storto: stanno pagando loro il conto della sua ossessione.",
                'requires' => ['all' => [
                    ['alive' => 'Nadia'],
                    ['flag' => 'nadia_gambit', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Dalle quello che chiede.', [['set_flag' => 'nadia_pushed', 'value' => true], ['resource' => 'fire', 'delta' => -6], ['character' => 'all', 'stress' => 6], ['spawn_event' => ['key' => 'nadia_arc_3', 'in_days' => 3]]], 'Il fuoco si abbassa, i nervi si tendono. Nadia non alza nemmeno lo sguardo.'),
                    $this->one('Falla rallentare. Il gruppo viene prima.', [['set_flag' => 'nadia_pushed', 'value' => true], ['resource' => 'morale', 'delta' => 5], ['modify_standing' => ['who' => 'Nadia', 'delta' => -6]], ['spawn_event' => ['key' => 'nadia_arc_3', 'in_days' => 3]]], 'Obbedisce, a labbra strette. «Vi sto salvando, e mi fermate.»'),
                ],
            ]),
            $this->ev([
                'key' => 'nadia_arc_3', 'title' => 'La resa dei conti',
                'speaker' => 'Nadia',
                'body' => "Nadia ti chiama al suo apparecchio. «È il momento. Riattacco il circuito del faro all'alambicco al massimo della pressione: o ci dà acqua per settimane, o salta tutto.» Tiene la mano sulla leva, ferma per una volta. «Decidi tu, capo.»",
                'requires' => ['all' => [
                    ['alive' => 'Nadia'],
                    ['flag' => 'nadia_pushed', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Tira la leva.', [['resource' => 'water', 'delta' => 22], ['resource' => 'morale', 'delta' => 10], ['set_flag' => 'nadia_vindicated', 'value' => true], ['modify_standing' => ['who' => 'Nadia', 'delta' => 18]]], 'L\'alambicco canta. Acqua a fiotti. Nadia, per una volta, sorride senza riserve.', [['resource' => 'water', 'delta' => -8], ['resource' => 'fire', 'delta' => -10], ['set_flag' => 'nadia_overreached', 'value' => true], ['character' => 'Nadia', 'stress' => 20]], 'Lo scoppio le brucia le sopracciglia. L\'apparecchio è rottami. «Avevo calcolato tutto», sussurra. Non aveva calcolato abbastanza.', 7, 3, 'incerto'),
                    $this->one('Smonta tutto. Non vale la pena.', [['resource' => 'water', 'delta' => -3], ['set_flag' => 'nadia_overreached', 'value' => true], ['modify_standing' => ['who' => 'Nadia', 'delta' => -10]]], 'Nadia smonta l\'alambicco in silenzio. «Allora era tutto inutile.» Qualcosa, in lei, si spegne.'),
                ],
            ]),
        ];
    }

    // Bruno (optimist): buoys morale, then downplays a real danger; his denial
    // costs — or his hope holds.
    private function brunoArc(): array
    {
        return [
            $this->ev([
                'key' => 'bruno_arc_1', 'title' => 'Il buonumore di Bruno',
                'speaker' => 'Bruno',
                'body' => "Il morale è a terra: pioggia, fame, silenzio. Bruno si alza, batte le mani e comincia a raccontare la storia più assurda della sua carriera da medico, gesticolando come un attore. «E il tizio ringrazia il chirurgo sbagliato!» Persino Carla ride. «Vedete? Siamo ancora vivi, e finché ridiamo non abbiamo perso.»",
                'requires' => ['all' => [
                    ['alive' => 'Bruno'],
                    ['day' => ['op' => '>=', 'value' => 4]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Lascialo fare. Ne abbiamo bisogno.', [['set_flag' => 'bruno_hope', 'value' => true], ['resource' => 'morale', 'delta' => 10], ['modify_standing' => ['who' => 'Bruno', 'delta' => 10]], ['spawn_event' => ['key' => 'bruno_arc_2', 'in_days' => 3]]], 'Per una sera l\'accampamento sembra di nuovo un gruppo di persone, non di naufraghi.'),
                    $this->one('Non è il momento di scherzare.', [['resource' => 'morale', 'delta' => -5], ['modify_standing' => ['who' => 'Bruno', 'delta' => -8]]], 'Bruno si siede, il sorriso che gli muore in faccia. «Hai ragione tu. Scusate.» Il silenzio torna, più pesante.'),
                ],
            ]),
            $this->ev([
                'key' => 'bruno_arc_2', 'title' => 'Bruno minimizza',
                'speaker' => 'Bruno',
                'body' => "Carla ha una ferita al polpaccio che si è gonfiata e annerita ai bordi. Bruno la sbircia e sorride. «Roba da nulla, un graffio infetto. Un impacco e domani corre.» Ma tu hai visto la striscia rossa salirle su per la gamba. Lui lo sa — e sceglie di non vederlo, per non spaventare nessuno.",
                'requires' => ['all' => [
                    ['alive' => 'Bruno'],
                    ['flag' => 'bruno_hope', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Fidati di lui. È il medico.', [['set_flag' => 'bruno_denial', 'value' => true], ['resource' => 'morale', 'delta' => 4], ['spawn_event' => ['key' => 'bruno_arc_3', 'in_days' => 3]]], 'Bruno applica l\'impacco con un sorriso. Tutti scelgono di credergli.'),
                    $this->one('Costringilo a guardare la verità.', [['set_flag' => 'bruno_denial', 'value' => true], ['resource' => 'fire', 'delta' => -4], ['character' => 'Bruno', 'stress' => 8], ['spawn_event' => ['key' => 'bruno_arc_3', 'in_days' => 3]]], 'Gli tieni la mano sulla ferita finché non smette di sorridere. «...Va bene. Va bene, è grave. Brucio i ferri.»'),
                ],
            ]),
            $this->ev([
                'key' => 'bruno_arc_3', 'title' => 'Quando la speranza si paga',
                'speaker' => 'Bruno',
                'body' => "La notte la febbre di Carla esplode. Bruno è chino su di lei, gli occhi sbarrati, la maschera del buonumore finalmente caduta. «Ho aspettato troppo perché volevo che andasse tutto bene. Adesso devo tagliare, e non ho granché. Reggetela. Reggetela forte.»",
                'requires' => ['all' => [
                    ['alive' => 'Bruno'],
                    ['flag' => 'bruno_denial', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Affidati a lui. Stavolta sul serio.', [['resource' => 'morale', 'delta' => 12], ['set_flag' => 'bruno_hope_held', 'value' => true], ['modify_standing' => ['who' => 'Bruno', 'delta' => 16]]], 'All\'alba la febbre cala. Bruno crolla esausto, ridendo e piangendo insieme. «Ve l\'avevo detto. Ce l\'abbiamo fatta.»', [['resource' => 'morale', 'delta' => -14], ['set_flag' => 'bruno_denial_cost', 'value' => true], ['character' => 'all', 'stress' => 12], ['modify_standing' => ['who' => 'Bruno', 'delta' => -10]]], 'Le sue mani arrivano tardi. Carla regge fino all\'alba, poi no. Bruno fissa il mare e non sorride per giorni.', 6, 4, 'incerto'),
                    $this->one('Prendi tu in mano la situazione.', [['resource' => 'water', 'delta' => -6], ['resource' => 'fire', 'delta' => -6], ['set_flag' => 'bruno_hope_held', 'value' => true], ['character' => 'Bruno', 'stress' => 10]], 'Lo metti da parte e dirigi tu l\'operazione, freddo dove lui era tenero. Funziona — a stento. Bruno ti guarda diverso, dopo.'),
                ],
            ]),
        ];
    }

    // Carla (coward): freezes in a crisis, frays the group's patience, then
    // either finds redemption — or breaks.
    private function carlaArc(): array
    {
        return [
            $this->ev([
                'key' => 'carla_arc_1', 'title' => 'Carla si blocca',
                'speaker' => 'Carla',
                'body' => "Un fronte di fiamme corre lungo la sterpaglia verso le scorte. Bruno e Nadia spalano sabbia urlando, ma Carla è inchiodata, le mani strette sul petto, lo sguardo perso. «Io... io non riesco. Mi dispiace. Non riesco a muovermi.» Il fuoco avanza.",
                'requires' => ['all' => [
                    ['alive' => 'Carla'],
                    ['day' => ['op' => '>=', 'value' => 4]],
                ]],
                'base_weight' => 8, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Scuotila. Abbiamo bisogno di lei ORA.', [['set_flag' => 'carla_froze', 'value' => true], ['resource' => 'food', 'delta' => -5], ['character' => 'Carla', 'stress' => 10]], 'La strattoni e si muove come un automa. Salvate metà delle scorte. Carla trema ancora ore dopo.'),
                    $this->one('Lasciala. Spegniamo il fuoco senza di lei.', [['set_flag' => 'carla_froze', 'value' => true], ['resource' => 'food', 'delta' => -8], ['modify_standing' => ['who' => 'Carla', 'delta' => -10]]], 'Vi arrangiate in tre. Carla resta a guardare, le lacrime che le rigano la cenere sul viso.'),
                ],
            ]),
            $this->ev([
                'key' => 'carla_arc_2', 'title' => 'La pazienza si logora',
                'speaker' => 'Carla',
                'body' => "È la terza volta che Carla si tira indietro davanti a un pericolo. Stavolta Nadia esplode: «Tu eri il pilota! Dovevi salvarci tu, e invece ci tocca portarti come un peso morto!» Carla incassa, muta. Il gruppo aspetta che tu dica qualcosa.",
                'requires' => ['all' => [
                    ['alive' => 'Carla'],
                    ['flag' => 'carla_froze', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->one('Difendila. La paura non è una colpa.', [['set_flag' => 'carla_strained', 'value' => true], ['resource' => 'morale', 'delta' => -3], ['modify_standing' => ['who' => 'Carla', 'delta' => 10]], ['spawn_event' => ['key' => 'carla_arc_3', 'in_days' => 3]]], 'Ti metti tra Carla e Nadia. Carla ti cerca con gli occhi, gratitudine e vergogna insieme.'),
                    $this->one('Dalle ragione a Nadia. Carla deve reagire.', [['set_flag' => 'carla_strained', 'value' => true], ['character' => 'Carla', 'stress' => 14], ['modify_standing' => ['who' => 'Carla', 'delta' => -8]], ['spawn_event' => ['key' => 'carla_arc_3', 'in_days' => 3]]], 'Carla annuisce piano, lo sguardo a terra. «Avete ragione voi. Sono un peso.» Si allontana sola.'),
                ],
            ]),
            $this->ev([
                'key' => 'carla_arc_3', 'title' => 'Il momento di Carla',
                'speaker' => 'Carla',
                'body' => "Una corrente trascina Nadia al largo mentre raccoglie molluschi. Nessuno sa nuotare bene in quel mare — tranne Carla, che da pilota lo ha studiato. Resta impietrita sulla riva un battito di troppo, poi ti guarda. «Posso provarci. Ma se mi blocco di nuovo, anneghiamo in due.»",
                'requires' => ['all' => [
                    ['alive' => 'Carla'],
                    ['flag' => 'carla_strained', 'is' => true],
                ]],
                'base_weight' => 9, 'cooldown_days' => 999,
                'choices' => [
                    $this->gamble('Vai, Carla. Credo in te.', [['resource' => 'morale', 'delta' => 14], ['set_flag' => 'carla_redeemed', 'value' => true], ['modify_standing' => ['who' => 'Carla', 'delta' => 20]]], 'Si butta. Per una volta non esita. Riporta Nadia a riva tossendo acqua e ridendo. Qualcosa, in lei, si è raddrizzato per sempre.', [['resource' => 'morale', 'delta' => -12], ['set_flag' => 'carla_broke', 'value' => true], ['character' => 'Carla', 'stress' => 22], ['modify_standing' => ['who' => 'Carla', 'delta' => -12]]], 'A metà strada il panico la riprende. Torna a riva da sola, vuota. Nadia ce la fa per i suoi mezzi, a stento. Carla non alza più la testa.', 6, 4, 'incerto'),
                    $this->one('Vado io. Tu non sei pronta.', [['resource' => 'fire', 'delta' => -5], ['character' => 'all', 'stress' => 8], ['set_flag' => 'carla_broke', 'value' => true], ['modify_standing' => ['who' => 'Carla', 'delta' => -6]]], 'Ti tuffi tu e riporti Nadia per il rotto della cuffia. Carla ti guarda dalla riva, e in quello sguardo c\'è una resa definitiva.'),
                ],
            ]),
        ];
    }

    // --- Stress-driven self-initiated behaviour (scheduled-only) ----
    // The island config's stress_bands schedules these when crew stress
    // crosses 60 / 85. Island flavour: isolamento, giungla, fame, paura.
    private function survivorStressEvents(): array
    {
        return [
            $this->ev([
                'key' => 'survivor_strained',
                'title' => 'Nervi tesi',
                'body' => "Qualcuno scaglia una gavetta contro un tronco. Il rumore resta a lungo nella giungla. La tensione, ormai, si tocca con mano.",
                'base_weight' => 10,
                'cooldown_days' => 0,
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    $this->one('Faccio finta di niente', [['resource' => 'morale', 'delta' => -4]], 'La cosa cova sotto la cenere.'),
                    $this->one('Gli parlo', [['resource' => 'morale', 'delta' => 3]], 'Si sfoga. La paura ha un nome, adesso. Per ora basta.'),
                ],
            ]),
            $this->ev([
                'key' => 'survivor_breaks',
                'title' => 'Crollo',
                'body' => "Uno dei tuoi ha smesso di rispondere. Sta seduto al limitare degli alberi, gli occhi fissi sul mare vuoto. Non ti sente nemmeno.",
                'base_weight' => 10,
                'cooldown_days' => 0,
                'requires' => ['flag' => '__scheduled_only', 'is' => true],
                'choices' => [
                    // Always-survivable option: calming a survivor never pushes a
                    // resource toward zero (keeps the card fair).
                    $this->one('Gli parlo con calma', [['character' => 'highest_stress', 'stress' => -20]], 'Pian piano si calma. Respira. Torna fra i vivi.', 'dovrebbe reggere'),
                    $this->one('Lo lascio stare', [['resource' => 'morale', 'delta' => -8]], 'Si chiude in sé. Per ore non dice una parola.', 'rischioso'),
                    $this->gamble('Lo costringo a lavorare', [['resource' => 'fire', 'delta' => 5]], 'Obbedisce, a denti stretti. La legna si accumula.', [['resource' => 'morale', 'delta' => -12], ['resource' => 'shelter', 'delta' => -15]], 'Sbaglia tutto. Manda all\'aria mezza giornata di lavoro. Forse di proposito.', 3, 2, 'molto pericoloso'),
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
            // ---- Atmosphere fillers: the island breathing -----------------
            $this->ev([
                'key' => 'c_filler_jungle_night', 'title' => 'La giungla di notte', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "Oltre il cerchio del fuoco la giungla è un muro nero che respira. Un verso, lungo e gorgogliante, che nessuno di voi sa nominare. Bruno smette di parlare a metà frase.",
                'choices' => [
                    $this->one('«È solo un animale.»', [['character' => 'all', 'stress' => -1]], 'Lo ripeti finché non ci credi un po\'. Funziona, quasi.'),
                    $this->one('Aggiungo legna al fuoco', [['resource' => 'fire', 'delta' => 1]], 'Le ombre indietreggiano. Il buio aspetta, paziente.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_flat_sea', 'title' => 'Il mare piatto', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "Niente onde, niente vento. Il mare è una lastra di stagno fino all'orizzonte, e nessuna sagoma a romperlo. Carla resta a fissarlo a lungo, le braccia strette intorno alle ginocchia.",
                'choices' => [
                    $this->one('Mi siedo accanto a lei', [['character' => 'Carla', 'stress' => -3]], 'Non dite niente. Non serve.'),
                    $this->one('Le porto via lo sguardo dall\'acqua', [['resource' => 'morale', 'delta' => 1]], '«Vieni, c\'è da fare.» A volte è la cosa più gentile.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_footprint', 'title' => 'L\'impronta', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 4,
                'body' => "Sulla sabbia umida, una sola impronta di piede nudo. Più grande delle vostre. La misurate contro le mani senza dirlo ad alta voce. La marea l'ha già mezza cancellata. Forse non c'è mai stata.",
                'choices' => [
                    $this->one('Non lo dico a nessuno', [['resource' => 'morale', 'delta' => -1]], 'Te lo tieni dentro, come una pietra in tasca.'),
                    $this->one('Tengo gli occhi aperti', [['resource' => 'shelter', 'delta' => 1]], 'Forse non siete soli. Meglio essere pronti.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_shells', 'title' => 'Conchiglie', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => "Una manciata di conchiglie levigate dal mare, di un rosa che pare impossibile su quest'isola. Le rigirate al sole. Per un momento è solo bellezza, senza secondo fine.",
                'choices' => [
                    $this->one('Le regalo a Carla', [['character' => 'Carla', 'stress' => -2]], 'Le infila in tasca come un tesoro di bambina.'),
                    $this->one('Ne faccio un mucchietto sul riparo', [['resource' => 'morale', 'delta' => 2]], 'Un piccolo segno che qui vive ancora qualcuno.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_crab_shell', 'title' => 'Un guscio di granchio', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => "Un guscio di granchio vuoto, intatto, abbandonato come un'armatura troppo stretta. Bruno lo solleva. «Anche lui è cresciuto e ha dovuto lasciarsi indietro la corazza vecchia. Non è male, come idea.»",
                'choices' => [
                    $this->one('«Filosofo di spiaggia.»', [['resource' => 'morale', 'delta' => 1]], 'Ride. Per una volta la battuta è leggera davvero.'),
                    $this->one('Lo rimetto dov\'era', [], 'Qualcosa, qui, va lasciato in pace.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_fire_shadows', 'title' => 'Ombre dal fuoco', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => "Il fuoco scoppietta e schizza scintille. Le vostre ombre ballano enormi sulla parete del riparo, e per un attimo sembrate dei giganti, non tre naufraghi spauriti.",
                'choices' => [
                    $this->one('Faccio le ombre con le mani', [['character' => 'all', 'stress' => -2]], 'Un cane, un uccello, un coniglio. Carla indovina tutto e ride.'),
                    $this->one('Resto a guardare le fiamme', [['resource' => 'morale', 'delta' => 1]], 'Il fuoco non chiede niente. È un buon compagno.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_distant_storm', 'title' => 'Un temporale lontano', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "All'orizzonte, sul mare, un temporale che non vi raggiungerà. Lampi muti dentro nuvole nere, lontanissimi. Da qui è quasi bello — la furia di qualcun altro.",
                'choices' => [
                    $this->one('Controllo le coperture, per sicurezza', [['resource' => 'shelter', 'delta' => 1]], 'Non si sa mai. Il vento gira in fretta, qui.'),
                    $this->one('Lo guardo e basta', [['resource' => 'morale', 'delta' => 1]], 'C\'è qualcosa di consolante in una tempesta che non è la tua.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_wreck_groan', 'title' => 'Il relitto geme', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => "Nel vento la carcassa dell'aereo geme, un lamento di lamiera che si torce. Nadia ci appoggia una mano. «Si sta ancora raffreddando. Tra un po' tacerà del tutto. Come tutto, qui.»",
                'choices' => [
                    $this->one('«Almeno ci ha portati fin qui.»', [['character' => 'Nadia', 'stress' => -2]], 'Lei annuisce piano. «Già. Almeno quello.»'),
                    $this->one('Recupero quel che posso dalla fusoliera', [['resource' => 'shelter', 'delta' => 1]], 'Un pannello, qualche vite. La carcassa dà ancora qualcosa.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_tidepool_fish', 'title' => 'Pesci nella secca', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 2,
                'body' => "La marea si è ritirata e ha lasciato pesci argentei a guizzare in una pozza tra gli scogli, intrappolati. Brillano disperati sotto il sole.",
                'choices' => [
                    $this->one('Ne prendo qualcuno', [['resource' => 'food', 'delta' => 2]], 'Cena facile. L\'isola, ogni tanto, è generosa.'),
                    $this->one('Li ributto in mare', [['resource' => 'morale', 'delta' => 2]], 'Carla ti guarda strano. Ma stasera dormirai meglio.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_shooting_star', 'title' => 'Una stella cadente', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "Senza le luci di nessuna città, il cielo è una cosa che non avevate mai visto: fitto, vivo, bianco di stelle. Una scia luminosa lo taglia in due e svanisce.",
                'choices' => [
                    $this->one('Esprimo un desiderio', [['character' => 'all', 'stress' => -2]], 'Sapete tutti e tre cosa avete chiesto. Nessuno lo dice.'),
                    $this->one('«Domani, da lì, ci cercheranno.»', [['resource' => 'morale', 'delta' => 2]], 'Forse è vero, forse no. Ma stanotte ci credete.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_salt_smell', 'title' => 'Odore di sale e legna bagnata', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "Dopo la pioggia, l'aria sa di sale e di legna fradicia che fuma sul fuoco. Un odore che ormai conoscete come la vostra pelle. È diventato l'odore di casa, che vi piaccia o no.",
                'choices' => [
                    $this->one('Respiro a fondo', [['resource' => 'morale', 'delta' => 1]], 'Strano: non è più sgradevole. È solo casa.'),
                    $this->one('Stendo i panni a asciugare', [['resource' => 'shelter', 'delta' => 1]], 'Piccoli gesti ordinari. Tengono insieme le giornate.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_strange_bird', 'title' => 'Un uccello mai visto', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => "Sul ramo più alto si è posato un uccello dai colori assurdi — verde, scarlatto, un occhio giallo e curioso. Vi studia senza paura, come se i naufraghi foste voi la novità.",
                'choices' => [
                    $this->one('Resto immobile a guardarlo', [['character' => 'all', 'stress' => -2]], 'Resta un minuto intero. Poi vola via, e vi sentite onorati.'),
                    $this->one('Provo a disegnarlo nel diario', [['resource' => 'morale', 'delta' => 2]], 'Viene male, ma è un giorno che vale la pena ricordare.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_animal_tracks', 'title' => 'Impronte nel fango', 'is_filler' => true, 'base_weight' => 4, 'cooldown_days' => 3,
                'body' => "Nel fango lungo il ruscello, vecchie impronte di animale — zampe, non piedi. Non sapete di che bestia, né se sia grossa. Ma vuol dire che, oltre il vostro accampamento, qui vive qualcosa.",
                'choices' => [
                    $this->one('Le seguo un tratto', [['resource' => 'food', 'delta' => 1]], 'Portano a una pianta da frutto che non avevate trovato. Grazie, chiunque tu sia.'),
                    $this->one('Le evito e torno indietro', [['resource' => 'shelter', 'delta' => 1]], 'Meglio non incontrarsi. Rinforzi la recinzione del campo.'),
                ],
            ]),
            $this->ev([
                'key' => 'c_filler_night_calm', 'title' => 'Una notte tranquilla', 'is_filler' => true, 'base_weight' => 5, 'cooldown_days' => 3,
                'body' => "Nessuna emergenza. Il vento è caldo, il fuoco basso e regolare, e i grilli — o quel che sono — cantano piano. Per una notte l'isola non chiede niente a nessuno.",
                'choices' => [
                    $this->one('Resto di guardia, ma sereno', [['character' => 'all', 'stress' => -3]], 'Gli altri dormono profondo. È un regalo, e lo sai.'),
                    $this->one('Dormo anch\'io, per una volta', [['resource' => 'morale', 'delta' => 2]], 'Vi svegliate riposati. Una rarità, qui.'),
                ],
            ]),
        ];
    }
}
