<?php

namespace App\Models;

use Database\Factories\AdInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdInvoice extends Model
{
    /** @use HasFactory<AdInvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'tax_rate' => 'decimal:2',
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
            ->where('status', 'issued')
            ->whereNull('paid_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', 'issued')
            ->whereNull('paid_at')
            ->where('due_at', '<', now());
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(AdBookingRequest::class, 'ad_booking_request_id');
    }

    public function financeExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'related_ad_invoice_id');
    }
}
