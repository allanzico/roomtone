<?php

namespace App\Services\Dj;

use Closure;

/**
 * The add_track rules (handover section 3). The DJ agent can only add a track that is in a stored Qloo pool, and
 * code writes every reason, so each published "because X loves Y" is true by construction. A rejection explains
 * what to fix, so the agent can retry from the message.
 *
 * @phpstan-type Pooled array{id: string, name: string, songs: list<string>, genres?: list<string>, rank: int}
 * @phpstan-type Love array{id: int, guest_id: int, text: string, pool: list<Pooled>, blend?: true, mood?: string}
 * @phpstan-type Accepted array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, love_id: int, rank: int, reason: string, bridge: bool, genres: list<string>|null}
 */
class SetValidator
{
    /**
     * A guest's blend (Qloo's middle ground between all their loves) is offered to the agent as a love with this id
     * plus the guest's id. It has no guest_loves row.
     */
    public const BLEND_BASE = 1_000_000;

    /** Qloo's calmer and more energetic artists for a guest's loves (the plan's low and high slots) are offered as loves with these ids plus the guest's id. */
    public const CALM_BASE = 2_000_000;

    public const ENERGETIC_BASE = 3_000_000;

    public static function isMood(int $loveId): bool
    {
        return $loveId >= self::CALM_BASE;
    }

    public readonly int $target;

    /** @var list<Accepted> */
    private array $accepted = [];

    /** @var list<string> */
    private array $log = [];

    /** @var array<string, true> Lower-cased things guests typed: those artists are not discoveries. */
    private array $typed = [];

    /** @var array<string, true> Lower-cased artists already played or playing. */
    private array $used = [];

    /** @var array{calm: int, energetic: int} Minimum number of tracks from Qloo's matching mood pools. */
    private array $moodQuota = ['calm' => 0, 'energetic' => 0];

    /**
     * @param  array<int, string>  $guests  Every guest, id => name.
     * @param  array<int, Love>  $loves  Loves with a stored pool, by id.
     * @param  list<string>  $typed  Everything guests typed.
     * @param  list<string>  $usedArtists  Artists already played or playing at this event.
     * @param  list<int>  $energyTargets  The host plan's target energy for each upcoming track.
     * @param  (Closure(string, string, list<string>): ?string)|null  $playableTitle  Given artist, title and the
     *                                                                                artist's other songs, the title that has a video, or null.
     * @param  (Closure(string, string): ?array{energy: int, bpm: int})|null  $storedMeasurements
     */
    public function __construct(
        private array $guests,
        private array $loves,
        array $typed,
        int $target,
        array $usedArtists = [],
        private ?Closure $playableTitle = null,
        array $energyTargets = [],
        private ?Closure $storedMeasurements = null,
    ) {
        // Fewer slots than guests with pools would make the reserve rule reject every track.
        $this->target = max($target, count(array_unique(array_column($loves, 'guest_id'))));

        foreach ($typed as $text) {
            $this->typed[mb_strtolower(trim($text))] = true;
        }

        foreach ($usedArtists as $artist) {
            $this->used[mb_strtolower($artist)] = true;
        }

        foreach (['calm' => fn (int $e) => $e <= 2, 'energetic' => fn (int $e) => $e >= 4] as $mood => $matches) {
            $demand = count(array_filter($energyTargets, $matches));
            $pool = array_filter($this->loves, fn (array $love) => ($love['mood'] ?? null) === $mood);
            $artists = [];
            foreach ($pool as $love) {
                foreach ($love['pool'] as $artist) {
                    $key = mb_strtolower($artist['name']);
                    if (! isset($this->used[$key]) && ! isset($this->typed[$key]) && $artist['songs'] !== []) {
                        $artists[$key] = true;
                    }
                }
            }
            $guestsWithoutPool = count(array_diff(
                array_unique(array_column($this->loves, 'guest_id')),
                array_unique(array_column($pool, 'guest_id')),
            ));
            $this->moodQuota[$mood] = min((int) ceil($demand * 0.8), count($artists), max(0, $this->target - $guestsWithoutPool));
        }
    }

    /**
     * Try to add one track.
     *
     * @param  array<string, mixed>  $args  artist, title, for_guest (id), love (id), energy (1-5), bpm.
     * @return array<string, mixed> What the agent sees: accepted, or the error to fix.
     */
    public function add(array $args): array
    {
        $artist = $args['artist'] ?? null;
        $title = $args['title'] ?? null;
        $forGuest = $args['for_guest'] ?? null;
        $loveId = $args['love'] ?? null;
        $energy = $args['energy'] ?? null;
        $bpm = $args['bpm'] ?? null;

        if (! is_string($artist) || ! is_string($title) || ! is_int($forGuest) || ! is_int($loveId) || ! is_int($energy) || ! is_int($bpm)) {
            return $this->reject('bad_args', 'Give artist and title as text, and for_guest, love, energy and bpm as whole numbers.');
        }

        if (count($this->accepted) >= $this->target) {
            return $this->reject('set_full', 'The set is full. Reply done.');
        }

        $love = $this->loves[$loveId] ?? null;

        if ($love === null) {
            return $this->reject('not_stored', "There are no stored Qloo results for love {$loveId}. Use a love id from the list.");
        }

        $match = $this->find($love['pool'], $artist);

        if ($match === null) {
            return $this->reject('not_in_results', "{$artist} is not in Qloo's results for love {$loveId}. Its top results: "
                .implode(', ', array_column(array_slice($love['pool'], 0, 6), 'name')).'.');
        }

        $song = $this->song($match['songs'], $title);

        if ($song === null) {
            return $this->reject('title_not_notable', "\"{$title}\" is not one of {$match['name']}'s notable songs: "
                .($match['songs'] === [] ? 'none listed, pick another artist' : implode('; ', $match['songs'])).'.');
        }

        if (! isset($this->guests[$forGuest])) {
            return $this->reject('bad_guest', "There is no guest {$forGuest}.");
        }

        $bridgeLove = null;
        if ($forGuest !== $love['guest_id']) {
            $bridgeLove = $this->bridgeLove($forGuest, $match['name']);

            if ($bridgeLove === null) {
                return $this->reject('not_a_bridge', "{$match['name']} is not in any of guest {$forGuest}'s results, so it is not for them. "
                    ."Set for_guest to {$love['guest_id']}, or pick an artist from guest {$forGuest}'s own results.");
            }
        }

        $key = mb_strtolower($match['name']);

        if (isset($this->used[$key]) || collect($this->accepted)->contains(fn (array $t) => mb_strtolower($t['artist']) === $key)) {
            return $this->reject('duplicate_artist', "{$match['name']} is already in the set. Pick a different artist.");
        }

        if (isset($this->typed[$key])) {
            return $this->reject('typed_by_guest', "A guest typed {$match['name']} themselves. Pick a discovery instead.");
        }

        $loveGuest = $this->guests[$love['guest_id']];
        $waiting = array_values(array_filter($this->unservedIds(), fn (int $id) => $id !== $forGuest));
        $free = $this->target - count($this->accepted) - 1;

        if (count($waiting) > $free) {
            return $this->reject('reserve_slots', ($this->target - count($this->accepted)).' slots are left and these guests have no track yet: '
                .$this->names($waiting).'. Add one for them first.');
        }

        if (count(array_filter($this->accepted, fn (array $t) => $t['for_guest_id'] === $forGuest)) >= $this->guestCap()) {
            return $this->reject('guest_cap', "Guest {$forGuest} already has {$this->guestCap()} tracks. Pick a track for someone else.");
        }

        // A guest with a blend gets their first track from it: the middle ground of their loves, not one love alone.
        $blendId = self::BLEND_BASE + $love['guest_id'];

        if ($forGuest === $love['guest_id'] && $loveId !== $blendId && isset($this->loves[$blendId])
            && ! isset($love['mood'])
            && ! collect($this->accepted)->contains(fn (array $t) => $t['love_id'] === $blendId && $t['for_guest_id'] === $forGuest)) {
            return $this->reject('blend_first', "{$loveGuest}'s first track must come from love {$blendId}, the middle ground of all their loves ({$this->loves[$blendId]['text']}).");
        }

        // Round the guest's loves: one love can't get another of their tracks while another of theirs has fewer.
        // Bridges (a track for another guest) don't count.
        $behind = $forGuest === $love['guest_id'] && ! self::isMood($loveId) ? $this->lovesBehind($loveId) : [];

        if ($behind !== []) {
            return $this->reject('love_spread', "{$loveGuest} already has a track from love {$loveId} ({$love['text']}). Use their "
                .'other loves first: '.implode(', ', array_map(fn (array $l) => "love {$l['id']} ({$l['text']})", $behind)).'.');
        }

        if ($energy < 1 || $energy > 5 || $bpm < 40 || $bpm > 220) {
            return $this->reject('bad_range', 'Energy must be 1 to 5 and bpm 40 to 220.');
        }

        if ($this->playableTitle !== null) {
            $others = array_values(array_filter($match['songs'], fn (string $s) => $s !== $song));
            $playable = ($this->playableTitle)($match['name'], $song, $others);

            if ($playable === null) {
                return $this->reject('no_video', "No playable video was found for {$match['name']}. Pick a different artist.");
            }

            $song = $playable;
        }

        // A reused song has one stored energy and tempo across rooms. The agent cannot relabel that same recording
        // as calm in one party and peak in another just to fit the current plan.
        $stored = $this->storedMeasurements === null ? null : ($this->storedMeasurements)($match['name'], $song);
        if ($stored !== null) {
            $energy = (int) $stored['energy'];
            $bpm = (int) $stored['bpm'];
        }

        $mood = $love['mood'] ?? null;
        if (($mood === 'energetic' && $energy < 4) || ($mood === 'calm' && $energy > 2)) {
            return $this->reject('mood_energy', "A {$mood} list needs a song with matching energy. Pick another song or estimate its energy honestly.");
        }

        foreach ($this->moodQuota as $neededMood => $quota) {
            $have = count(array_filter($this->accepted, fn (array $t) => ($this->loves[$t['love_id']]['mood'] ?? null) === $neededMood));
            $remaining = $this->target - count($this->accepted) - 1;
            if ($mood !== $neededMood && $quota - $have > $remaining) {
                return $this->reject('mood_quota', "The host's energy plan still needs ".($quota - $have)." tracks from {$neededMood} lists. Pick from one of those lists now.");
            }
        }

        // Name a second guest whose taste shares this artist: the one the agent chose, or another guest's love that
        // ranks it in Qloo's top 5. One extra name keeps the reason short enough for the screen.
        $shared = $bridgeLove ?? $this->sharedLove($match['name'], [$forGuest, $love['guest_id']]);
        $reason = match (true) {
            $bridgeLove !== null => "because {$this->guests[$forGuest]} loves {$bridgeLove['text']} and {$loveGuest} loves {$love['text']}",
            $shared !== null => "because {$loveGuest} loves {$love['text']} and {$this->guests[$shared['guest_id']]} loves {$shared['text']}",
            default => "because {$loveGuest} loves {$love['text']}",
        };

        $this->accepted[] = [
            'artist' => $match['name'],
            'title' => $song,
            'qloo_artist_id' => $match['id'],
            'energy' => $energy,
            'bpm' => $bpm,
            'for_guest_id' => $forGuest,
            'love_id' => $loveId,
            'rank' => $match['rank'],
            'reason' => $reason,
            // The reason names a second guest.
            'bridge' => $shared !== null,
            'genres' => $match['genres'] ?? null,
        ];
        $this->log[] = 'ok';

        return [
            'accepted' => true,
            'title' => $song,
            'tracks_so_far' => count($this->accepted),
            'target' => $this->target,
            'guests_without_a_track' => $this->unservedIds(),
        ];
    }

    /**
     * Stop requiring a guest's first track from their blend: when it has given nothing playable after several
     * rounds, their own loves must be allowed to serve them.
     */
    public function dropBlend(int $guestId): void
    {
        unset($this->loves[self::BLEND_BASE + $guestId]);
    }

    /**
     * @return list<Accepted>
     */
    public function accepted(): array
    {
        return $this->accepted;
    }

    /**
     * Rejection codes and "ok", in order: how hard the agent had to work.
     *
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    public function complete(): bool
    {
        return count($this->accepted) >= $this->target && $this->unservedIds() === [];
    }

    /**
     * Guests who have at least one stored pool but no track yet. Guests without pools are not counted, or an
     * unreadable guest would deadlock the set.
     *
     * @return list<int>
     */
    public function unservedIds(): array
    {
        $withPools = array_values(array_unique(array_column($this->loves, 'guest_id')));
        $served = array_column($this->accepted, 'for_guest_id');

        return array_values(array_diff($withPools, $served));
    }

    /**
     * The same guest's other loves that have fewer of the guest's own tracks than this one, so this love must wait.
     *
     * @return list<Love>
     */
    private function lovesBehind(int $loveId): array
    {
        $owner = $this->loves[$loveId]['guest_id'];
        $count = fn (int $id) => count(array_filter($this->accepted, fn (array $t) => $t['love_id'] === $id && $t['for_guest_id'] === $owner));
        $mine = $count($loveId);

        return array_values(array_filter(
            $this->loves,
            fn (array $other) => $other['guest_id'] === $owner && $other['id'] !== $loveId && ! self::isMood($other['id']) && $count($other['id']) < $mine,
        ));
    }

    public function guestCap(): int
    {
        $guests = max(1, count(array_unique(array_column($this->loves, 'guest_id'))));

        return (int) ceil($this->target / $guests) + 1;
    }

    /**
     * @param  list<int>  $ids
     */
    private function names(array $ids): string
    {
        return implode(', ', array_map(fn (int $id) => "{$this->guests[$id]} (guest {$id})", $ids));
    }

    /**
     * @param  list<Pooled>  $pool
     * @return Pooled|null
     */
    private function find(array $pool, string $artist): ?array
    {
        $artist = mb_strtolower(trim($artist));

        foreach ($pool as $entry) {
            if (mb_strtolower($entry['name']) === $artist) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $songs
     */
    private function song(array $songs, string $title): ?string
    {
        $title = mb_strtolower(trim($title));

        foreach ($songs as $song) {
            if (mb_strtolower($song) === $title) {
                return $song;
            }
        }

        return null;
    }

    /**
     * The for_guest's own love whose pool also has this artist: what makes a track a bridge.
     *
     * @return Love|null
     */
    private function bridgeLove(int $guestId, string $artist): ?array
    {
        foreach ($this->loves as $love) {
            if ($love['guest_id'] === $guestId && $this->find($love['pool'], $artist) !== null) {
                return $love;
            }
        }

        return null;
    }

    /**
     * Another guest's love (not one of $exclude) whose pool ranks this artist in the top 5; the strongest one.
     *
     * @param  list<int>  $exclude  Guest ids.
     * @return Love|null
     */
    private function sharedLove(string $artist, array $exclude): ?array
    {
        $best = null;
        $bestRank = 6;

        foreach ($this->loves as $love) {
            $entry = in_array($love['guest_id'], $exclude, true) ? null : $this->find($love['pool'], $artist);

            if ($entry !== null && $entry['rank'] < $bestRank) {
                [$best, $bestRank] = [$love, $entry['rank']];
            }
        }

        return $best;
    }

    /**
     * @return array{accepted: false, error: string}
     */
    private function reject(string $code, string $message): array
    {
        $this->log[] = $code;

        return ['accepted' => false, 'error' => $message];
    }
}
