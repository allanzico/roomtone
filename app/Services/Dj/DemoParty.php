<?php

namespace App\Services\Dj;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\GuestLove;
use App\Models\SetItem;
use App\Models\TasteCluster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The demo party. `roomtone:demo:build` builds the template (event code DEMO) at deploy time from live Qloo calls
 * into this server's database; each "Try a demo party" click gets its own copy, made from the database alone, so
 * copies keep working after the Qloo key is turned off.
 */
class DemoParty
{
    public const CODE = 'DEMO';

    /** Fictional guests and the energy plan, in minutes from when the party opens. Never Qloo data. */
    public const FILE = 'database/parties/demo.json';

    /**
     * @return array{name: string, occasion: string, plan: list<array{after_minutes: int, energy: int, label: string}>, guests: list<array{name: string, loves: list<string>}>}
     */
    public static function file(): array
    {
        return json_decode(File::get(base_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The plan as clock times from $now, so a visitor arrives just after dinner and sees the peak and the wind down.
     *
     * @param  list<array{after_minutes: int, energy: int, label: string}>  $plan
     * @return list<array{at: string, energy: int, label: string}>
     */
    public static function planFrom(array $plan, CarbonImmutable $now): array
    {
        return array_map(fn (array $p) => [
            'at' => $now->addMinutes($p['after_minutes'])->format('H:i'),
            'energy' => $p['energy'],
            'label' => $p['label'],
        ], $plan);
    }

    /**
     * A visitor's own copy of the demo: guests, loves with their stored pools, islands and the set. No Qloo, model
     * or YouTube calls. The first track starts as now playing.
     */
    public function copy(): Event
    {
        $template = Event::query()->where('code', self::CODE)->firstOrFail();

        return DB::transaction(function () use ($template) {
            do {
                $code = Str::upper(Str::random(6));
            } while (Event::where('code', $code)->exists());

            $event = Event::create([
                'code' => $code,
                'name' => $template->name,
                'occasion' => $template->occasion,
                'energy_plan' => self::planFrom(self::file()['plan'], CarbonImmutable::now()),
                'host_token' => Str::random(40),
                'demo' => true,
            ]);

            $clusters = [];
            foreach ($template->tasteClusters()->get() as $cluster) {
                $clusters[$cluster->id] = $event->tasteClusters()->create($cluster->only(['label', 'color', 'position']))->id;
            }

            $guests = [];
            $loves = [];
            foreach ($template->guests()->with(['loves', 'clusters'])->orderBy('id')->get() as $guest) {
                $copy = $event->guests()->create($guest->only(['name', 'color']));
                $guests[$guest->id] = $copy->id;

                foreach ($guest->loves as $love) {
                    $loves[$love->id] = $copy->loves()->create($love->only(['raw_text', 'qloo_entity_id', 'qloo_type', 'resolved_name', 'status', 'pool']))->id;
                }

                if ($guest->blend !== null) {
                    $copy->update(['blend' => [...$guest->blend, 'loves' => array_values(array_filter(array_map(fn (int $id) => $loves[$id] ?? null, $guest->blend['loves'])))]]);
                }

                $copy->clusters()->sync($guest->clusters->mapWithKeys(fn (TasteCluster $c) => [
                    $clusters[$c->id] => ['weight' => $c->getRelationValue('pivot')->weight],
                ])->all());
            }

            $items = $template->setItems()->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])->orderBy('position')->get();
            foreach ($items->values() as $i => $item) {
                $event->setItems()->create([
                    'track_id' => $item->track_id,
                    'position' => $i + 1,
                    'status' => $i === 0 ? SetItemStatus::Playing : SetItemStatus::Queued,
                    'reason_guest_id' => $item->reason_guest_id === null ? null : ($guests[$item->reason_guest_id] ?? null),
                    'reason_text' => $item->reason_text,
                    'reason_love_id' => $item->reason_love_id === null ? null : ($loves[$item->reason_love_id] ?? null),
                    'reason_rank' => $item->reason_rank,
                ]);
            }

            return $event;
        });
    }

    /**
     * Whether a freshly built template is good enough to replace the live demo.
     *
     * @return array{ok: bool, guests: int, guests_with_pools: int, tracks: int, tracks_with_video: int}
     */
    public static function readiness(Event $event): array
    {
        $guests = $event->guests()->count();
        $withPools = GuestLove::query()->whereIn('guest_id', $event->guests()->select('id'))
            ->where('status', 'resolved')->distinct()->count('guest_id');
        $items = $event->setItems()->with('track')->get();
        $withVideo = $items->filter(fn (SetItem $i) => $i->track->youtube_video_id !== null)->count();

        return [
            'ok' => $guests > 0 && $withPools >= 0.75 * $guests && $items->count() >= 10 && $withVideo >= 0.8 * $items->count(),
            'guests' => $guests,
            'guests_with_pools' => $withPools,
            'tracks' => $items->count(),
            'tracks_with_video' => $withVideo,
        ];
    }
}
