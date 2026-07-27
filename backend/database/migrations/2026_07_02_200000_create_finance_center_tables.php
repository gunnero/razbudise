<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('expense')->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('finance_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('number')->unique();
            $table->string('type')->default('invoice')->index();
            $table->string('status')->default('draft')->index();
            $table->string('customer_name');
            $table->string('customer_tax_number')->nullable();
            $table->string('customer_registration_number')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_email')->nullable()->index();
            $table->text('description');
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->unsignedInteger('subtotal_denars')->default(0);
            $table->unsignedInteger('discount_denars')->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->unsignedInteger('tax_denars')->default(0);
            $table->unsignedInteger('total_denars')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('finance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('vendor_name');
            $table->string('vendor_tax_number')->nullable();
            $table->string('vendor_registration_number')->nullable();
            $table->text('description');
            $table->date('expense_date')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->string('status')->default('unpaid')->index();
            $table->unsignedInteger('subtotal_denars')->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->unsignedInteger('tax_denars')->default(0);
            $table->unsignedInteger('total_denars')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('document_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('related_ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->foreignId('related_ad_invoice_id')->nullable()->constrained('ad_invoices')->nullOnDelete();
            $table->foreignId('related_finance_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
        Schema::dropIfExists('finance_invoices');
        Schema::dropIfExists('finance_categories');
    }
};
