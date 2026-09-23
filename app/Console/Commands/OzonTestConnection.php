<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonTestConnection extends Command
{
    protected $signature = 'ozon:test-connection';

    protected $description = 'Check Ozon credentials with one read-only request, even when export is disabled';

    public function handle(OzonClient $client): int
    {
        $result = $client->testConnection();
        $this->line('Ozon connection: '.$result);

        return $result === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
