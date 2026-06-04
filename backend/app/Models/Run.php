<?php

namespace App\Models;

use App\Game\SeededRng;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $seed
 * @property int    $rng_cursor
 * @property int    $day
 * @property array  $resources
 * @property string $status
 */
class Run extends Model
{
    protected $fillable = [
        'seed',
        'rng_cursor',
        'day',
        'resources',
        'status',
    ];

    protected $casts = [
        'seed' => 'integer',
        'rng_cursor' => 'integer',
        'day' => 'integer',
        'resources' => 'array',
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
