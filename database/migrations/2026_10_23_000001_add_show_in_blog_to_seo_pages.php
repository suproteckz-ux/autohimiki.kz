<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->boolean('show_in_blog')->default(false)->after('show_comparison');
            $table->index(['show_in_blog', 'is_active'], 'seo_pages_blog_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropIndex('seo_pages_blog_idx');
            $table->dropColumn('show_in_blog');
        });
    }
};
