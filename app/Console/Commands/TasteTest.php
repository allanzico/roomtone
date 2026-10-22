<?php

namespace App\Console\Commands;

use App\Services\Qloo\QlooClient;
use App\Services\Qloo\QlooException;
use App\Services\Taste\TasteAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Taste test: does "three things you love" turn into songs a person recognises as theirs?
 *
 * Per person: Qloo search for each love → the agent picks a candidate or bridges food/places to genres →
 * Qloo artists per love → the agent picks up to three songs with a reason. Writes a scoring sheet to
 * storage/app/taste-test/ (git-ignored: it holds Qloo data, which must never be committed).
 */
#[Signature('roomtone:taste {file=storage/app/taste-test/people.json : JSON list of {"name": "...", "loves": ["...", "...", "..."]}}')]
#[Description('Run the agentic taste test on a list of people and their three loves')]
class TasteTest extends Command
{
    public function handle(QlooClient $qloo, TasteAgent $agent): int
    {
        $file = $this->argument('file');

        if (! is_string($file)) {
            $this->error('Give a people file.');

            return self::FAILURE;
        }

        $path = str_starts_with($file, '/') ? $file : base_path($file);

        if (! File::exists($path)) {
            $this->error("No people file at {$path}.");

            return self::FAILURE;
        }

        /** @var list<array{name: string, loves: list<string>}> $people */
        $people = (array) json_decode(File::get($path), true);

        $results = [];

        foreach ($people as $person) {
            $this->newLine();
            $this->line("<options=bold>{$person['name']}</>: ".implode(' · ', $person['loves']));

            try {
                $results[] = $this->runPerson($qloo, $agent, $person['name'], $person['loves']);
            } catch (RuntimeException $e) {
                $this->error("  Skipped: {$e->getMessage()}");
            }
        }

        $report = $this->report($results);
        $out = storage_path('app/taste-test/report.md');
        File::ensureDirectoryExists(dirname($out));
        File::put($out, $report);

        $this->newLine();
        $this->info("Scoring sheet: {$out}");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $loves
     * @return array{name: string, loves: list<array<string, mixed>>, picks: list<array<string, mixed>>}
     */
    private function runPerson(QlooClient $qloo, TasteAgent $agent, string $name, array $loves): array
    {
        $searched = array_map(fn (string $love) => [
            'love' => $love,
            'candidates' => $this->safely(fn () => $qloo->candidates($love), []),
        ], $loves);

        $resolved = collect($agent->resolve($name, $searched))->keyBy('love');

        $rows = [];

        foreach ($searched as $item) {
            $love = $item['love'];
            $read = $resolved->get($love) ?? ['pick_id' => null, 'location' => null, 'genres' => [], 'how' => 'no answer'];
            $picked = collect($item['candidates'])->firstWhere('id', $read['pick_id']);

            // A picked city is a location, never a signal (Qloo rejects it).
            if ($picked !== null && $picked['kind'] === 'location') {
                $read['location'] ??= $picked['name'];
                $picked = null;
            }

            $tag = null;
            foreach ($read['genres'] as $genre) {
                $tag ??= $this->safely(fn () => $qloo->tags($genre, 1)[0] ?? null, null);
            }

            $artists = [];
            if ($picked !== null || $read['location'] !== null || $tag !== null) {
                $artists = $this->safely(fn () => $qloo->artists(
                    $picked !== null ? [$picked['id']] : [],
                    $picked === null ? $read['location'] : null,
                    $picked === null && $tag !== null ? [$tag['id']] : [],
                    10,
                ), []);
            }

            $as = match (true) {
                $picked !== null => "{$picked['name']} ({$picked['type']})",
                $tag !== null || $read['location'] !== null => trim(($tag['name'] ?? '').($read['location'] !== null ? " in {$read['location']}" : '')),
                default => 'not understood',
            };

            $rows[] = [
                'love' => $love,
                'route' => $picked !== null ? 'match' : ($artists !== [] ? 'bridge' : 'miss'),
                'as' => $as,
                'how' => $read['how'],
                'artists' => $artists,
            ];

            $this->line(sprintf('  %-22s → %s  <fg=gray>(%s)</>', $love, $as, $read['how']));
            $this->line('  '.str_repeat(' ', 24).'<fg=gray>'.implode(', ', array_slice(array_column($artists, 'name'), 0, 5)).'</>');
        }

        $options = array_values(array_filter(array_map(fn (array $row) => [
            'love' => $row['love'],
            'artists' => array_map(fn (array $a) => ['name' => $a['name'], 'songs' => array_slice($a['songs'], 0, 5)], array_slice($row['artists'], 0, 8)),
        ], $rows), fn (array $o) => $o['artists'] !== []));

        $picks = [];
        if ($options !== []) {
            foreach ($agent->pick($name, $options) as $pick) {
                $known = collect($rows)->flatMap(fn (array $row) => $row['artists'])
                    ->contains(fn (array $a) => strcasecmp($a['name'], $pick['artist']) === 0
                        && collect($a['songs'])->contains(fn (string $s) => strcasecmp($s, $pick['song']) === 0));

                $picks[] = [...$pick, 'invented' => ! $known];
                $flag = $known ? '' : ' <fg=red>(not in Qloo list)</>';
                $this->line("  ♪ {$pick['song']}, {$pick['artist']}, because {$name} loves {$pick['love']}{$flag}");
            }
        }

        return ['name' => $name, 'loves' => $rows, 'picks' => $picks];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (QlooException $e) {
            $this->warn("  Qloo: {$e->getMessage()}");

            return $fallback;
        }
    }

    /**
     * @param  list<array{name: string, loves: list<array<string, mixed>>, picks: list<array<string, mixed>>}>  $results
     */
    private function report(array $results): string
    {
        $md = '# Taste test, '.now()->format('j M Y H:i')."\n\n";
        $md .= "Show each person their three songs. Tick the ones they'd call \"that's me\".\n";
        $md .= "Pass: 7 of 10 people say yes to at least one, including people whose loves are foods or places.\n";

        $routes = ['match' => 0, 'bridge' => 0, 'miss' => 0];
        $byArtist = [];

        foreach ($results as $result) {
            $md .= "\n## {$result['name']}\n\n| Love | Read as | How | Top artists |\n|---|---|---|---|\n";

            foreach ($result['loves'] as $row) {
                $routes[$row['route']]++;
                $names = array_slice(array_column($row['artists'], 'name'), 0, 5);
                foreach ($names as $artist) {
                    $byArtist[$artist][$result['name']] = true;
                }
                $md .= "| {$row['love']} | {$row['as']} | {$row['how']} | ".implode(', ', $names)." |\n";
            }

            $md .= "\n";
            foreach ($result['picks'] as $pick) {
                $warn = $pick['invented'] ? ' ⚠ not in Qloo list' : '';
                $md .= "- [ ] {$pick['song']}, {$pick['artist']}, because {$result['name']} loves {$pick['love']}{$warn}\n";
            }
            if ($result['picks'] === []) {
                $md .= "- No songs: nothing resolved.\n";
            }
        }

        $total = max(1, array_sum($routes));
        $md .= "\n## Summary\n\n";
        $md .= sprintf("- Loves understood: %d%% (%d direct Qloo matches, %d bridged by the agent, %d missed)\n",
            round(100 * ($routes['match'] + $routes['bridge']) / $total), $routes['match'], $routes['bridge'], $routes['miss']);

        $shared = array_filter($byArtist, fn (array $who) => count($who) >= 2);
        uasort($shared, fn (array $a, array $b) => count($b) <=> count($a));
        $md .= '- Artists shared by 2+ people: '.($shared === [] ? 'none' : implode(', ', array_map(
            fn (string $artist, array $who) => "{$artist} (".implode(', ', array_keys($who)).')',
            array_keys($shared), $shared,
        )))."\n";

        return $md;
    }
}
