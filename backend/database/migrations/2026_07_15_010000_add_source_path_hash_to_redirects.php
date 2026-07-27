<?php

use App\Support\UrlPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            $table->char('source_path_hash', 64)->nullable()->after('source_url');
        });

        DB::table('redirects')
            ->select(['id', 'source_url'])
            ->orderBy('id')
            ->chunkById(500, function ($redirects): void {
                foreach ($redirects as $redirect) {
                    $path = UrlPath::normalize($redirect->source_url);

                    DB::table('redirects')
                        ->where('id', $redirect->id)
                        ->update(['source_path_hash' => $path === '' ? null : hash('sha256', $path)]);
                }
            });

        Schema::table('redirects', function (Blueprint $table): void {
            $table->index(['is_active', 'source_path_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'source_path_hash']);
            $table->dropColumn('source_path_hash');
        });
    }
};
