<?php

namespace App\Console\Commands;

use App\Models\DietPlan;
use App\Models\StoredFile;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizeFiles extends Command
{
    protected $signature = 'gordeh:privatize-files {--dry-run : List what would be moved without touching anything}';

    protected $description = 'Move uploaded files (stored files, diet plans) from the public disk to the private disk';

    public function handle(): int
    {
        $paths = StoredFile::pluck('url')
            ->merge(DietPlan::pluck('file_url'))
            ->map(fn ($value) => PrivateFiles::path($value))
            ->filter()
            ->unique();

        $public = Storage::disk(PrivateFiles::LEGACY_DISK);
        $private = Storage::disk(PrivateFiles::DISK);
        $moved = 0;

        foreach ($paths as $path) {
            if (! $public->exists($path)) {
                continue;
            }

            $this->line(($this->option('dry-run') ? '[dry-run] ' : '').$path);
            if ($this->option('dry-run')) {
                $moved++;
                continue;
            }

            if (! $private->exists($path)) {
                $private->writeStream($path, $public->readStream($path));
            }
            $public->delete($path);
            $moved++;
        }

        $this->info("{$moved} file(s) ".($this->option('dry-run') ? 'would be ' : '').'moved to the private disk.');

        return self::SUCCESS;
    }
}
