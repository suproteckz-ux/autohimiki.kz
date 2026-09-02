<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support both the original minimal 014 and the consolidated 014 in this repo.
        foreach (['type', 'column_map', 'price_changes', 'stock_changes', 'not_found_count'] as $column) {
            if (Schema::hasColumn('import_batches', $column)) {
                continue;
            }
            Schema::table('import_batches', function (Blueprint $table) use ($column) {
                match ($column) {
                    'type' => $table->enum('type', ['prices_only', 'full'])->default('prices_only'),
                    'not_found_count' => $table->unsignedInteger('not_found_count')->default(0),
                    default => $table->json($column)->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        // These columns may have been created by 014. Retain data on rollback;
        // rolling back 014 itself removes its table.
    }
};
