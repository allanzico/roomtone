<?php

namespace App\Models;

use App\Services\Dj\EnergyArc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $occasion
 * @property array<mixed>|null $energy_plan Setup's {timeline, points, tz?, minutes?}, or an older list of clock points (see EnergyArc::plan)
 * @property string $host_token
 * @property Carbon|null $ended_at When the host ended the party; nothing new is read or built after that
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'occasion', 'energy_plan', 'host_token', 'ended_at'])]
#[Hidden(['host_token'])]
class Event extends Model
{
    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /**
     * @return HasMany<TasteCluster, $this>
     */
    public function tasteClusters(): HasMany
    {
        return $this->hasMany(TasteCluster::class);
    }

    /**
     * @return HasMany<HostRequest, $this>
     */
    public function hostRequests(): HasMany
    {
        return $this->hasMany(HostRequest::class);
    }

    /**
     * @return HasMany<SetItem, $this>
     */
    public function setItems(): HasMany
    {
        return $this->hasMany(SetItem::class);
    }

    /**
     * Whether a key from the host link opens host controls (requests, playback sync).
     */
    public function isHostKey(mixed $key): bool
    {
        return is_string($key) && $key !== '' && hash_equals($this->host_token, $key);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * The energy the party starts at (1–5), from the first step of the host's plan.
     */
    public function startingEnergy(): int
    {
        return (int) (EnergyArc::plan($this)['points'][0]['energy'] ?? 3);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'energy_plan' => 'array',
            'ended_at' => 'datetime',
        ];
    }
}
