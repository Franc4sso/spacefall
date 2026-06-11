<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use App\Models\Event;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

/** Flag di servizio non-narrativi: scritti/letti dal motore, non dalle scelte. */
const FLAG_REACHABILITY_WHITELIST = [
    'expedition_active', '__scheduled_only', '__never',
    'crew_trust', 'died_of_hunger', 'epithet',
    // sensors_warned co-occorre SEMPRE con arc_truth_found (stesso outcome);
    // arc_truth_found copre già l'epilogo, evitando una riga duplicata.
    'sensors_warned',
];

/** Raccoglie ricorsivamente i flag letti da un albero di condizioni. */
function collectReadFlags(?array $cond, array &$into): void
{
    if ($cond === null) {
        return;
    }
    foreach (['all', 'any'] as $combinator) {
        if (array_key_exists($combinator, $cond)) {
            foreach ((array) $cond[$combinator] as $sub) {
                collectReadFlags($sub, $into);
            }
        }
    }
    if (array_key_exists('not', $cond)) {
        collectReadFlags($cond['not'], $into);
    }
    if (array_key_exists('flag', $cond)) {
        $into[$cond['flag']] = true;
    }
}

it('ogni flag scritto da una scelta è letto da una carta, un finale o l\'epilogo', function () {
    $written = [];
    $read = [];

    foreach (Event::all() as $event) {
        collectReadFlags($event->requires, $read);

        foreach ($event->choices as $choice) {
            if (isset($choice['requires'])) {
                collectReadFlags($choice['requires'], $read);
            }
            foreach ($choice['outcomes'] ?? [] as $outcome) {
                foreach ($outcome['effects'] ?? [] as $effect) {
                    if (array_key_exists('set_flag', $effect)
                        && ($effect['scope'] ?? 'run') === 'run') {
                        $written[$effect['set_flag']] = true;
                    }
                }
            }
        }
    }

    foreach (config('themes.space.endings') as $ending) {
        collectReadFlags($ending['when'] ?? null, $read);
    }

    foreach (array_keys(config('themes.space.epilogue.witness_flags', [])) as $flag) {
        $read[$flag] = true;
    }

    foreach (array_keys(config('themes.space.epilogue.escape_outcome_lines', [])) as $flag) {
        $read[$flag] = true;
    }

    $orphans = array_values(array_filter(
        array_keys($written),
        fn ($f) => ! isset($read[$f]) && ! in_array($f, FLAG_REACHABILITY_WHITELIST, true),
    ));

    expect($orphans)->toBe([], 'Flag scritti ma mai letti (collegali a una carta/finale/epilogo): ' . implode(', ', $orphans));
});

/**
 * Debito documentato: flag isola scritti ma non ancora letti da nessuna
 * carta/finale/epilogo. Ogni voce è inerte OGGI; i task di contenuto futuri
 * RIMUOVONO le voci man mano che cablano davvero il flag. Non aggiungere
 * voci nuove senza un commento che spieghi perché il flag è inerte.
 */
const ISLAND_FLAG_REACHABILITY_IGNORE = [
    // Conseguenza survivor-arc: settato quando mandi qualcuno a recuperare
    // provviste e annega (IslandEventSeeder, evento "fetch supplies"). Oggi
    // nessun finale/epilogo lo legge; un task colpa/sopravvissuti lo caplerà.
    'sent_to_drown',
    // mystery hook, sub-project 3
    'arc_log_truth',
];

it('island: ogni flag scritto da una scelta è letto da una carta, un finale o l\'epilogo', function () {
    $this->seed(\Database\Seeders\IslandEventSeeder::class);

    $written = [];
    $read = [];

    foreach (Event::where('theme', 'island')->get() as $event) {
        collectReadFlags($event->requires, $read);

        foreach ($event->choices as $choice) {
            if (isset($choice['requires'])) {
                collectReadFlags($choice['requires'], $read);
            }
            foreach ($choice['outcomes'] ?? [] as $outcome) {
                foreach ($outcome['effects'] ?? [] as $effect) {
                    if (array_key_exists('set_flag', $effect)
                        && ($effect['scope'] ?? 'run') === 'run') {
                        $written[$effect['set_flag']] = true;
                    }
                }
            }
        }
    }

    foreach (config('themes.island.endings') as $ending) {
        collectReadFlags($ending['when'] ?? null, $read);
    }

    foreach (array_keys(config('themes.island.epilogue.witness_flags', [])) as $flag) {
        $read[$flag] = true;
    }

    foreach (array_keys(config('themes.island.epilogue.rescue_outcome_lines', [])) as $flag) {
        $read[$flag] = true;
    }

    $orphans = array_values(array_filter(
        array_keys($written),
        fn ($f) => ! isset($read[$f]) && ! in_array($f, ISLAND_FLAG_REACHABILITY_IGNORE, true),
    ));

    expect($orphans)->toBe([], 'Flag isola scritti ma mai letti: ' . implode(', ', $orphans));
});
