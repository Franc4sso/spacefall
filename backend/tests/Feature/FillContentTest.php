<?php

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;
use Database\Seeders\FillContentEventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
    $this->seed(FillContentEventSeeder::class);
});

function fcEvents(): \Illuminate\Support\Collection
{
    return Event::where('key', 'like', 'fc_%')->get();
}

function outcomeHasCost(array $outcome): bool
{
    foreach (($outcome['effects'] ?? []) as $e) {
        if (! is_array($e)) {
            continue;
        }
        if (array_key_exists('resource', $e) && (int) ($e['delta'] ?? 0) < 0) return true;
        if (array_key_exists('character', $e) && ((int) ($e['stress'] ?? 0) > 0 || (int) ($e['hunger'] ?? 0) > 0)) return true;
        if (array_key_exists('damage_system', $e)) return true;
        if (array_key_exists('kill', $e)) return true;
        if (array_key_exists('consume_item', $e)) return true;
        if (array_key_exists('relationship', $e) && (int) ($e['relationship']['delta'] ?? 0) < 0) return true;
        if (array_key_exists('modify_trust', $e) && (int) $e['modify_trust'] < 0) return true;
        if (array_key_exists('modify_standing', $e) && (int) ($e['modify_standing']['delta'] ?? 0) < 0) return true;
        if (array_key_exists('set_flag', $e)) return true;
        if (array_key_exists('spawn_event', $e)) return true;
    }
    return false;
}

function choiceHasCost(array $choice): bool
{
    foreach (($choice['outcomes'] ?? []) as $o) {
        if (outcomeHasCost($o)) return true;
    }
    return false;
}

it('has no free choice: every multi-option card has a cost on every choice', function () {
    $offenders = [];
    foreach (fcEvents() as $e) {
        $choices = $e->choices ?? [];
        if (count($choices) < 2) {
            continue;
        }
        foreach ($choices as $i => $choice) {
            if (! choiceHasCost($choice)) {
                $offenders[] = "{$e->key} choice#{$i} ('".($choice['label'] ?? '')."')";
            }
        }
    }
    expect($offenders)->toBe([], 'These choices are free (no cost on any outcome): ' . implode(', ', $offenders));
});

it('keeps every new event valid against the DSL schema', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));
    fcEvents()->each(function (Event $e) use ($schema) {
        $schema->validate([
            'key' => $e->key, 'title' => $e->title, 'body' => $e->body,
            'choices' => $e->choices, 'requires' => $e->requires,
        ]);
        expect(true)->toBeTrue();
    });
});

it('uses unique keys for the new batch', function () {
    $keys = fcEvents()->pluck('key');
    expect($keys->count())->toBe($keys->unique()->count());
});
