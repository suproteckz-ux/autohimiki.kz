<?php

namespace App\Console\Commands;

use App\Services\ProductUrls\ProductUrlRollback;
use Illuminate\Console\Command;

class RollbackOnecProductSlugs extends Command
{
    protected $signature = 'products:rollback-onec-slugs {receipt : Original JSON execution receipt} {--dry-run} {--approve=}';

    protected $description = 'Restore prior product URLs atomically, preserving the new URLs as reverse 301 aliases';

    public function handle(ProductUrlRollback $rollback): int
    {
        try {
            $receipt = json_decode(file_get_contents($this->argument('receipt')), true, 512, JSON_THROW_ON_ERROR);
            $result = $this->option('dry-run') ? $rollback->plan($receipt) : $rollback->execute($receipt, (string) $this->option('approve'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return str_starts_with($result['cache_status'] ?? '', 'FAILED') ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Rollback blocked or transaction failed. Rerun rollback dry-run and review URL state; no partial product rollback committed.');

            return self::FAILURE;
        }
    }
}
