<?php

namespace App\Console\Commands;

use App\Models\OzonProductLink;
use App\Services\Ozon\OzonExporter;
use Illuminate\Console\Command;

class OzonRefreshImports extends Command
{
    protected $signature = 'ozon:refresh-imports {--sku= : Required original offer_id}';

    protected $description = 'Check a single asynchronous import without re-importing or resending prices';

    public function handle(OzonExporter $exporter): int
    {
        $link = OzonProductLink::where('offer_id', $this->option('sku'))->first();
        if (! $link) {
            $this->error('Known --sku required');

            return self::FAILURE;
        }
        try {
            $exporter->refresh($link);
            $this->line("SKU {$link->offer_id}: {$link->status}; ".($link->last_error ?? ''));

            return $link->status === 'error' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $message = $exporter->safeError($e);
            $link->update(['last_error' => $message]);
            $this->error($message);

            return self::FAILURE;
        }
    }
}
