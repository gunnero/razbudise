<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metadata', function (Blueprint $table) {
            $table->text('seo_title')->nullable()->change();
            $table->text('canonical_url')->nullable()->change();
            $table->text('og_title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seo_metadata', function (Blueprint $table) {
            $table->string('seo_title')->nullable()->change();
            $table->string('canonical_url')->nullable()->change();
            $table->string('og_title')->nullable()->change();
        });
    }
};
