<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $artist
 * @property string $title
 * @property string|null $youtube_video_id
 * @property int|null $bpm
 * @property int $energy
 * @property string|null $qloo_artist_id
 * @property list<string>|null $genres Qloo's genres for the artist when last picked; null when unknown
 * @property CarbonImmutable|null $youtube_checked_at
 */
#[Fillable(['artist', 'title', 'youtube_video_id', 'bpm', 'energy', 'qloo_artist_id', 'genres', 'youtube_checked_at'])]
#[WithoutTimestamps]
class Track extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['youtube_checked_at' => 'immutable_datetime', 'genres' => 'array'];
    }

    /**
     * @return HasMany<SetItem, $this>
     */
    public function setItems(): HasMany
    {
        return $this->hasMany(SetItem::class);
    }
}
