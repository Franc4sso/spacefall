<?php

namespace App\Models;

use App\Game\SeededRng;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property array       $choice_log
 */
class Run extends Model
{
    use HasFactory;
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
        'characters',
        'relationships',
        'items',
        'systems',
        'ending_key',
        'ending_type',
        'profile_id',
        'choice_log',
        'phase_floor',
        'death_log',
    ];

    protected $casts = [
        'seed' => 'integer',
        'rng_cursor' => 'integer',
        'day' => 'integer',
        'resources' => 'array',
        'flags' => 'array',
        'recent_events' => 'array',
        'scheduled_events' => 'array',
        'characters' => 'array',
        'relationships' => 'array',
        'items' => 'array',
        'systems' => 'array',
        'choice_log' => 'array',
        'death_log' => 'array',
    ];

    protected $attributes = [
        'flags' => '{}',
        'recent_events' => '{}',
        'scheduled_events' => '[]',
        'characters' => '[]',
        'relationships' => '[]',
        'items' => '[]',
        'systems' => '{}',
        'choice_log' => '[]',
        'phase_floor' => 'isolation',
        'death_log' => '[]',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

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
