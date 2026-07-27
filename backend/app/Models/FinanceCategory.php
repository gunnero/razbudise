<?php

namespace App\Models;

use Database\Factories\FinanceCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    /** @use HasFactory<FinanceCategoryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->whereIn('type', ['income', 'both']);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->whereIn('type', ['expense', 'both']);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class);
    }
}
