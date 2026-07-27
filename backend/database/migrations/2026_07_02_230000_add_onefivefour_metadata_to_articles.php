<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('onefivefour_source_id')->nullable()->unique()->after('old_url');
            $table->json('onefivefour_metadata')->nullable()->after('onefivefour_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn(['onefivefour_source_id', 'onefivefour_metadata']);
        });
    }
};
