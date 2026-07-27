<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_supervisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('authors')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['editor_id', 'author_id'], 'editorial_supervisions_editor_author_unique');
            $table->index(['author_id', 'is_primary', 'ends_at'], 'editorial_supervisions_author_primary_idx');
            $table->index(['editor_id', 'ends_at'], 'editorial_supervisions_editor_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_supervisions');
    }
};
