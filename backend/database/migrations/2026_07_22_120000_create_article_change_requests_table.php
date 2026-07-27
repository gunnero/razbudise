<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary');
            $table->date('deadline_at')->nullable()->index();
            $table->timestamp('resubmitted_at')->nullable()->index();
            $table->foreignId('resubmitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['article_id', 'created_at'], 'article_change_requests_history_idx');
        });

        Schema::table('article_editorial_comments', function (Blueprint $table) {
            $table->foreignId('change_request_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('article_change_requests')
                ->nullOnDelete();
            $table->timestamp('addressed_at')->nullable()->after('is_resolved')->index();
            $table->foreignId('addressed_by')
                ->nullable()
                ->after('addressed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(
                ['change_request_id', 'is_resolved', 'is_blocking'],
                'article_change_request_comments_state_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('article_editorial_comments', function (Blueprint $table) {
            // MySQL may use the composite state index to support this foreign
            // key, while SQLite will not drop a column referenced by an index.
            // Remove the constraint and index independently before the column.
            $table->dropForeign(['change_request_id']);
            $table->dropIndex('article_change_request_comments_state_idx');
            $table->dropConstrainedForeignId('addressed_by');
            $table->dropIndex(['addressed_at']);
            $table->dropColumn('addressed_at');
            $table->dropColumn('change_request_id');
        });

        Schema::dropIfExists('article_change_requests');
    }
};
