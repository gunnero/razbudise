<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('supervising_editor_id')->nullable()->after('author_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_editor_id')->nullable()->after('supervising_editor_id')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('scheduled_at')->index();
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable()->after('submitted_by')->index();
            $table->foreignId('reviewed_by')->nullable()->after('review_started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('changes_requested_at')->nullable()->after('reviewed_by')->index();
            $table->foreignId('changes_requested_by')->nullable()->after('changes_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('changes_requested_by')->index();
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('published_by')->index();
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('rejected_by')->index();
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->timestamp('publication_failed_at')->nullable()->after('archived_by')->index();
            $table->text('publication_failure_reason')->nullable()->after('publication_failed_at');
            $table->timestamp('last_editorial_activity_at')->nullable()->after('publication_failure_reason')->index();

            $table->index(['status', 'submitted_at'], 'articles_status_submitted_idx');
            $table->index(['assigned_editor_id', 'status'], 'articles_assigned_editor_status_idx');
            $table->index(['supervising_editor_id', 'status'], 'articles_supervising_editor_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_status_submitted_idx');
            $table->dropIndex('articles_assigned_editor_status_idx');
            $table->dropIndex('articles_supervising_editor_status_idx');

            $table->dropConstrainedForeignId('supervising_editor_id');
            $table->dropConstrainedForeignId('assigned_editor_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('changes_requested_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn([
                'submitted_at',
                'review_started_at',
                'changes_requested_at',
                'approved_at',
                'rejected_at',
                'archived_at',
                'publication_failed_at',
                'publication_failure_reason',
                'last_editorial_activity_at',
            ]);
        });
    }
};
