<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class OzonTestConnection extends Command
{
    protected $signature = 'ozon:test-connection';

    protected $description = 'Check Ozon credentials with one read-only request, even when export is disabled';

    public function handle(OzonClient $client): int
    {
        $details = [];
        $result = $client->testConnection(function (array $safeResponse) use (&$details): void {
            $details = $safeResponse;
        });
        $this->line('Ozon connection: '.$result);
        foreach ($details as $label => $value) {
            // VERBOSITY_NORMAL + OUTPUT_RAW: response text is not console markup.
            $this->output->writeln($label.': '.$value, OutputInterface::OUTPUT_RAW);
        }

        return $result === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
