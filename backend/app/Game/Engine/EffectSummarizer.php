<?php

namespace App\Game\Engine;

/**
 * Turns a raw effects list into a compact, readable summary: net resource deltas
 * plus short Italian notes for notable non-resource effects (a death, a damaged
 * system, a consumed item, crew stress). Pure. Used by the choice log (so the
 * timeline records what each choice DID) and by the UI delta display.
 */
final class EffectSummarizer
{
    /**
     * @param  list<array<string,mixed>>  $effects
     * @return array{resources: array<string,int>, notes: list<string>}
     */
    public function summarize(array $effects): array
    {
        $resources = [];
        $notes = [];

        foreach ($effects as $e) {
            if (! is_array($e)) {
                continue;
            }
            if (array_key_exists('resource', $e)) {
                $code = $e['resource'];
                $resources[$code] = ($resources[$code] ?? 0) + (int) ($e['delta'] ?? 0);
            } elseif (array_key_exists('character', $e)) {
                $who = $e['character'];
                if (($e['stress'] ?? 0) != 0) {
                    $notes[] = "{$who}: stress " . $this->signed((int) $e['stress']);
                }
                if (($e['hunger'] ?? 0) != 0) {
                    $notes[] = "{$who}: fame " . $this->signed((int) $e['hunger']);
                }
            } elseif (array_key_exists('kill', $e)) {
                $notes[] = 'morte';
            } elseif (array_key_exists('damage_system', $e)) {
                $notes[] = $e['damage_system'] . ' danneggiato';
            } elseif (array_key_exists('consume_item', $e)) {
                $notes[] = $e['consume_item'] . ' consumato';
            } elseif (array_key_exists('grant_item', $e)) {
                $notes[] = $e['grant_item'] . ' ottenuto';
            } elseif (array_key_exists('relationship', $e)) {
                $notes[] = 'rapporto cambiato';
            }
        }

        $resources = array_filter($resources, fn ($v) => $v !== 0);

        return ['resources' => $resources, 'notes' => array_values($notes)];
    }

    private function signed(int $n): string
    {
        return ($n > 0 ? '+' : '') . $n;
    }
}
