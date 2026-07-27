<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('advertiser_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name');
            $table->string('tax_number');
            $table->string('registration_number')->nullable();
            $table->string('billing_address');
            $table->string('billing_city');
            $table->string('billing_country', 2)->default('MK');
            $table->string('contact_name');
            $table->string('contact_email')->index();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('dashboard_deactivated_at')->nullable();
            $table->timestamp('former_customer_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index(['company_name', 'tax_number']);
            $table->index('advertiser_id', 'ad_customers_advertiser_id_idx');
        });

        Schema::create('ad_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_zone_id')->constrained('ad_zones')->cascadeOnDelete();
            $table->unsignedTinyInteger('duration_weeks');
            $table->string('creative_type')->default('image');
            $table->unsignedInteger('price_denars');
            $table->decimal('political_multiplier', 8, 2)->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['ad_zone_id', 'duration_weeks', 'creative_type']);
        });

        Schema::create('ad_discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->string('type')->default('percent');
            $table->unsignedInteger('value');
            $table->json('ad_zone_keys')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ad_booking_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_customer_id')->nullable()->constrained('ad_customers')->nullOnDelete();
            $table->foreignId('discount_code_id')->nullable()->constrained('ad_discount_codes')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('billing_company_name');
            $table->string('billing_tax_number');
            $table->string('billing_registration_number')->nullable();
            $table->string('billing_address');
            $table->string('billing_city');
            $table->string('billing_country', 2)->default('MK');
            $table->string('contact_name');
            $table->string('contact_email')->index();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default('pending_review')->index();
            $table->unsignedInteger('subtotal_denars')->default(0);
            $table->unsignedInteger('discount_denars')->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->unsignedInteger('tax_denars')->default(0);
            $table->unsignedInteger('total_denars')->default(0);
            $table->string('payment_status')->default('unpaid')->index();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('external_payment_provider')->nullable();
            $table->string('external_payment_id')->nullable();
            $table->json('payment_metadata')->nullable();
            $table->timestamp('hold_expires_at')->nullable()->index();
            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index('ad_customer_id', 'ad_booking_requests_customer_id_idx');
            $table->index('discount_code_id', 'ad_booking_requests_discount_code_id_idx');
            $table->index(['status', 'payment_status']);
        });

        Schema::create('ad_booking_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_booking_request_id')->constrained('ad_booking_requests')->cascadeOnDelete();
            $table->foreignId('ad_zone_id')->constrained('ad_zones')->restrictOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->foreignId('ad_creative_id')->nullable()->constrained('ad_creatives')->nullOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->unsignedTinyInteger('duration_weeks');
            $table->timestamp('start_at')->index();
            $table->timestamp('end_at')->index();
            $table->string('creative_type')->default('image')->index();
            $table->longText('html')->nullable();
            $table->string('click_url')->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('status')->default('pending_review')->index();
            $table->boolean('is_political_or_sensitive')->default(false)->index();
            $table->decimal('political_multiplier', 8, 2)->default(1);
            $table->boolean('technical_review_required')->default(false)->index();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedInteger('price_denars');
            $table->timestamps();
            $table->index('ad_booking_request_id', 'ad_booking_line_items_request_id_idx');
            $table->index('ad_campaign_id', 'ad_booking_line_items_campaign_id_idx');
            $table->index('ad_creative_id', 'ad_booking_line_items_creative_id_idx');
            $table->index('image_id', 'ad_booking_line_items_image_id_idx');
            $table->index(['ad_zone_id', 'start_at', 'end_at']);
        });

        Schema::create('ad_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_booking_request_id')->unique()->constrained('ad_booking_requests')->cascadeOnDelete();
            $table->string('number')->unique();
            $table->string('type')->default('proforma');
            $table->string('status')->default('issued')->index();
            $table->string('document_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('subtotal_denars')->default(0);
            $table->unsignedInteger('discount_denars')->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->unsignedInteger('tax_denars')->default(0);
            $table->unsignedInteger('total_denars')->default(0);
            $table->timestamps();
        });

        Schema::create('ad_creative_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_booking_line_item_id')->constrained('ad_booking_line_items')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('creative_type');
            $table->longText('html')->nullable();
            $table->string('click_url')->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('status')->default('pending_review')->index();
            $table->boolean('technical_review_required')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('ad_booking_line_item_id', 'ad_creative_revisions_line_item_id_idx');
            $table->index('submitted_by', 'ad_creative_revisions_submitted_by_idx');
            $table->index('image_id', 'ad_creative_revisions_image_id_idx');
            $table->index('reviewed_by', 'ad_creative_revisions_reviewed_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_creative_revisions');
        Schema::dropIfExists('ad_invoices');
        Schema::dropIfExists('ad_booking_line_items');
        Schema::dropIfExists('ad_booking_requests');
        Schema::dropIfExists('ad_discount_codes');
        Schema::dropIfExists('ad_prices');
        Schema::dropIfExists('ad_customers');
    }
};
