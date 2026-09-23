<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\OzonCategoryMapping;
use App\Models\OzonProductLink;
use App\Services\Ozon\OzonExporter;
use App\Services\Ozon\OzonPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class OzonExportCategory extends Command
{
    protected $signature = 'ozon:export-category {--category= : Exact local slug or ID} {--sku=} {--limit=} {--dry-run}';

    protected $description = 'Prepare/import one exact category; never publish or send stocks';

    public function handle(OzonPayload $payload, OzonExporter $exporter): int
    {
        try {
            if (! $this->option('category')) {
                $this->error('--category is required');

                return self::FAILURE;
            }
            if ($this->option('limit') !== null && (! ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1)) {
                $this->error('--limit must be positive');

                return self::FAILURE;
            }
            $category = Category::where('slug', $this->option('category'))->first();
            if (! $category && ctype_digit((string) $this->option('category'))) {
                $category = Category::find($this->option('category'));
            }
            if (! $category) {
                $this->error('Category not found; use the actual local slug or ID.');

                return self::FAILURE;
            }
            $this->line("Category: {$category->id} / {$category->slug} / {$category->name}");
            $mapping = Schema::hasTable('ozon_category_mappings')
                ? OzonCategoryMapping::where('local_category_id', $category->id)->where('enabled', true)->first() : null;
            $this->line($mapping ? "Ozon mapping: description_category_id={$mapping->ozon_description_category_id}; type_id={$mapping->ozon_type_id}" : 'Ozon mapping: MISSING');
            $linksAvailable = Schema::hasTable('ozon_product_links');
            if (! $this->option('dry-run') && (! $mapping || ! $linksAvailable)) {
                $this->error('Mapping or Ozon tables missing; no API requests made.');

                return self::FAILURE;
            }
            $query = $category->products()->active()->with('images')->orderBy('id');
            if ($this->option('sku') !== null) {
                $query->where('sku', $this->option('sku'));
            }
            if ($this->option('limit') !== null) {
                $query->limit((int) $this->option('limit'));
            }
            $counts = array_fill_keys(['Products selected', 'Ready', 'Missing photo', 'Missing description', 'Missing price', 'Missing mapping', 'Existing Ozon links', 'New', 'Created/imported', 'Requires manual review', 'Content updated', 'Stock updated', 'Price sent for new', 'Price skipped for existing', 'Skipped', 'Failed'], 0);
            foreach ($query->get() as $product) {
                $counts['Products selected']++;
                $link = $linksAvailable ? OzonProductLink::where('local_product_id', $product->id)->first() : null;
                $counts[$link ? 'Existing Ozon links' : 'New']++;
                $warnings = [];
                foreach (['Missing photo' => ! $payload->images($product), 'Missing description' => trim((string) $product->description) === '', 'Missing price' => (float) $product->price <= 0, 'Missing mapping' => ! $mapping] as $label => $missing) {
                    if ($missing) {
                        $counts[$label]++;
                        $warnings[] = $label;
                    }
                }
                try {
                    if ($link) {
                        if ($link->offer_id !== $product->sku) {
                            throw new \RuntimeException('sku_changed');
                        }
                        $counts['Price skipped for existing']++;
                        $counts['Skipped']++;
                        if ($link->status === 'requires_manual_review') {
                            $counts['Requires manual review']++;
                        }
                        if ($link->status === 'error') {
                            $counts['Failed']++;
                        }
                        $this->line("SKU {$product->sku}: existing; status={$link->status}; price skipped".($link->last_error ? '; '.$link->last_error : ''));

                        continue;
                    }
                    if (! $mapping) {
                        throw new \RuntimeException('ozon_mapping_missing');
                    }
                    $payload->create($product, $mapping);
                    $counts['Ready']++;
                    if (! $this->option('dry-run')) {
                        $result = $exporter->export($product, $mapping);
                        if ($result === 'imported') {
                            $counts['Created/imported']++;
                            $counts['Price sent for new']++;
                        } else {
                            $counts['Price skipped for existing']++;
                            $counts['Skipped']++;
                        }
                    }
                    $this->line("SKU {$product->sku}: quantity={$payload->quantity($product)} (held until manual publication)".($warnings ? '; '.implode('; ', $warnings) : ''));
                } catch (\Throwable $e) {
                    $counts['Failed']++;
                    $this->error("SKU {$product->sku}: ".$exporter->safeError($e).($warnings ? '; '.implode('; ', $warnings) : ''));
                }
            }
            $this->table(['Report', 'Count'], collect($counts)->map(fn ($value, $key) => [$key, $value])->values()->all());
            $this->line('Accepted task is not a created draft. Run ozon:refresh-imports and inspect the seller cabinet.');
            if ($this->option('dry-run')) {
                $this->line('Dry-run: no HTTP requests or database writes. Remote duplicates, image availability and API validation are NOT checked.');
            }

            return $counts['Failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Local catalog unavailable or operation failed. '.$exporter->safeError($e));

            return self::FAILURE;
        }
    }
}
