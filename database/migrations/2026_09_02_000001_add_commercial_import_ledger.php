<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['total_chunks', 'processed_chunks'] as $column) {
            if (! Schema::hasColumn('import_batches', $column)) {
                Schema::table('import_batches', fn (Blueprint $t) => $t->unsignedInteger($column)->default(0));
            }
        }
        Schema::table('import_batches', function (Blueprint $t) {
            $t->string('source')->nullable()->index();
            $t->unsignedBigInteger('onec_file_id')->nullable()->index();
        });
        Schema::create('import_run_locks', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->unsignedBigInteger('source_mtime')->nullable();
            $t->string('file_hash', 64)->nullable();
        });
        DB::table('import_run_locks')->insert(['name' => 'products']);
        Schema::create('onec_files', function (Blueprint $t) {
            $t->id();
            $t->string('sha256', 64)->unique();
            $t->text('filename');
            $t->unsignedBigInteger('source_mtime');
            $t->unsignedInteger('total_rows');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('import_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('chunk_index');
            $t->unique(['import_batch_id', 'chunk_index']);
        });
        Schema::create('import_commercial_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('onec_file_id')->constrained('onec_files')->restrictOnDelete();
            $t->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('sku');
            $t->unsignedInteger('row_number');
            $t->string('status');
            $t->json('before_values')->nullable();
            $t->json('after_values')->nullable();
            $t->json('diagnostics');
            $t->timestamp('created_at');
            $t->unique(['onec_file_id', 'sku']);
        });
    }

    public function down(): void
    {
        // Roll back products from the journal first, never erase rollback evidence.
        if (DB::table('import_commercial_rows')->exists() || DB::table('onec_files')->exists()) {
            throw new RuntimeException('Commercial ledger is not empty; retain it and roll back code only.');
        }
        Schema::dropIfExists('import_commercial_rows');
        Schema::dropIfExists('import_chunks');
        Schema::dropIfExists('onec_files');
        Schema::dropIfExists('import_run_locks');
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn(['source', 'onec_file_id']));
    }
};
