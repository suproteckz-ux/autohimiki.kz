<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->string('hero_image')->nullable()->after('intro');
            $table->string('hero_image_alt', 255)->nullable()->after('hero_image');
            $table->string('hero_label', 120)->nullable()->after('hero_image_alt');
            $table->string('cta_heading', 255)->nullable()->after('canonical_url');
            $table->string('cta_description', 500)->nullable()->after('cta_heading');
            $table->string('cta_link', 500)->nullable()->after('cta_description');
            $table->string('cta_button_text', 100)->nullable()->after('cta_link');
            $table->boolean('show_comparison')->default(false)->after('cta_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropColumn([
                'hero_image', 'hero_image_alt', 'hero_label',
                'cta_heading', 'cta_description', 'cta_link', 'cta_button_text',
                'show_comparison',
            ]);
        });
    }
};
