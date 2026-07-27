<?php

namespace App\Models;

use Database\Factories\FinanceInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceInvoice extends Model
{
    /** @use HasFactory<FinanceInvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'subtotal_denars' => 'integer',
            'discount_denars' => 'integer',
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

    public function scopeReceivable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['issued', 'overdue'])
            ->whereNull('paid_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['issued', 'overdue'])
            ->whereNull('paid_at')
            ->where('due_at', '<', now());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'related_finance_invoice_id');
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
