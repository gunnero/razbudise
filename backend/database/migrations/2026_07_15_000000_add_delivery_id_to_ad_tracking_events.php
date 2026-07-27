<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_impressions', function (Blueprint $table): void {
            $table->uuid('delivery_id')->nullable()->after('id')->unique();
            $table->char('event_fingerprint', 64)->nullable()->after('delivery_id')->unique();
        });

        Schema::table('ad_clicks', function (Blueprint $table): void {
            $table->uuid('delivery_id')->nullable()->after('id')->unique();
            $table->char('event_fingerprint', 64)->nullable()->after('delivery_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ad_impressions', function (Blueprint $table): void {
            $table->dropUnique(['event_fingerprint']);
            $table->dropUnique(['delivery_id']);
            $table->dropColumn('event_fingerprint');
            $table->dropColumn('delivery_id');
        });

        Schema::table('ad_clicks', function (Blueprint $table): void {
            $table->dropUnique(['event_fingerprint']);
            $table->dropUnique(['delivery_id']);
            $table->dropColumn('event_fingerprint');
            $table->dropColumn('delivery_id');
        });
    }
};
