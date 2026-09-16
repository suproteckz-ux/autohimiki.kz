<?php

namespace App\Console\Commands;

use App\Services\ProductUrls\ProductUrlVerifier;
use Illuminate\Console\Command;

class VerifyProductUrlMigration extends Command
{
    protected $signature = 'products:verify-url-migration {receipt? : Optional JSON execution receipt} {--json : Machine-readable JSON output}';

    protected $description = 'Read-only verification of every migrated redirect, target, canonical, sitemap and protected product field';

    public function handle(ProductUrlVerifier $verifier): int
    {
        try {
            if ($path = $this->argument('receipt')) {
                if (! is_file($path) || ! is_readable($path)) {
                    throw new \RuntimeException('receipt_not_readable');
                }
                $receipt = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $result = $verifier->verify($receipt);
                $result = ['status' => $result['failed'] ? 'failed' : 'ok', 'mode' => 'receipt'] + $result;
            } else {
                $result = $verifier->verifyCurrentState();
            }
            // Keep the original JSON default for existing receipt consumers.
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $message = 'Verification could not complete; check manifest, origin, database or receipt/HTTP availability. No changes made.';
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'mode' => $this->argument('receipt') ? 'receipt' : 'current_state', 'error' => $message], JSON_THROW_ON_ERROR));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }
    }
}
