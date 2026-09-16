<?php

namespace App\Console\Commands;

use App\Services\ProductUrls\ProductUrlMigration;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class MigrateOnecProductSlugs extends Command
{
    protected $signature = 'products:migrate-onec-slugs {--dry-run : SELECT-only plan; no DB/cache writes}
        {--approve= : Exact approval hash from a successful dry-run}
        {--expect= : Require this candidate count} {--json : Emit complete machine-readable manifest/receipt}';

    protected $description = 'Plan or atomically migrate active onec UUID product URLs; preserve SKU/content and history';

    public function handle(ProductUrlMigration $migration): int
    {
        try {
            $expect = $this->option('expect');
            if ($expect !== null && ! ctype_digit((string) $expect)) {
                throw new \RuntimeException('expect_must_be_a_nonnegative_integer');
            }
            if ($this->option('dry-run')) {
                $result = $migration->plan();
                $result['status'] = 'dry_run_no_changes';
            } else {
                $result = $migration->execute((string) $this->option('approve'), $expect === null ? null : (int) $expect);
            }
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                foreach ($result['rows'] as $row) {
                    $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
                $this->info("Candidates: {$result['count']}; blocked: {$result['blocked']}; status: {$result['status']}");
                $this->line('Approval: '.$result['approval']);
                if (isset($result['cache_status'])) {
                    $this->line('Cache: '.$result['cache_status']);
                }
            }

            return $result['blocked'] || ($expect !== null && (int) $expect !== $result['count'])
                || str_starts_with($result['cache_status'] ?? '', 'FAILED') ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            // Do not print QueryException SQL/bindings or credentials in CLI reports.
            $message = $e instanceof QueryException ? 'database_operation_failed; transaction_rolled_back_if_started' : $e->getMessage();
            $this->error($message);

            return self::FAILURE;
        }
    }
}
