<?php

namespace App\Jobs\Import;

use App\Models\ImportBatch;
use App\Services\Import\ColumnMapper;
use App\Services\Import\CommercialImportRunner;
use App\Services\Import\ImportFileParser;
use App\Services\Import\OnecFileIntake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** One queued run, sequential chunks, one DB transaction. No delayed finalizer race. */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 60;

    public function __construct(public readonly int $batchId) {}

    public function handle(ImportFileParser $parser, ColumnMapper $mapper): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        if ($batch->status === 'done') {
            return;
        }
        try {
            if ($batch->type === 'prices_only') {
                // Upload timestamps do not establish export chronology. Require identical bytes
                // to the current stable FTP input, and use its authoritative filesystem mtime.
                $file = app(OnecFileIntake::class)->stage();
                if ($file['status'] !== 'staged') {
                    throw new \RuntimeException('Current FTP input is required for manual commercial import.');
                }
                $uploaded = Storage::disk('public')->path($batch->filepath);
                if (! is_file($uploaded) || hash_file('sha256', $uploaded) !== $file['hash']) {
                    @unlink($file['path']);
                    throw new \RuntimeException('Uploaded file differs from current FTP input; chronological review required.');
                }
                app(CommercialImportRunner::class)->run($file, [], $batch);

                return;
            }
            $rows = $mapper->map($parser->parse($batch->filepath), $batch->column_map ?? []);
            if ($rows === []) {
                throw new \RuntimeException('Empty import.');
            }
            DB::transaction(function () use ($batch, $rows) {
                app(CommercialImportRunner::class)->lock();
                $batch->refresh();
                if ($batch->status === 'done') {
                    return;
                }
                $chunks = array_chunk($rows, 100);
                $batch->update(['status' => 'processing', 'started_at' => now(), 'finished_at' => null,
                    'total_rows' => count($rows), 'total_chunks' => count($chunks), 'processed_chunks' => 0]);
                foreach ($chunks as $index => $chunk) {
                    (new ImportChunkJob($batch->id, $chunk, $index, count($chunks)))->handle();
                }
                (new FinalizeImportJob($batch->id))->handle();
            });
        } catch (\Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $changed = ImportBatch::where('id', $this->batchId)->where('status', '!=', 'done')
            ->update(['status' => 'failed', 'finished_at' => now()]);
        if ($changed) {
            Cache::put("import_progress_{$this->batchId}", ['status' => 'failed', 'error' => $e->getMessage()], 3600);
        }
    }
}
