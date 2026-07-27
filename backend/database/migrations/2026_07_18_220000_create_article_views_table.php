<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->timestamp('occurred_at')->index();
            $table->string('referrer_host')->nullable();
            $table->string('device', 20)->nullable()->index();
            $table->timestamps();

            $table->unique(['article_id', 'visitor_hash', 'viewed_on']);
            $table->index(['article_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_views');
    }
};
