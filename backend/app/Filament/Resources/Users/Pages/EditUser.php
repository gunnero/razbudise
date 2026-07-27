<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Newsroom\NewsroomNotificationService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array{status: string|null, name: string, email: string, roles: list<int>, password: bool}|null */
    private ?array $accountStateBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->record;
        $this->accountStateBeforeSave = [
            'status' => $user->status,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles()->orderBy('roles.id')->pluck('roles.id')->map(fn (int $id): int => $id)->all(),
            'password' => filled($this->data['password'] ?? null),
        ];

        $this->guardEditorialRoleBoundary();

        if (! $user->isAdmin() || $this->anotherActiveAdministratorExists($user)) {
            return;
        }

        $roleIds = collect($this->data['roles'] ?? []);
        $adminRoleIds = Role::query()
            ->whereIn('name', ['admin', 'super_admin'])
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id);

        $keepsAdminRole = $roleIds
            ->map(fn ($id): string => (string) $id)
            ->intersect($adminRoleIds)
            ->isNotEmpty();

        $keepsActiveStatus = ($this->data['status'] ?? $user->status ?? 'active') === 'active';

        if ($keepsAdminRole && $keepsActiveStatus) {
            return;
        }

        Notification::make()
            ->title('Cannot remove the final active Administrator')
            ->body('Create or assign another active Administrator before changing this account.')
            ->danger()
            ->send();

        throw new Halt;
    }

    protected function afterSave(): void
    {
        if ($this->accountStateBeforeSave === null) {
            return;
        }

        /** @var User $user */
        $user = $this->record->refresh();
        $changes = [];

        foreach (['status', 'name', 'email'] as $field) {
            if ($this->accountStateBeforeSave[$field] !== $user->{$field}) {
                $changes[] = $field;
            }
        }

        $currentRoles = $user->roles()->orderBy('roles.id')->pluck('roles.id')->map(fn (int $id): int => $id)->all();

        if ($this->accountStateBeforeSave['roles'] !== $currentRoles) {
            $changes[] = 'roles';
        }

        if ($this->accountStateBeforeSave['password']) {
            $changes[] = 'password';
        }

        app(NewsroomNotificationService::class)->accountUpdated($user, $changes);
    }

    private function guardEditorialRoleBoundary(): void
    {
        $actor = auth()->user();

        if ($actor?->hasPermission('users.manage')) {
            return;
        }

        if (! ($actor?->hasPermission('editorial.users.manage') ?? false)) {
            return;
        }

        $selectedRoleNames = Role::query()
            ->whereIn('id', collect($this->data['roles'] ?? [])->map(fn ($id): string => (string) $id))
            ->pluck('name');

        $allowed = $selectedRoleNames->isNotEmpty()
            && $selectedRoleNames->every(fn (string $role): bool => in_array($role, ['editor', 'author', 'contributor'], true));

        if ($allowed) {
            return;
        }

        Notification::make()
            ->title('Cannot assign that role')
            ->body('Editor-in-Chief may manage only Editor, Author, or Contributor accounts.')
            ->danger()
            ->send();

        throw new Halt;
    }

    private function anotherActiveAdministratorExists(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
            ->exists();
    }
}
