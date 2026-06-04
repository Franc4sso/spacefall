<?php

namespace App\Models;

use App\Game\SeededRng;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $seed
 * @property int    $rng_cursor
 * @property int    $day
 * @property array       $resources
 * @property string      $status
 * @property array       $flags
 * @property array       $recent_events
 * @property array       $scheduled_events
 * @property string|null $current_event_key
 */
class Run extends Model
{
    protected $fillable = [
        'seed',
        'rng_cursor',
        'day',
        'resources',
        'status',
        'flags',
        'recent_events',
        'scheduled_events',
        'current_event_key',
    ];

    protected $casts = [
        'seed' => 'integer',
        'rng_cursor' => 'integer',
        'day' => 'integer',
        'resources' => 'array',
        'flags' => 'array',
        'recent_events' => 'array',
        'scheduled_events' => 'array',
    ];

    protected $attributes = [
        'flags' => '{}',
        'recent_events' => '{}',
        'scheduled_events' => '[]',
    ];

    /**
     * A fresh RNG positioned at this run's current cursor. After drawing,
     * persist the advanced cursor with syncRng().
     */
    public function rng(): SeededRng
    {
        return new SeededRng($this->seed, $this->rng_cursor);
    }

    /**
     * Persist the cursor after the given RNG has been drawn from, so the
     * stream continues correctly on the next request.
     */
    public function syncRng(SeededRng $rng): void
    {
        $this->rng_cursor = $rng->cursor();
    }
}
