<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonWarehouses extends Command
{
    protected $signature = 'ozon:warehouses {--cursor= : Read one explicit next page}';

    protected $description = 'Read one warehouse page; never choose a warehouse or update config automatically';

    public function handle(OzonClient $client): int
    {
        try {
            $data = $client->warehouses((string) $this->option('cursor'));
            $rows = [];
            foreach ($data['warehouses'] as $item) {
                $status = is_array($item['status'] ?? null) ? ($item['status']['state'] ?? '') : ($item['status'] ?? '');
                $active = empty($item['is_archived']) && ! in_array(strtoupper((string) $status), ['DISABLED', 'ARCHIVED', 'INACTIVE'], true);
                $rows[] = array_map($client->safeDisplay(...), [$item['warehouse_id'], $item['name'] ?? '', $status, $active ? 'yes' : 'no']);
            }
            $this->table(['Warehouse ID', 'Name', 'Status', 'Active'], $rows);
            $this->line('Configured warehouse: '.$client->safeDisplay(config('ozon.warehouse_id')));
            if ($data['has_next'] ?? false) {
                $this->line('More warehouses available; next --cursor='.$client->safeDisplay($data['cursor']));
            }
            $this->line('Read only. Select the appropriate warehouse manually; config was not changed.');

            return self::SUCCESS;
        } catch (\RuntimeException $error) {
            $this->error('Ozon warehouses: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
