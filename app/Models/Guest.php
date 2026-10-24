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
 * @property array{loves: list<int>, text: string, pool: list<array{id: string, name: string, songs: list<string>, genres: list<string>, rank: int}>}|null $blend Qloo's middle ground between all the guest's loves
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'color', 'blend'])]
class Guest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['blend' => 'array'];
    }

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
