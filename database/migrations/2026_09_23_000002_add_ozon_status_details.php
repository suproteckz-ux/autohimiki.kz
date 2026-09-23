<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ozon_product_links', function (Blueprint $table) {
            $table->timestamp('last_status_check_at')->nullable();
            $table->string('ozon_status')->nullable();
            $table->text('ozon_status_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ozon_product_links', fn (Blueprint $table) => $table->dropColumn(['last_status_check_at', 'ozon_status', 'ozon_status_message']));
    }
};
