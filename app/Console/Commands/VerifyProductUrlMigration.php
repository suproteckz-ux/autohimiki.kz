<?php

namespace App\Console\Commands;

use App\Services\ProductUrls\ProductUrlVerifier;
use Illuminate\Console\Command;

class VerifyProductUrlMigration extends Command
{
    protected $signature = 'products:verify-url-migration {receipt : JSON execution receipt, not the public proposal}';

    protected $description = 'Read-only verification of every migrated redirect, target, canonical, sitemap and protected product field';

    public function handle(ProductUrlVerifier $verifier): int
    {
        try {
            $receipt = json_decode(file_get_contents($this->argument('receipt')), true, 512, JSON_THROW_ON_ERROR);
            $result = $verifier->verify($receipt);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Verification could not complete; check receipt, origin, database and public HTTP availability. No changes made.');

            return self::FAILURE;
        }
    }
}
