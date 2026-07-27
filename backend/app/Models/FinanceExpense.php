<?php

namespace App\Models;

use Database\Factories\FinanceExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceExpense extends Model
{
    /** @use HasFactory<FinanceExpenseFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'subtotal_denars' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_denars' => 'integer',
            'total_denars' => 'integer',
        ];
    }

    public function scopeNotVoid(Builder $query): Builder
    {
        return $query->where('status', '!=', 'void');
    }

    public function scopePaidBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to]);
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNull('paid_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNull('paid_at')
            ->where('due_at', '<', now());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function financeInvoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'related_finance_invoice_id');
    }

    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'related_ad_campaign_id');
    }

    public function adInvoice(): BelongsTo
    {
        return $this->belongsTo(AdInvoice::class, 'related_ad_invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
