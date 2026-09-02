<?php

namespace App\Services\Import;

use App\Jobs\Import\FinalizeImportJob;
use App\Jobs\Import\ImportChunkJob;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/** One orchestrator for uploaded prices_only files and onec:sync. */
class CommercialImportRunner
{
    public function run(array $file, array $options = [], ?ImportBatch $batch = null): array
    {
        if (hash_file('sha256', $file['path']) !== $file['hash']) {
            throw new \RuntimeException('Staged file hash changed; nothing applied.');
        }
        if (! ($options['dry_run'] ?? false) && config('onec.order_source') !== 'ftp_mtime') {
            throw new \RuntimeException('Export chronology is unconfirmed. Review FTP timestamp order before setting ONEC_ORDER_SOURCE=ftp_mtime.');
        }
        if (! ($options['dry_run'] ?? false) && ! $batch) {
            $completed = DB::table('onec_files')->where('sha256', $file['hash'])->whereNotNull('completed_at')->first();
            if ($completed) {
                return ['status' => 'duplicate', 'total_rows' => $completed->total_rows, 'updated' => 0];
            }
        }
        $raw = app(ImportFileParser::class)->parseCommercial($file['path']);
        if (hash_file('sha256', $file['path']) !== $file['hash']) {
            throw new \RuntimeException('Staged file changed during validation.');
        }
        $rows = app(ColumnMapper::class)->map($raw, [
            'sku' => 'Номенклатура.Код', 'price' => 'Розничная цена', 'quantity' => 'Остаток на складе',
        ]);
        $selected = array_values(array_filter($rows, fn ($row) => ! isset($options['sku']) || $row['sku'] === $options['sku']));
        if (isset($options['limit'])) {
            $selected = array_slice($selected, 0, $options['limit']);
        }
        if ($selected === []) {
            throw new \RuntimeException('No source rows match the requested SKU/limit.');
        }
        if (! ($options['dry_run'] ?? false) && ! $batch) {
            $known = DB::table('onec_files')->where('sha256', $file['hash'])->first();
            if ($known && DB::table('import_commercial_rows')->where('onec_file_id', $known->id)
                ->whereIn('sku', array_column($selected, 'sku'))->count() === count($selected)) {
                return ['status' => 'duplicate', 'total_rows' => count($rows), 'selected_rows' => count($selected), 'updated' => 0];
            }
        }
        if ($options['dry_run'] ?? false) {
            return DB::transaction(function () use ($file, $selected, $rows) {
                $state = $this->lock();
                $ledger = DB::table('onec_files')->where('sha256', $file['hash'])->first();
                $this->assertOrder($file, $state, $ledger);
                $updater = new PriceStockUpdater(new ImportBatch);
                $plans = array_map(function ($row) use ($updater, $ledger) {
                    $plan = $updater->planRow($row);
                    if ($ledger && DB::table('import_commercial_rows')->where('onec_file_id', $ledger->id)->where('sku', $row['sku'])->exists()) {
                        $plan['status'] = 'already_processed';
                        $plan['after'] = $plan['before'];
                    }

                    return $plan;
                }, $selected);

                return ['status' => 'dry_run', 'total_rows' => count($rows), 'selected_rows' => count($selected), 'plans' => $plans];
            });
        }
        $batch ??= ImportBatch::create(['type' => 'prices_only', 'source' => 'onec',
            'filename' => basename($file['original']), 'filepath' => $file['path'], 'status' => 'pending']);
        try {
            return DB::transaction(function () use ($file, $rows, $selected, $batch) {
                $state = $this->lock();
                $batch->refresh();
                if ($batch->status === 'done') {
                    return ['status' => 'duplicate', 'batch_id' => $batch->id, 'total_rows' => count($rows)];
                }
                $ledger = DB::table('onec_files')->where('sha256', $file['hash'])->first();
                $this->assertOrder($file, $state, $ledger);
                $id = $ledger?->id ?? DB::table('onec_files')->insertGetId([
                    'sha256' => $file['hash'], 'filename' => basename($file['original']),
                    'source_mtime' => $file['mtime'], 'total_rows' => count($rows), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $remaining = array_values(array_filter($selected, fn ($row) => ! DB::table('import_commercial_rows')
                    ->where('onec_file_id', $id)->where('sku', $row['sku'])->exists()));
                $chunks = array_chunk($remaining, 100);
                $batch->update(['source' => 'onec', 'onec_file_id' => $id, 'filepath' => $file['path'],
                    'status' => 'processing', 'started_at' => now(), 'finished_at' => null,
                    'total_rows' => count($selected), 'total_chunks' => count($chunks), 'processed_chunks' => 0,
                    'updated_count' => 0, 'error_count' => 0, 'not_found_count' => 0,
                    'skipped_count' => count($selected) - count($remaining)]);
                foreach ($chunks as $index => $chunk) {
                    (new ImportChunkJob($batch->id, $chunk, $index, count($chunks)))->handle();
                }
                $processed = DB::table('import_commercial_rows')->where('onec_file_id', $id)->count();
                if ($processed === count($rows)) {
                    DB::table('onec_files')->where('id', $id)->update(['completed_at' => now(), 'updated_at' => now()]);
                }
                if ($remaining !== []) {
                    DB::table('import_run_locks')->where('name', 'products')->update([
                        'source_mtime' => $ledger?->source_mtime ?? $file['mtime'], 'file_hash' => $file['hash'],
                    ]);
                }
                (new FinalizeImportJob($batch->id))->handle();
                $batch->refresh();

                return ['status' => $remaining === [] ? 'duplicate' : 'done', 'batch_id' => $batch->id,
                    'total_rows' => count($rows), 'selected_rows' => count($selected),
                    'updated' => $batch->updated_count, 'not_found' => $batch->not_found_count,
                    'diagnostic_rows' => $batch->error_count, 'skipped' => $batch->skipped_count];
            });
        } catch (\Throwable $e) {
            $batch->refresh();
            if ($batch->status !== 'done') {
                $batch->update(['status' => 'failed', 'finished_at' => now()]);
            }
            throw $e;
        }
    }

    public function lock(): object
    {
        $state = DB::table('import_run_locks')->where('name', 'products')->lockForUpdate()->first();
        if (! $state) {
            throw new \RuntimeException('Commercial import migration/lock row is missing.');
        }

        return $state;
    }

    private function assertOrder(array $file, object $state, ?object $ledger): void
    {
        $mtime = $ledger?->source_mtime ?? $file['mtime'];
        if ($state->source_mtime !== null && ($mtime < $state->source_mtime
            || ($mtime == $state->source_mtime && $state->file_hash !== $file['hash']))) {
            throw new \RuntimeException('Older or chronologically ambiguous 1C snapshot; review required.');
        }
    }
}
