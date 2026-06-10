<?php

use App\Game\Engine\EpilogueComposer;
use App\Game\Engine\RunState;

function endedState(array $overrides = []): RunState
{
    return new RunState(
        day: $overrides['day'] ?? 26,
        resources: $overrides['resources'] ?? ['oxygen' => 30, 'food' => 30, 'power' => 30, 'morale' => 30, 'hull' => 30],
        flags: $overrides['flags'] ?? [],
        characters: $overrides['characters'] ?? [
            ['name' => 'Anna', 'role' => 'engineer', 'traits' => [], 'alive' => true, 'stress' => 70, 'hunger' => 0, 'away_until' => 0],
        ],
        deathLog: $overrides['deathLog'] ?? [],
    );
}

it('builds a fallen section from the death log', function () {
    $c = new EpilogueComposer();
    $state = endedState(['deathLog' => [
        ['name' => 'Cole', 'day' => 14, 'cause' => 'expedition', 'context' => 'wreck'],
    ]]);
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'ULTIMO IN PIEDI', 'text' => '...']);
    $fallen = collect($sections)->firstWhere('title', 'Caduti');
    expect($fallen)->not->toBeNull();
    expect(implode(' ', $fallen['lines']))->toContain('Cole');
    expect(implode(' ', $fallen['lines']))->toContain('14');
    expect(implode(' ', $fallen['lines']))->toContain('spedizione');
});

it('includes a key-choices section from witness flags', function () {
    $c = new EpilogueComposer();
    $state = endedState(['flags' => ['cannibalism' => true]]);
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $choices = collect($sections)->firstWhere('title', 'Le tue scelte');
    expect($choices)->not->toBeNull();
    expect(implode(' ', $choices['lines']))->toContain('mangiato');
});

it('reports survivors with a colored line', function () {
    $c = new EpilogueComposer();
    $state = endedState();
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $surv = collect($sections)->firstWhere('title', 'I superstiti');
    expect($surv)->not->toBeNull();
    expect(implode(' ', $surv['lines']))->toContain('Anna');
});

it('always opens with the outcome section using the ending text', function () {
    $c = new EpilogueComposer();
    $sections = $c->compose(endedState(), ['key' => 'lone_survivor', 'name' => 'ULTIMO IN PIEDI', 'text' => 'Hai salvato la stazione.']);
    expect($sections[0]['title'])->toBe('Esito');
    expect(implode(' ', $sections[0]['lines']))->toContain('Hai salvato la stazione.');
});

it('omits empty sections (no deaths => no Caduti)', function () {
    $c = new EpilogueComposer();
    $sections = $c->compose(endedState(['deathLog' => []]), ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    expect(collect($sections)->firstWhere('title', 'Caduti'))->toBeNull();
});

it('include una riga epilogo per cole_heroics', function () {
    $c = new EpilogueComposer();
    $state = endedState(['flags' => ['cole_heroics' => true]]);
    $sections = $c->compose($state, ['key' => 'lone_survivor', 'name' => 'x', 'text' => 'y']);
    $choices = collect($sections)->firstWhere('title', 'Le tue scelte');
    expect($choices)->not->toBeNull();
    expect(implode(' ', $choices['lines']))->toContain('comandi');
});
