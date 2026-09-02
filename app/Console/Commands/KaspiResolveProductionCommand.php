<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use Illuminate\Console\Command;

class KaspiResolveProductionCommand extends Command
{
    protected $signature = 'kaspi:resolve-production {--sku=} {--limit=1} {--cursor=0} {--dry-run} {--debug}';

    protected $description = 'Read candidates and resolve widget URLs in visible local Windows Chromium; never writes products.';

    public function handle(KaspiLocalBrowserGuard $guard, KaspiProductionCandidateClient $client, KaspiLocalUrlResolver $resolver): int
    {
        try {
            $guard->assertAllowed();
            $options = ['limit' => $this->option('limit'), 'cursor' => $this->option('cursor')];
            if (! ctype_digit((string) $options['limit']) || (int) $options['limit'] < 1 || (int) $options['limit'] > 100
                || ! ctype_digit((string) $options['cursor'])) {
                throw new \RuntimeException('Use --limit=1..100 and a nonnegative --cursor.');
            }
            if ($this->option('sku') !== null) {
                $options['sku'] = trim($this->option('sku'));
                if ($options['sku'] === '' || mb_strlen($options['sku']) > 255) {
                    throw new \RuntimeException('Invalid --sku.');
                }
            }
            if (blank(config('services.kaspi.merchant_id')) || blank(config('services.kaspi.city_id'))) {
                throw new \RuntimeException('Set KASPI_MERCHANT_ID and KASPI_CITY_ID locally.');
            }
            $candidates = $client->fetch($options);
            if ($candidates === []) {
                $this->warn('No eligible active products: check exact SKU and storefront slug; bulk requests also require missing main_image/description.');

                return self::SUCCESS;
            }
            $failed = false;
            foreach ($candidates as $candidate) {
                $result = $resolver->resolve($candidate, (bool) $this->option('debug'));
                $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $failed = $failed || $result['status'] !== 'resolved';
                if ($result['status'] === 'captcha_detected') {
                    break;
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
