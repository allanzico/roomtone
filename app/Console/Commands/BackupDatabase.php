<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A consistent copy of the SQLite database (VACUUM INTO is safe while the app writes), kept next to it in
 * backups/. Scheduled daily; the newest few are kept.
 */
#[Signature('roomtone:backup {--keep=7 : Backups to keep} {--dir= : Where to write them (default: backups/ next to the database)}')]
#[Description('Back up the SQLite database and keep the newest few copies')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Only SQLite databases are backed up by this command.');

            return self::FAILURE;
        }

        $database = (string) config('database.connections.sqlite.database');
        $dir = is_string($this->option('dir')) && $this->option('dir') !== ''
            ? $this->option('dir')
            : dirname($database === ':memory:' ? database_path('database.sqlite') : $database).'/backups';

        File::ensureDirectoryExists($dir);
        $file = $dir.'/roomtone-'.now()->format('Y-m-d-His').'.sqlite';

        DB::statement('VACUUM INTO ?', [$file]);

        $keep = max(1, (int) $this->option('keep'));
        $old = collect(File::glob($dir.'/roomtone-*.sqlite'))->sort()->reverse()->slice($keep);
        $old->each(fn (string $path) => File::delete($path));

        $this->info("Backed up to {$file}".($old->isEmpty() ? '' : "; removed {$old->count()} older"));

        return self::SUCCESS;
    }
}
