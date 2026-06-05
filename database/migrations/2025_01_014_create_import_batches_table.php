<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['prices_only', 'full'])->default('prices_only');
            $table->string('filename');
            $table->string('filepath');
            $table->json('column_map')->nullable();
            $table->json('price_changes')->nullable();
            $table->json('stock_changes')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('processed_chunks')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('not_found_count')->default(0);
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])
                  ->default('pending');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
