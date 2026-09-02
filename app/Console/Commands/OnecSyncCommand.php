<?php

namespace App\Console\Commands;

use App\Services\Import\CommercialImportRunner;
use App\Services\Import\OnecFileIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OnecSyncCommand extends Command
{
    protected $signature = 'onec:sync {--dry-run} {--sku=} {--limit=} {--file=} {--debug}';

    protected $description = 'Validate the newest stable 1C XLSX and update only price, quantity and in_stock.';

    public function handle(OnecFileIntake $intake, CommercialImportRunner $runner): int
    {
        $file = null;
        $result = null;
        try {
            $options = ['dry_run' => (bool) $this->option('dry-run')];
            if ($this->option('sku') !== null) {
                if (trim($this->option('sku')) === '') {
                    throw new \InvalidArgumentException('--sku must not be empty.');
                }
                $options['sku'] = trim($this->option('sku'));
            }
            if ($this->option('limit') !== null) {
                if (! ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1) {
                    throw new \InvalidArgumentException('--limit must be a positive integer.');
                }
                $options['limit'] = (int) $this->option('limit');
            }
            $file = $intake->stage($this->option('file'), $options['dry_run']);
            if ($file['status'] === 'no_file') {
                $this->info('No XLSX input.');

                return self::SUCCESS;
            }
            $result = $runner->run($file, $options);
            $this->info('SHA-256: '.$file['hash']);
            foreach ($result['plans'] ?? [] as $plan) {
                $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            unset($result['plans']);
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
            if ($this->option('debug')) {
                $this->line('Source: '.$file['original'].'; mtime: '.$file['mtime'].'; staged bytes: '.$file['size']);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            Log::warning('1C import rejected/failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        } finally {
            if (isset($file['path']) && ($this->option('dry-run') || $result === null || ($result['status'] ?? null) === 'duplicate')) {
                @unlink($file['path']);
            }
        }
    }
}
