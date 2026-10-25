<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Not RefreshDatabase: VACUUM INTO cannot run inside its wrapping transaction. Each test gets its own SQLite file.
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/roomtone-backups-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->database = $this->dir.'/live.sqlite';
    touch($this->database);

    config(['database.connections.sqlite.database' => $this->database]);
    DB::purge('sqlite');
    DB::statement('create table events (code text, name text)');
    DB::table('events')->insert(['code' => 'BACK01', 'name' => 'Rooftop']);
});

afterEach(function () {
    DB::purge('sqlite');
    File::deleteDirectory($this->dir);
});

test('a backup is a working copy of the database, next to it by default', function () {
    $this->artisan('roomtone:backup')->assertSuccessful();

    $files = File::glob($this->dir.'/backups/roomtone-*.sqlite');
    expect($files)->toHaveCount(1);

    $copy = new PDO('sqlite:'.$files[0]);
    expect($copy->query("select name from events where code = 'BACK01'")->fetchColumn())->toBe('Rooftop');
});

test('only the newest backups are kept', function () {
    File::ensureDirectoryExists($this->dir.'/backups');
    foreach (range(1, 8) as $day) {
        File::put($this->dir.sprintf('/backups/roomtone-2026-10-%02d-000000.sqlite', $day), 'old');
    }

    $this->travelTo(now()->setDate(2026, 10, 22));
    $this->artisan('roomtone:backup', ['--keep' => 7])->assertSuccessful();

    $kept = collect(File::glob($this->dir.'/backups/roomtone-*.sqlite'))->map(fn ($f) => basename($f))->sort()->values()->all();
    expect($kept)->toHaveCount(7)
        ->and($kept[0])->toBe('roomtone-2026-10-03-000000.sqlite')
        ->and(end($kept))->toStartWith('roomtone-2026-10-22-');
});
