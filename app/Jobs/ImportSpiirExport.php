<?php

namespace App\Jobs;

use App\Finance\SpiirExportImporter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportSpiirExport implements ShouldQueue
{
    use Queueable;

    public const string FAILED_MESSAGE = 'The import could not be completed.';

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $importId,
        public int $userId,
    ) {}

    public static function cacheKey(string $importId): string
    {
        return "spiir-import:{$importId}";
    }

    /**
     * Run the import off the queued job and mirror progress in the cache so
     * the UI can poll it. The uploaded directory is always removed.
     */
    public function handle(SpiirExportImporter $importer): void
    {
        $key = self::cacheKey($this->importId);
        $directory = "spiir-imports/{$this->importId}";

        try {
            $this->storeStatus($key, ['status' => 'running']);

            $summary = $importer->importExport(
                Storage::disk('local')->path($directory),
                User::query()->find($this->userId),
            );

            GeneratePostingEmbeddingsJob::dispatch()->afterCommit();

            $this->storeStatus($key, ['status' => 'completed', 'summary' => $summary]);
        } catch (Throwable $exception) {
            report($exception);

            $this->storeStatus($key, ['status' => 'failed', 'error' => self::FAILED_MESSAGE]);
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeStatus(string $key, array $attributes): void
    {
        $status = array_merge(Cache::get($key, []), $attributes, ['user_id' => $this->userId]);

        Cache::put($key, $status, now()->addDay());
    }
}
