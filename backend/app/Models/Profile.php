<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The persistent player profile — cross-run state. One per `handle`.
 *
 * @property int    $id
 * @property string $handle
 * @property int    $research_points
 * @property array  $unlocks
 * @property array  $flags
 */
class Profile extends Model
{
    protected $fillable = ['handle', 'research_points', 'unlocks', 'flags'];

    protected $casts = [
        'research_points' => 'integer',
        'unlocks' => 'array',
        'flags' => 'array',
    ];

    protected $attributes = [
        'research_points' => 0,
        'unlocks' => '[]',
        'flags' => '{}',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** Resolve-or-create a profile by handle (no auth; out of scope). */
    public static function resolve(string $handle): self
    {
        return static::firstOrCreate(['handle' => $handle]);
    }

    public function hasUnlock(string $key): bool
    {
        return in_array($key, $this->unlocks ?? [], true);
    }
}
