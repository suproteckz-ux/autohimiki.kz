<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonDiagnose extends Command
{
    protected $signature = 'ozon:diagnose';

    protected $description = 'Print effective Ozon read-only URLs and credential presence without HTTP or DB access';

    public function handle(OzonClient $client): int
    {
        $data = $client->diagnostics();
        $this->line('Base host: '.$data['base_host']);
        $this->line('Base URL source: fixed in OzonClient; OZON_BASE_URL is not used');
        $this->line('Seller endpoint: '.$data['seller_endpoint']);
        $this->line('Seller effective URL: '.$data['seller_effective_url']);
        $this->line('Seller method: POST');
        $this->line('Seller Content-Type: application/json');
        $this->line('Seller body: {}');
        $this->line('Warehouse endpoint: '.$data['warehouse_endpoint']);
        $this->line('Warehouse effective URL: '.$data['warehouse_effective_url']);
        $this->line('Warehouse method: POST');
        $this->line('Credentials configured:');
        $this->line('Client ID: '.($data['client_id_configured'] ? 'yes' : 'no'));
        $this->line('API key: '.($data['api_key_configured'] ? 'yes' : 'no'));
        $this->line('No HTTP requests or database writes performed.');

        return self::SUCCESS;
    }
}
