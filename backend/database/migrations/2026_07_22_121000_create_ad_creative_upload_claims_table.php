<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_creative_media_blobs', function (Blueprint $table): void {
            $table->char('content_sha256', 64)->primary();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();
            $table->index('media_asset_id');
        });

        Schema::create('ad_creative_upload_claims', function (Blueprint $table): void {
            $table->id();
            // Revoked and expired claims are retained as a lifecycle record after
            // their media reference is released.
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('claimant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('original_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('attached_booking_request_id')
                ->nullable()
                ->constrained('ad_booking_requests')
                ->nullOnDelete();
            $table->timestamps();
            $table->index(['media_asset_id', 'status'], 'ad_creative_claims_media_status_idx');
            $table->index(['claimant_user_id', 'status'], 'ad_creative_claims_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_creative_upload_claims');
        Schema::dropIfExists('ad_creative_media_blobs');
    }
};
