<?php

namespace App\Models;

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
 */
#[Fillable(['raw_text', 'qloo_entity_id', 'qloo_type', 'resolved_name'])]
#[Table('guest_loves')]
#[WithoutTimestamps]
class GuestLove extends Model
{
    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
