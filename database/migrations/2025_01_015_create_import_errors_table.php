<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')
                  ->constrained('import_batches')
                  ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('sku')->nullable();
            $table->text('message');
            $table->json('row_data')->nullable();
            $table->timestamps();

            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
