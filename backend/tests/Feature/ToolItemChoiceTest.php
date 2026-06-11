<?php

use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Le 6 carte-crisi che ricevono una scelta gated su oggetto-strumento, con
 * l'attrezzo atteso. La scelta è la scorciatoia che consuma l'attrezzo.
 *
 * @return array<string, string> card key => expected item key
 */
function toolItemExpectations(): array
{
    return [
        'ration_crisis'       => 'rations',
        'power_cascade'       => 'scanner',
        'survivor_strained'   => 'medkit',
        'survivor_breaks'     => 'medkit',
        'ration_cut_decision' => 'rations',
        'fuel_leak_warning'   => 'spacesuit',
    ];
}

/** Carica tutte le carte seedate come mappa key => event row. */
function toolItemSeededEvents(): array
{
    (new EventSeeder())->run();
    (new ContentEventSeeder())->run();

    return \App\Models\Event::all()->keyBy('key')->all();
}

/** Trova la scelta gated su $item dentro le choices di una carta. */
function toolItemGatedChoice(array $choices, string $item): ?array
{
    foreach ($choices as $c) {
        if (($c['requires']['has_item'] ?? null) === $item) {
            return $c;
        }
    }

    return null;
}

it('ogni carta-crisi ha una scelta gated sul proprio attrezzo', function () {
    $events = toolItemSeededEvents();

    foreach (toolItemExpectations() as $cardKey => $itemKey) {
        expect(array_key_exists($cardKey, $events))->toBeTrue("carta mancante: {$cardKey}");
        $choices = $events[$cardKey]->choices;
        $choice = toolItemGatedChoice($choices, $itemKey);
        expect($choice)->not->toBeNull("scelta gated su {$itemKey} mancante in {$cardKey}");
        expect($choice['requires_item'] ?? null)->toBe($itemKey,
            "requires_item incoerente in {$cardKey}");
    }
});

it('ogni scelta-strumento consuma il proprio attrezzo in tutti gli outcome', function () {
    $events = toolItemSeededEvents();

    foreach (toolItemExpectations() as $cardKey => $itemKey) {
        $choice = toolItemGatedChoice($events[$cardKey]->choices ?? [], $itemKey);
        if ($choice === null) {
            // scelta non ancora aggiunta — il primo test già la segnalerà
            continue;
        }
        foreach ($choice['outcomes'] as $o) {
            $consumes = collect($o['effects'])
                ->contains(fn ($e) => ($e['consume_item'] ?? null) === $itemKey);
            expect($consumes)->toBeTrue("outcome senza consume_item {$itemKey} in {$cardKey}");
        }
    }
});

it('nessuna scelta-strumento gata su un oggetto fuori dalla griglia sbloccata', function () {
    $unlocked = collect(config('game.items'))
        ->reject(fn ($i) => $i['locked'] ?? false)
        ->pluck('key')
        ->all();

    foreach (toolItemExpectations() as $itemKey) {
        expect($unlocked)->toContain($itemKey,
            "attrezzo {$itemKey} non è nella griglia sbloccata");
    }
});
