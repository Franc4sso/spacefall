<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declarative event content. All gameplay meaning lives in the data
 * (`requires`, `choices` → `outcomes` → `effects`); the engine never branches
 * on a specific event key.
 *
 * @property string      $key
 * @property string      $title
 * @property string      $body
 * @property string|null $speaker
 * @property int         $base_weight
 * @property int         $cooldown_days
 * @property bool        $is_filler
 * @property array|null  $requires
 * @property array        $choices
 */
class Event extends Model
{
    protected $fillable = [
        'key',
        'title',
        'body',
        'speaker',
        'base_weight',
        'cooldown_days',
        'is_filler',
        'requires',
        'choices',
    ];

    protected $casts = [
        'base_weight' => 'integer',
        'cooldown_days' => 'integer',
        'is_filler' => 'boolean',
        'requires' => 'array',
        'choices' => 'array',
    ];
}
