<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $event_id
 * @property string $label
 * @property string $color
 * @property array{x: float, y: float, z: float} $position
 */
#[Fillable(['label', 'color', 'position'])]
#[WithoutTimestamps]
class TasteCluster extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsToMany<Guest, $this>
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class)->withPivot('weight');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'array',
        ];
    }
}
