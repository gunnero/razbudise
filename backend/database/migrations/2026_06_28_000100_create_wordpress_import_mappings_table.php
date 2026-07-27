<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->unsignedBigInteger('wordpress_id')->nullable();
            $table->string('source_slug')->nullable();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'wordpress_id']);
            $table->unique(['source_type', 'source_slug']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_import_mappings');
    }
};
