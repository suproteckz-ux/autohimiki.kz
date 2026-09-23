<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\Ozon\OzonPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OzonCheckImages extends Command
{
    protected $signature = 'ozon:check-images {--category= : Exact local slug} {--sku=}';

    protected $description = 'Read-only HTTPS HEAD checks for local site images, without Ozon credentials';

    public function handle(OzonPayload $payload): int
    {
        $category = Category::where('slug', $this->option('category'))->first();
        if (! $category) {
            $this->error('Exact --category slug required');

            return self::FAILURE;
        }
        $query = $category->products()->active()->with('images')->orderBy('id');
        if ($this->option('sku') !== null) {
            $query->where('sku', $this->option('sku'));
        }
        $failed = 0;
        foreach ($query->get() as $product) {
            $images = $payload->images($product);
            if (! $images) {
                $failed++;
                $this->error("SKU {$product->sku}: no local HTTPS images");
            }
            foreach ($images as $url) {
                try {
                    $response = Http::connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->head($url);
                    $valid = $response->successful() && str_starts_with(strtolower($response->header('Content-Type')), 'image/');
                    $this->line("SKU {$product->sku}: ".($valid ? 'OK' : 'FAILED')." HTTP {$response->status()} {$url}");
                    $failed += $valid ? 0 : 1;
                } catch (\Throwable) {
                    $failed++;
                    $this->error("SKU {$product->sku}: image request failed {$url}");
                }
            }
        }
        $this->line('Image checks failed: '.$failed.'. Availability from this machine does not prove Ozon downloaded the image.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
