<?php

namespace App\Models;

use App\Enums\SetItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property int $track_id
 * @property int $position
 * @property SetItemStatus $status
 * @property int|null $reason_guest_id
 * @property string $reason_text
 * @property int|null $reason_love_id
 * @property int|null $reason_rank
 * @property int|null $host_request_id
 * @property-read Track $track
 */
#[Fillable(['track_id', 'position', 'status', 'reason_guest_id', 'reason_text', 'reason_love_id', 'reason_rank', 'host_request_id'])]
#[WithoutTimestamps]
class SetItem extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function reasonGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'reason_guest_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SetItemStatus::class,
        ];
    }
}
