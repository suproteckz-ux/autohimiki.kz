<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->json('filter_criteria')->nullable();
        });
        Schema::create('seo_manager_requests', function (Blueprint $table) {
            $table->id();
            $table->string('actor', 24);
            $table->string('idempotency_key', 128);
            $table->string('payload_hash', 64);
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('http_status')->default(201);
            $table->timestamps();
            $table->unique(['actor', 'idempotency_key']);
            $table->index('created_at');
        });
        Schema::create('seo_manager_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_page_id')->unique()->constrained('seo_pages')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->json('metadata');
            $table->timestamps();
        });
        Schema::create('seo_manager_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->json('proposal');
            $table->string('status', 16)->default('proposed');
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('seo_manager_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor', 24);
            $table->string('action', 32);
            $table->unsignedBigInteger('resource_id');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->index(['resource_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_manager_audit_logs');
        Schema::dropIfExists('seo_manager_proposals');
        Schema::dropIfExists('seo_manager_drafts');
        Schema::dropIfExists('seo_manager_requests');
        Schema::table('seo_pages', fn (Blueprint $table) => $table->dropColumn('filter_criteria'));
    }
};
