<?php

namespace App\Services\Dj;

use Closure;

/**
 * The add_track rules (handover section 3). The DJ agent can only add a track that is in a stored Qloo pool, and
 * code writes every reason, so each published "because X loves Y" is true by construction. A rejection explains
 * what to fix, so the agent can retry from the message.
 *
 * @phpstan-type Pooled array{id: string, name: string, songs: list<string>, genres?: list<string>, rank: int}
 * @phpstan-type Love array{id: int, guest_id: int, text: string, pool: list<Pooled>}
 * @phpstan-type Accepted array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, love_id: int, rank: int, reason: string, bridge: bool}
 */
class SetValidator
{
    public readonly int $target;

    /** @var list<Accepted> */
    private array $accepted = [];

    /** @var list<string> */
    private array $log = [];

    /** @var array<string, true> Lower-cased things guests typed: those artists are not discoveries. */
    private array $typed = [];

    /** @var array<string, true> Lower-cased artists already played or playing. */
    private array $used = [];

    /**
     * @param  array<int, string>  $guests  Every guest, id => name.
     * @param  array<int, Love>  $loves  Loves with a stored pool, by id.
     * @param  list<string>  $typed  Everything guests typed.
     * @param  list<string>  $usedArtists  Artists already played or playing at this event.
     * @param  (Closure(string, string, list<string>): ?string)|null  $playableTitle  Given artist, title and the
     *                                                                                artist's other songs, the title that has a video, or null.
     */
    public function __construct(
        private array $guests,
        private array $loves,
        array $typed,
        int $target,
        array $usedArtists = [],
        private ?Closure $playableTitle = null,
    ) {
        // Fewer slots than guests with pools would make the reserve rule reject every track.
        $this->target = max($target, count(array_unique(array_column($loves, 'guest_id'))));

        foreach ($typed as $text) {
            $this->typed[mb_strtolower(trim($text))] = true;
        }

        foreach ($usedArtists as $artist) {
            $this->used[mb_strtolower($artist)] = true;
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

        $waiting = array_values(array_filter($this->unservedIds(), fn (int $id) => $id !== $forGuest));
        $free = $this->target - count($this->accepted) - 1;

        if (count($waiting) > $free) {
            return $this->reject('reserve_slots', ($this->target - count($this->accepted)).' slots are left and these guests have no track yet: '
                .$this->names($waiting).'. Add one for them first.');
        }

        if (count(array_filter($this->accepted, fn (array $t) => $t['for_guest_id'] === $forGuest)) >= $this->guestCap()) {
            return $this->reject('guest_cap', "Guest {$forGuest} already has {$this->guestCap()} tracks. Pick a track for someone else.");
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

        $loveGuest = $this->guests[$love['guest_id']];
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
