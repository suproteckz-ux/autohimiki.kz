<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_url')->unique();
            $table->string('to_url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('from_url');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
