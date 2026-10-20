<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'color'])]
class Guest extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<GuestLove, $this>
     */
    public function loves(): HasMany
    {
        return $this->hasMany(GuestLove::class);
    }

    /**
     * @return BelongsToMany<TasteCluster, $this>
     */
    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(TasteCluster::class)->withPivot('weight');
    }
}
