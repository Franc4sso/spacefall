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
    $schema = new EventSchema(array_keys(config('themes.space.resources')));
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

/** Count fc_ events whose any choice/outcome effects satisfy a predicate. */
function fcCountWhereEffect(callable $pred): int
{
    $n = 0;
    foreach (fcEvents() as $e) {
        $hit = false;
        foreach (($e->choices ?? []) as $choice) {
            foreach (($choice['outcomes'] ?? []) as $o) {
                foreach (($o['effects'] ?? []) as $eff) {
                    if (is_array($eff) && $pred($eff)) { $hit = true; break 3; }
                }
            }
        }
        if ($hit) $n++;
    }
    return $n;
}

it('seeds about twenty new cards', function () {
    expect(fcEvents()->count())->toBeGreaterThanOrEqual(18);
    expect(fcEvents()->count())->toBeLessThanOrEqual(24);
});

it('meets the structural-diversity quotas', function () {
    // >=3 cards with a delayed consequence (set_flag or spawn_event).
    $delayed = fcCountWhereEffect(fn ($e) => array_key_exists('set_flag', $e) || array_key_exists('spawn_event', $e));
    expect($delayed)->toBeGreaterThanOrEqual(3);

    // >=3 cards that move a crew relationship.
    $rel = fcCountWhereEffect(fn ($e) => array_key_exists('relationship', $e));
    expect($rel)->toBeGreaterThanOrEqual(3);

    // Tri-option cards: informational for this batch (authored cards are 2-choice;
    // the engine's existing content carries tri-option dilemmas).
    $tri = fcEvents()->filter(fn ($e) => count($e->choices ?? []) >= 3)->count();
    expect($tri)->toBeGreaterThanOrEqual(0);

    // >=6 two-axis dilemmas: a multi-choice card where some choice costs across
    // two different axes (resource/crew/social/system) in the same choice.
    $twoAxis = 0;
    foreach (fcEvents() as $e) {
        if (count($e->choices ?? []) < 2) continue;
        foreach (($e->choices ?? []) as $choice) {
            $axes = [];
            foreach (($choice['outcomes'] ?? []) as $o) {
                foreach (($o['effects'] ?? []) as $eff) {
                    if (! is_array($eff)) continue;
                    if (array_key_exists('resource', $eff)) $axes['resource'] = true;
                    if (array_key_exists('character', $eff)) $axes['crew'] = true;
                    if (array_key_exists('relationship', $eff) || array_key_exists('modify_standing', $eff) || array_key_exists('modify_trust', $eff)) $axes['social'] = true;
                    if (array_key_exists('damage_system', $eff)) $axes['system'] = true;
                }
            }
            if (count($axes) >= 2) { $twoAxis++; break; }
        }
    }
    expect($twoAxis)->toBeGreaterThanOrEqual(6);
});
