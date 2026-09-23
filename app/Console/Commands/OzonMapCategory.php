<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\OzonCategoryMapping;
use Illuminate\Console\Command;

class OzonMapCategory extends Command
{
    protected $signature = 'ozon:map-category {category : Exact local slug} {description_category_id} {type_id} {--enable}';

    protected $description = 'Save a single manually supplied Ozon mapping; no network access';

    public function handle(): int
    {
        $category = Category::where('slug', $this->argument('category'))->first();
        if (! $category) {
            $this->error('Local category not found');

            return self::FAILURE;
        }
        foreach (['description_category_id', 'type_id'] as $key) {
            if (! ctype_digit($this->argument($key)) || (int) $this->argument($key) <= 0) {
                $this->error('Identifiers must be positive integers');

                return self::FAILURE;
            }
        }
        OzonCategoryMapping::updateOrCreate(['local_category_id' => $category->id], [
            'ozon_description_category_id' => $this->argument('description_category_id'),
            'ozon_type_id' => $this->argument('type_id'), 'enabled' => $this->option('enable'),
        ]);
        $this->info("Saved mapping for {$category->id} / {$category->name}");

        return self::SUCCESS;
    }
}
