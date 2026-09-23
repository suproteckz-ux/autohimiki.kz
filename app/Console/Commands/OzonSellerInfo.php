<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonSellerInfo extends Command
{
    protected $signature = 'ozon:seller-info';

    protected $description = 'Read seller info without enabling exports or storing API responses';

    public function handle(OzonClient $client): int
    {
        try {
            $data = $client->sellerInfo();
            $this->line('Ozon seller: OK');
            $this->line('Company: '.$client->safeDisplay(data_get($data, 'company.name') ?? data_get($data, 'result.company.name')));

            return self::SUCCESS;
        } catch (\RuntimeException $error) {
            $this->error('Ozon seller: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
