<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_booking_requests', function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('ad_booking_requests', function (Blueprint $table): void {
            $table->dropColumn('rejection_reason');
        });
    }
};
