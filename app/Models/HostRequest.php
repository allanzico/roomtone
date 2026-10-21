<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property string $text
 * @property RequestStatus $status
 * @property array{genre: string|null, mood: string|null, summary: string, tags: list<string>}|null $interpreted
 * @property string|null $reply
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 */
#[Fillable(['text', 'status', 'interpreted', 'reply'])]
class HostRequest extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => RequestStatus::class, 'interpreted' => 'array'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
