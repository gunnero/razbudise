<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('mime_type');
            $table->string('title')->nullable()->after('display_name');
            $table->text('description')->nullable()->after('caption');
            $table->string('photographer')->nullable()->after('description');
            $table->string('source_name')->nullable()->after('photographer');
            $table->string('license')->nullable()->after('source_name');
            $table->text('copyright_notice')->nullable()->after('license');
            $table->string('seo_filename_suggestion')->nullable()->after('copyright_notice');
            $table->text('usage_notes')->nullable()->after('seo_filename_suggestion');
            $table->timestamp('archived_at')->nullable()->after('metadata')->index();
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'display_name',
                'title',
                'description',
                'photographer',
                'source_name',
                'license',
                'copyright_notice',
                'seo_filename_suggestion',
                'usage_notes',
                'archived_at',
            ]);
        });
    }
};
