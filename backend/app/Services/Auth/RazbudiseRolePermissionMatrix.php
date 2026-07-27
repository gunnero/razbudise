<?php

namespace App\Services\Auth;

use App\Models\AdminAuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RazbudiseRolePermissionMatrix
{
    /**
     * @return array<string, array{label: string}>
     */
    public function permissions(): array
    {
        return [
            'articles.view' => ['label' => 'View articles'],
            'articles.create' => ['label' => 'Create articles'],
            'articles.update' => ['label' => 'Update articles'],
            'articles.publish' => ['label' => 'Publish articles'],
            'articles.review' => ['label' => 'Review articles'],
            'articles.approve' => ['label' => 'Approve article changes'],
            'editorial.feedback' => ['label' => 'Send editorial feedback'],
            'media.manage' => ['label' => 'Manage media assets'],
            'taxonomies.manage' => ['label' => 'Manage categories and tags'],
            'authors.manage' => ['label' => 'Manage author and team profiles'],
            'editorial.users.manage' => ['label' => 'Manage editor and author accounts'],
            'analytics.view' => ['label' => 'View editorial analytics'],
            'seo.manage' => ['label' => 'Manage SEO metadata'],
            'ads.view' => ['label' => 'View ad reports'],
            'ads.manage' => ['label' => 'Manage ads'],
            'imports.view' => ['label' => 'View imports'],
            'imports.manage' => ['label' => 'Manage imports'],
            'finance.view' => ['label' => 'View finance center'],
            'finance.manage' => ['label' => 'Manage finance center'],
            'finance.export' => ['label' => 'Export finance reports'],
            'newsletter.manage' => ['label' => 'Manage newsletter subscribers'],
            'settings.manage' => ['label' => 'Manage site settings'],
            'users.manage' => ['label' => 'Manage users, roles, and permissions'],
        ];
    }

    /**
     * @return array<string, array{label: string, permissions: array<int, string>}>
     */
    public function roles(): array
    {
        $allPermissions = array_keys($this->permissions());
        $editorialPermissions = $this->editorialPermissionBundle();

        return [
            'super_admin' => [
                'label' => 'Супер администратор',
                'permissions' => $allPermissions,
            ],
            'admin' => [
                'label' => 'Администратор',
                'permissions' => $allPermissions,
            ],
            'editor_in_chief' => [
                'label' => 'Главен и одговорен уредник',
                'permissions' => [
                    ...$editorialPermissions,
                    'editorial.users.manage',
                ],
            ],
            'editor' => [
                'label' => 'Уредник',
                'permissions' => $editorialPermissions,
            ],
            'author' => [
                'label' => 'Автор',
                'permissions' => [
                    'articles.view',
                    'articles.create',
                    'articles.update',
                    'media.manage',
                    'analytics.view',
                ],
            ],
            'contributor' => [
                'label' => 'Соработник',
                'permissions' => [
                    'articles.view',
                    'articles.create',
                    'articles.update',
                    'media.manage',
                ],
            ],
            'photographer' => [
                'label' => 'Фотограф',
                'permissions' => [
                    'media.manage',
                ],
            ],
            'media_manager' => [
                'label' => 'Менаџер на медиуми',
                'permissions' => [
                    'media.manage',
                ],
            ],
            'ad_manager' => [
                'label' => 'Менаџер за реклами',
                'permissions' => [
                    'ads.view',
                    'ads.manage',
                    'media.manage',
                ],
            ],
            'accountant' => [
                'label' => 'Сметководител',
                'permissions' => [
                    'finance.view',
                    'finance.manage',
                    'finance.export',
                ],
            ],
            'import_manager' => [
                'label' => 'Import manager',
                'permissions' => [
                    'imports.view',
                    'imports.manage',
                    'articles.view',
                    'media.manage',
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function editorialPermissionBundle(): array
    {
        return [
            'articles.view',
            'articles.create',
            'articles.update',
            'articles.review',
            'articles.approve',
            'articles.publish',
            'editorial.feedback',
            'media.manage',
            'taxonomies.manage',
            'authors.manage',
            'analytics.view',
            'seo.manage',
        ];
    }

    /**
     * @return array{permissions: int, roles: int}
     */
    public function sync(): array
    {
        return DB::transaction(function (): array {
            $permissions = $this->syncPermissions();
            $roles = $this->syncRoles($permissions);

            return [
                'permissions' => $permissions->count(),
                'roles' => $roles->count(),
            ];
        });
    }

    /**
     * @return Collection<string, Permission>
     */
    private function syncPermissions(): Collection
    {
        return collect($this->permissions())
            ->mapWithKeys(function (array $definition, string $name): array {
                $permission = Permission::query()->firstOrNew(['name' => $name]);
                $before = $permission->exists ? $permission->only(['name', 'label']) : null;

                $permission->fill(['label' => $definition['label']]);
                $permission->save();

                if ($before !== $permission->only(['name', 'label'])) {
                    $this->audit($permission, $permission->wasRecentlyCreated ? 'permission.created' : 'permission.updated', $before, $permission->only(['name', 'label']));
                }

                return [$name => $permission->refresh()];
            });
    }

    /**
     * @param  Collection<string, Permission>  $permissions
     * @return Collection<string, Role>
     */
    private function syncRoles(Collection $permissions): Collection
    {
        return collect($this->roles())
            ->mapWithKeys(function (array $definition, string $name) use ($permissions): array {
                $role = Role::query()->firstOrNew(['name' => $name]);
                $before = $role->exists ? $role->only(['name', 'label']) : null;

                $role->fill(['label' => $definition['label']]);
                $role->save();

                $permissionIds = collect($definition['permissions'])
                    ->map(fn (string $permission): int => $permissions[$permission]->id)
                    ->sort()
                    ->values()
                    ->all();

                $beforePermissions = $role->permissions()->pluck('permissions.name')->sort()->values()->all();
                $role->permissions()->sync($permissionIds);
                $afterPermissions = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

                if ($before !== $role->only(['name', 'label'])) {
                    $this->audit($role, $role->wasRecentlyCreated ? 'role.created' : 'role.updated', $before, $role->only(['name', 'label']));
                }

                if ($beforePermissions !== $afterPermissions) {
                    $this->audit($role, 'role.permissions_synced', ['permissions' => $beforePermissions], ['permissions' => $afterPermissions]);
                }

                return [$name => $role->refresh()];
            });
    }

    private function audit(Model $model, string $action, ?array $before, ?array $after): void
    {
        AdminAuditLog::query()->create([
            'actor_id' => auth()->id(),
            'auditable_type' => $model::class,
            'auditable_id' => $model->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
