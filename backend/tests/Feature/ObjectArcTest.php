<?php

use App\Game\Engine\EventSchema;
use App\Models\Event;
use Database\Seeders\ContentEventSeeder;
use Database\Seeders\EventSeeder;

beforeEach(function () {
    $this->seed(EventSeeder::class);
    $this->seed(ContentEventSeeder::class);
});

function arcEvents(): \Illuminate\Support\Collection
{
    return Event::where('key', 'like', 'arc_%')->get();
}

function arcOutcomeHasCost(array $outcome): bool
{
    foreach (($outcome['effects'] ?? []) as $e) {
        if (! is_array($e)) continue;
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

it('seeds the seedbank arc as three chained events', function () {
    foreach (['arc_seedbank_1', 'arc_seedbank_2', 'arc_seedbank_3'] as $key) {
        expect(Event::where('key', $key)->exists())->toBeTrue("missing {$key}");
    }
    $s2 = Event::where('key', 'arc_seedbank_2')->first();
    expect(json_encode($s2->requires))->toContain('arc_seedbank_stage1');
    $s3 = Event::where('key', 'arc_seedbank_3')->first();
    expect(json_encode($s3->requires))->toContain('arc_seedbank_stage2');
});

it('schedules the next stage from each arc stage', function () {
    $s1 = Event::where('key', 'arc_seedbank_1')->first();
    expect(json_encode($s1->choices))->toContain('arc_seedbank_2');
    $s2 = Event::where('key', 'arc_seedbank_2')->first();
    expect(json_encode($s2->choices))->toContain('arc_seedbank_3');
});

it('has no free choice in any multi-option arc event', function () {
    $offenders = [];
    foreach (arcEvents() as $e) {
        $choices = $e->choices ?? [];
        if (count($choices) < 2) continue;
        foreach ($choices as $i => $choice) {
            $hasCost = false;
            foreach (($choice['outcomes'] ?? []) as $o) {
                if (arcOutcomeHasCost($o)) { $hasCost = true; break; }
            }
            if (! $hasCost) $offenders[] = "{$e->key} choice#{$i}";
        }
    }
    expect($offenders)->toBe([], 'Free choices: ' . implode(', ', $offenders));
});

it('keeps every arc event valid against the DSL schema', function () {
    $schema = new EventSchema(array_keys(config('game.resources')));
    arcEvents()->each(function (Event $e) use ($schema) {
        $schema->validate(['key' => $e->key, 'title' => $e->title, 'body' => $e->body, 'choices' => $e->choices, 'requires' => $e->requires]);
        expect(true)->toBeTrue();
    });
});

it('seeds the comms arc chained, ending in a rescue-answer that sets sos_sent', function () {
    foreach (['arc_comms_1', 'arc_comms_2', 'arc_comms_3'] as $key) {
        expect(Event::where('key', $key)->exists())->toBeTrue("missing {$key}");
    }
    expect(json_encode(Event::where('key', 'arc_comms_2')->first()->requires))->toContain('arc_comms_stage1');
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->requires))->toContain('arc_comms_stage2');
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->choices))->toContain('sos_sent');
    expect(json_encode(Event::where('key', 'arc_comms_3')->first()->choices))->toContain('arc_rescue_answered');
});
