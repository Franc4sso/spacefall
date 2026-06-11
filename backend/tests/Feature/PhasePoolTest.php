<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * I 15 nuovi eventi-fase introdotti da questa feature, con la fase attesa.
 *
 * @return array<string, string> event key => expected phase
 */
function phasePoolNewEvents(): array
{
    return [
        'iso_inventory'        => 'isolation',
        'iso_first_friction'   => 'isolation',
        'iso_routine'          => 'isolation',
        'iso_old_terminal'     => 'isolation',
        'iso_ration_habit'     => 'isolation',
        'det_compound_failure' => 'deterioration',
        'det_dwindling_stores' => 'deterioration',
        'det_cracks_showing'   => 'deterioration',
        'det_rumor'            => 'deterioration',
        'det_make_do'          => 'deterioration',
        'rec_who_eats'         => 'reckoning',
        'rec_the_truth'        => 'reckoning',
        'rec_last_repair'      => 'reckoning',
        'rec_who_stays'        => 'reckoning',
        'rec_reckoning_vote'   => 'reckoning',
    ];
}

function phasePoolEvents(): array
{
    (new EventSeeder())->run();
    (new ContentEventSeeder())->run();

    return \App\Models\Event::all()->keyBy('key')->all();
}

it('ogni nuovo evento-fase esiste ed è gated sulla fase attesa', function () {
    $events = phasePoolEvents();

    foreach (phasePoolNewEvents() as $key => $phase) {
        expect(array_key_exists($key, $events))->toBeTrue("evento mancante: {$key}");
        $req = $events[$key]->requires;
        expect($req['phase'] ?? null)->toBe($phase, "fase errata in {$key}");
    }
});

it('ci sono esattamente 5 nuovi eventi per fase', function () {
    $byPhase = [];
    foreach (phasePoolNewEvents() as $phase) {
        $byPhase[$phase] = ($byPhase[$phase] ?? 0) + 1;
    }
    expect($byPhase)->toBe([
        'isolation' => 5,
        'deterioration' => 5,
        'reckoning' => 5,
    ]);
});

it('posta crescente: gli iso_* non uccidono né scrivono flag-finale; almeno un rec_* è definitivo', function () {
    $events = phasePoolEvents();

    $effectsOf = function ($event) {
        $all = [];
        foreach ($event->choices as $choice) {
            foreach ($choice['outcomes'] as $o) {
                foreach ($o['effects'] as $e) {
                    $all[] = $e;
                }
            }
        }
        return $all;
    };

    $finalFlags = ['made_the_sacrifice', 'left_someone', 'cannibalism'];

    foreach (phasePoolNewEvents() as $key => $phase) {
        if ($phase !== 'isolation') {
            continue;
        }
        if (!array_key_exists($key, $events)) {
            continue;
        }
        foreach ($effectsOf($events[$key]) as $e) {
            expect(array_key_exists('kill', $e))->toBeFalse("iso {$key} non deve uccidere");
            $flag = $e['set_flag'] ?? null;
            expect(in_array($flag, $finalFlags, true))->toBeFalse("iso {$key} non deve scrivere flag-finale");
        }
    }

    $hasDefinitive = false;
    foreach (phasePoolNewEvents() as $key => $phase) {
        if ($phase !== 'reckoning') {
            continue;
        }
        if (!array_key_exists($key, $events)) {
            continue;
        }
        foreach ($effectsOf($events[$key]) as $e) {
            if (array_key_exists('kill', $e) || in_array($e['set_flag'] ?? null, $finalFlags, true)) {
                $hasDefinitive = true;
            }
        }
    }
    expect($hasDefinitive)->toBeTrue('almeno un rec_* deve avere un esito definitivo (kill o flag-finale)');
});

it('in deterioration il selector include i det_* e non gli iso_*/rec_* esclusivi', function () {
    $events = phasePoolEvents();
    $evaluator = new \App\Game\Engine\ConditionEvaluator();

    $run = app(\App\Game\RunFactory::class)->create(1, []);
    $run->day = 12;
    $run->phase_floor = 'deterioration';
    $run->save();
    $state = \App\Game\Engine\RunState::fromRun($run->fresh());
    expect($state->phase)->toBe('deterioration');

    $eligible = fn ($key) => $evaluator->evaluate($events[$key]->requires, $state);
    expect($eligible('det_compound_failure'))->toBeTrue();
    expect($eligible('iso_inventory'))->toBeFalse();
    expect($eligible('rec_who_eats'))->toBeFalse();
});
