# Oggetti-strumento nelle crisi comuni — Design

> Spec del 2026-06-11. Feature Tier 1 #1 da `docs/superpowers/TODO.md`.
> Estende l'interazione oggetto→scelta alle crisi più frequenti, l'arma più
> efficiente contro la ripetizione.

---

## 1. Problema

Le ~10 carte-crisi più frequenti (alto `base_weight`) si ripetono di run in run
con le stesse 2-3 scelte. Oggi solo gli oggetti-cibo e l'attrezzatura-spedizioni
aprono scelte condizionate; le crisi comuni no. Risultato: dopo poche run il
giocatore riconosce ogni carta e sceglie in automatico.

## 2. Soluzione

Ognuna delle 10 carte-crisi più frequenti ottiene **una scelta extra gated su un
oggetto-strumento** che il giocatore può possedere. La scelta è una **scorciatoia
migliore** della via base (costo-risorsa minore / esito migliore) **ma consuma
l'attrezzo** (`consume_item`). L'attrezzo è quindi una risorsa scarsa: "lo brucio
su questa crisi o lo tengo per una peggiore?".

### 2.1 Niente engine nuovo

Il motore è già pronto e va **solo riusato** (coerente con la nota di memoria
[[narrative-consequences-engine-exists]]):

- **Gating per-scelta**: `ConditionEvaluator` valuta `has_item`
  (`ConditionEvaluator.php:107-109`); `EventEngine` filtra le scelte su `requires`
  sia in `visibleChoices()` (`:263`) sia alla risoluzione (`:123`).
- **Consumo binario**: l'effetto `consume_item` esiste e funziona
  (`EffectApplier.php:115`), già usato da drone/medkit/spacesuit. Toglie
  l'oggetto **intero** (1 uso) — è esattamente la semantica voluta.
- **Hint UI**: il campo `requires_item` sulla scelta segnala all'interfaccia
  quale oggetto la sblocca (pattern in `EventSeeder.php:68-69`, `:211-217`).

Questa feature è quindi **contenuto + numeri**, zero codice nuovo.

## 3. Mappatura oggetto → crisi

Vincolo: usare **solo i 9 oggetti della griglia iniziale sbloccata**
(`config/game.php:496-504`) — drone, scanner, welder, medkit, rifle, seedbank,
spacesuit, comms, rations. Gli oggetti `locked` (toolkit, fabricator, sensors,
flare, logbank, manual, reactor_cell…) NON vanno usati: non sono pescabili finché
il sistema di meta-sblocco (Tier 3 #6) non li attiva, quindi la scelta non
scatterebbe mai. (Risvegliarli è un lavoro separato — debito noto nel TODO.)

Il giocatore parte con **4 oggetti su 9** (`items_pick = 4`), quindi ogni scelta
gated compare solo in alcune run: variabilità voluta.

**Restrizione del modello (rilevata leggendo le carte reali, 2026-06-11):** la
"scorciatoia più economica" ha senso solo dove la via base ha un **costo-risorsa
reale**. Delle 10 carte frequenti candidate:

- `power_flicker` ha **già** una scelta gated (welder) → esclusa, niente da fare.
- `old_scorch`, `reactor_gamble`, `the_sacrifice` hanno **costo-base = 0** (carte
  positive / solo-flag): non c'è nulla da rendere "più economico". Escluse — non
  forziamo il modello su carte che non lo reggono.

Restano **6 carte** con costo-base reale. Questa è la mappatura definitiva:

| Carta | Peso | File:linea | Attrezzo | Costo-base peggiore | Scorciatoia |
|---|---|---|---|---|---|
| `ration_crisis` | 22 | EventSeeder.php:347 | **rations** | morale −14 (mangio solo io) | apri il cuscinetto → niente fame, nessuno mangia da solo. (2ª scelta gated accanto al rifle già esistente.) |
| `power_cascade` | 10 | EventSeeder.php:79 | **scanner** | power −12 / oxygen −10 | leggi quale linea cede e la isoli → fermi la cascata senza bruciare aria né settore |
| `survivor_strained` | 10 | EventSeeder.php:447 | **medkit** | morale −4 | un sedativo → lo calmi senza perdita di morale |
| `survivor_breaks` | 10 | EventSeeder.php:475 | **medkit** | morale −12 / stress | tratti il crollo → lo riporti in sé senza danni |
| `ration_cut_decision` | 9 | ContentEventSeeder.php:1738 | **rations** | morale −15 + sfiducia + rivolta | attingi alla riserva → eviti del tutto il taglio |
| `fuel_leak_warning` | 8 | ContentEventSeeder.php:1690 | **spacesuit** | power −12 (→ −30 se ignori) | esci a sigillare la perdita → niente fuga di carburante |

Distribuzione: medkit ×2, rations ×2, scanner ×1, spacesuit ×1.

> Nota: `ration_crisis` avrà **due** scelte gated (il `rifle`→caccia già presente
> + la nuova `rations`→riserva). Sono vie distinte e coerenti; nessun conflitto.

## 4. Forma di ogni scelta

Per ogni carta, una nuova voce nell'array `choices`:

```php
[
    'label'         => '<verbo + attrezzo>',     // es. "Salda la breccia"
    'hint'          => '<costo/consumo>',         // es. "consuma la saldatrice"
    'requires'      => ['has_item' => '<key>'],
    'requires_item' => '<key>',                   // hint UI
    'outcomes' => [[
        'weight'  => 1,
        'effects' => [
            ['consume_item' => '<key>'],
            // + effetto-risparmio: la via base costa X, questa costa molto meno
        ],
        'log' => '<esito riuscito, tono asciutto>',
    ]],
],
```

### 4.1 Regola di bilanciamento (numeri, da tarare a playtest)

La scorciatoia deve essere **chiaramente migliore** della via base sulla risorsa
in gioco, ma **non gratis**: il costo è la perdita dell'attrezzo.

- Identificare il costo-risorsa peggiore della via base della carta (es.
  `hull -8`).
- La scorciatoia lo **azzera o quasi** (es. `hull 0`, al più un piccolo costo
  collaterale su un'altra risorsa).
- Nessun nuovo costo-risorsa pesante: il prezzo *è* `consume_item`.
- Esito **deterministico** (un solo outcome, weight 1): l'attrezzo *funziona*.
  Niente gamble — il gamble è la via base senza attrezzo.

I delta esatti per carta si fissano in fase di piano leggendo le vie base
esistenti di ciascuna carta, e si tarano col simulatore/playtest (leva di tuning,
come fame e spedizioni).

## 5. Confini e non-obiettivi

- **NO cariche multiple.** Consumo binario (1 uso), riusa `consume_item`. Vere
  cariche (contatore per-oggetto) sono un sistema nuovo, fuori scope.
- **NO oggetti locked.** Solo i 9 della griglia sbloccata. Risvegliare i
  dormienti è il debito Tier 3 #6, separato.
- **NO trade-off.** La scorciatoia è vantaggio puro sulla risorsa; l'unico costo
  è perdere l'attrezzo. (Decisione di design: leggibilità massima.)
- **NO nuove carte.** Solo nuove *scelte* su carte esistenti.
- **NO modifiche all'engine.** Se durante l'implementazione serve toccare
  `ConditionEvaluator`/`EffectApplier`/`EventEngine`, fermarsi: significa che
  un'assunzione è sbagliata.

## 6. Testing

Pattern dei test esistenti (`EscapeChainTest`, `EndingTest`): asserzioni
seed-time sul contenuto, non simulazione.

1. **Presenza scelta gated** — per ognuna delle 6 carte: la scelta extra esiste,
   ha `requires.has_item = <key atteso>` e `requires_item` coerente.
2. **Consumo** — ogni scelta gated ha un effetto `consume_item` del proprio
   attrezzo in tutti i suoi outcome.
3. **Solo griglia sbloccata** — nessuna delle 10 scelte gata su un oggetto
   `locked` (test-guardiano: legge `config('game.items')`, fallisce se una scelta
   gata su un oggetto non nella griglia sbloccata).
4. **Visibilità** — con l'oggetto in inventario la scelta è in `visibleChoices`;
   senza, è nascosta (un caso end-to-end su una carta rappresentativa, es.
   `power_flicker`).
5. **Vantaggio** — la scorciatoia costa, sulla risorsa-chiave della carta, meno
   della via base peggiore (un caso rappresentativo; il resto è tuning).

Suite intera (`php artisan test`) verde + `tsc --noEmit` pulito a fine lavoro.

## 7. Stima

~5-6 task: lettura vie base delle 10 carte → scrittura 10 scelte nei seeder →
test-guardiano "solo griglia" → test presenza/consumo → taratura numeri →
verifica suite. Tutto in `EventSeeder.php` / `ContentEventSeeder.php` (dove vive
ciascuna carta) e nei test.
