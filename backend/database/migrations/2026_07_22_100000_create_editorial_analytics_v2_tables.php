<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_views', function (Blueprint $table): void {
            $table->char('session_hash', 64)->nullable()->after('visitor_hash')->index();
            $table->string('source_channel', 40)->nullable()->after('referrer_host')->index();
            $table->string('context_page', 40)->nullable()->after('source_channel');
            $table->boolean('is_returning')->default(false)->after('device')->index();
        });

        Schema::create('editorial_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 48)->index();
            $table->char('visitor_hash', 64)->index();
            $table->char('session_hash', 64)->nullable()->index();
            $table->char('dedupe_key', 64)->unique();
            $table->date('event_date')->index();
            $table->timestamp('occurred_at')->index();
            $table->string('context_page', 40)->nullable()->index();
            $table->string('source_channel', 40)->default('unknown')->index();
            $table->string('referrer_host')->nullable();
            $table->string('device', 20)->default('unknown')->index();
            $table->unsignedInteger('engaged_ms')->nullable();
            $table->unsignedTinyInteger('scroll_depth')->nullable();
            $table->string('target_host')->nullable();
            $table->boolean('is_returning')->default(false)->index();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->unsignedTinyInteger('consent_version');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'event_date', 'event_type'], 'editorial_events_article_day_type');
            $table->index(['author_id', 'event_date'], 'editorial_events_author_day');
        });

        Schema::create('editorial_analytics_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day')->index();
            $table->string('scope_key', 80);
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_channel', 40)->default('unknown');
            $table->string('device', 20)->default('unknown');
            $table->unsignedBigInteger('page_views')->default(0);
            $table->unsignedBigInteger('article_opens')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->unsignedBigInteger('engaged_sessions')->default(0);
            $table->unsignedBigInteger('engaged_time_ms')->default(0);
            $table->unsignedBigInteger('scroll_depth_sum')->default(0);
            $table->unsignedBigInteger('scroll_depth_samples')->default(0);
            $table->unsignedBigInteger('outbound_clicks')->default(0);
            $table->unsignedBigInteger('social_shares')->default(0);
            $table->unsignedBigInteger('completions')->default(0);
            $table->unsignedBigInteger('return_visitors')->default(0);
            $table->unsignedBigInteger('homepage_clicks')->default(0);
            $table->unsignedBigInteger('category_clicks')->default(0);
            $table->unsignedBigInteger('author_clicks')->default(0);
            $table->unsignedBigInteger('search_clicks')->default(0);
            $table->unsignedBigInteger('related_clicks')->default(0);
            $table->timestamps();

            $table->unique(['day', 'scope_key', 'source_channel', 'device'], 'editorial_analytics_daily_dimension');
            $table->index(['author_id', 'day'], 'editorial_daily_author_day');
            $table->index(['category_id', 'day'], 'editorial_daily_category_day');
            $table->index(['source_channel', 'day'], 'editorial_daily_source_day');
            $table->index(['device', 'day'], 'editorial_daily_device_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_analytics_daily');
        Schema::dropIfExists('editorial_analytics_events');

        Schema::table('article_views', function (Blueprint $table): void {
            $table->dropIndex(['session_hash']);
            $table->dropIndex(['source_channel']);
            $table->dropIndex(['is_returning']);
            $table->dropColumn(['session_hash', 'source_channel', 'context_page', 'is_returning']);
        });
    }
};
