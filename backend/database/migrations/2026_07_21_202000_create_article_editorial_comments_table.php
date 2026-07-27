<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_editorial_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('article_editorial_comments')->cascadeOnDelete();
            $table->longText('body');
            $table->string('visibility')->default('author_visible')->index();
            $table->boolean('is_blocking')->default(false)->index();
            $table->boolean('is_resolved')->default(false)->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'visibility']);
            $table->index(['article_id', 'is_blocking', 'is_resolved'], 'article_comments_blocking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_editorial_comments');
    }
};
