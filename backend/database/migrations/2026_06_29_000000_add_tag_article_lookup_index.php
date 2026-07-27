<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_tag', function (Blueprint $table): void {
            $table->index(['tag_id', 'article_id'], 'article_tag_tag_id_article_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('article_tag', function (Blueprint $table): void {
            $table->dropIndex('article_tag_tag_id_article_id_index');
        });
    }
};
