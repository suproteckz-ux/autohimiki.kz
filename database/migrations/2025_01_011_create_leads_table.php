<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30);
            $table->foreignId('product_id')
                  ->nullable()
                  ->constrained('products')
                  ->nullOnDelete();
            $table->string('product_name')->nullable();
            $table->text('comment')->nullable();
            $table->string('source', 50)->default('form');
            $table->enum('status', ['new', 'processing', 'done', 'cancelled'])
                  ->default('new');
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
