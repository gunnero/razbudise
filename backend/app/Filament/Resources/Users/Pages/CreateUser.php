<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Newsroom\NewsroomNotificationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        if ($this->selectedRolesAreAllowed()) {
            return;
        }

        Notification::make()
            ->title('Cannot assign that role')
            ->body('Editor-in-Chief may create only Editor, Author, or Contributor accounts.')
            ->danger()
            ->send();

        throw new Halt;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;

        app(NewsroomNotificationService::class)->accountCreated($user);
    }

    private function selectedRolesAreAllowed(): bool
    {
        $user = auth()->user();

        if ($user?->hasPermission('users.manage')) {
            return true;
        }

        if (! ($user?->hasPermission('editorial.users.manage') ?? false)) {
            return false;
        }

        $selectedRoleNames = Role::query()
            ->whereIn('id', collect($this->data['roles'] ?? [])->map(fn ($id): string => (string) $id))
            ->pluck('name');

        return $selectedRoleNames->isNotEmpty()
            && $selectedRoleNames->every(fn (string $role): bool => in_array($role, ['editor', 'author', 'contributor'], true));
    }
}
