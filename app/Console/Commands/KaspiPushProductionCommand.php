<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiSingleProductPolicy;
use Illuminate\Console\Command;

class KaspiPushProductionCommand extends Command
{
    protected $signature = 'kaspi:push-production {--sku=} {--dry-run} {--debug}';

    protected $description = 'Controlled local Kaspi content import for the single KASPI-1C SKU';

    public function handle(KaspiProductionBridgeService $bridge): int
    {
        try {
            KaspiSingleProductPolicy::assertSku($this->option('sku'));
            $prepared = $bridge->prepare($this->option('sku'), (bool) $this->option('debug'));
            $this->line(json_encode($prepared['preview'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            if ($this->option('dry-run')) {
                $this->info('dry_run: no POST performed');

                return self::SUCCESS;
            }
            $this->line(json_encode($bridge->send($prepared['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Do not expose HTTP exceptions, raw browser output or payloads containing credentials.
            $message = $e instanceof \RuntimeException && preg_match('/^([a-z][a-z0-9_]{2,100})(?::|$)/D', $e->getMessage(), $matches) ? $matches[1] : 'kaspi_1c_failed';
            $this->error($message);

            return self::FAILURE;
        }
    }
}
