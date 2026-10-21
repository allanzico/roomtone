<?php

namespace App\Models;

use App\Enums\LoveStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $guest_id
 * @property string $raw_text
 * @property string|null $qloo_entity_id
 * @property string|null $qloo_type
 * @property string|null $resolved_name
 * @property LoveStatus $status
 * @property list<array{id: string, name: string, songs: list<string>, rank: int}>|null $pool Qloo's ranked artists for this love
 * @property-read Guest $guest
 */
#[Fillable(['raw_text', 'qloo_entity_id', 'qloo_type', 'resolved_name', 'status', 'pool'])]
#[Table('guest_loves')]
#[WithoutTimestamps]
class GuestLove extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => LoveStatus::class, 'pool' => 'array'];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
