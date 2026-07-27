<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()
            ->where('name', $role)
            ->exists();
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && ($this->hasRole('admin') || $this->hasRole('super_admin'));
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isActive() && $this->roles()->exists();
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, ['en', 'mk'], true) ? $this->locale : 'en';
    }

    public function createdArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'created_by');
    }

    public function updatedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'updated_by');
    }

    public function uploadedMediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'uploaded_by');
    }

    public function adCustomer(): HasOne
    {
        return $this->hasOne(AdCustomer::class);
    }

    public function teamMember(): HasOne
    {
        return $this->hasOne(TeamMember::class);
    }

    public function editorialSupervisions(): HasMany
    {
        return $this->hasMany(EditorialSupervision::class, 'editor_id');
    }

    public function supervisedAuthors(): HasMany
    {
        return $this->hasMany(EditorialSupervision::class, 'editor_id')
            ->active()
            ->where('is_primary', true);
    }

    public function assignedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'assigned_editor_id');
    }

    public function supervisedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'supervising_editor_id');
    }

    public function submittedAdCreativeRevisions(): HasMany
    {
        return $this->hasMany(AdCreativeRevision::class, 'submitted_by');
    }

    public function reviewedAdCreativeRevisions(): HasMany
    {
        return $this->hasMany(AdCreativeRevision::class, 'reviewed_by');
    }

    public function createdFinanceInvoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class, 'created_by');
    }

    public function updatedFinanceInvoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class, 'updated_by');
    }

    public function createdFinanceExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'created_by');
    }

    public function updatedFinanceExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'updated_by');
    }
}
