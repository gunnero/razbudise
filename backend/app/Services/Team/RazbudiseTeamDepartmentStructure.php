<?php

namespace App\Services\Team;

use App\Models\TeamDepartment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RazbudiseTeamDepartmentStructure
{
    /**
     * @return Collection<string, TeamDepartment>
     */
    public function sync(): Collection
    {
        return DB::transaction(function (): Collection {
            $departments = collect();

            foreach ($this->definitions() as $definition) {
                if ($definition['parent_group_key'] !== null) {
                    continue;
                }

                $departments->put(
                    $definition['group_key'],
                    $this->upsertDepartment($definition),
                );
            }

            foreach ($this->definitions() as $definition) {
                if ($definition['parent_group_key'] === null) {
                    continue;
                }

                $parent = $departments->get($definition['parent_group_key'])
                    ?? TeamDepartment::query()->where('group_key', $definition['parent_group_key'])->first();

                if (! $parent) {
                    throw new RuntimeException("Missing parent department for {$definition['group_key']}.");
                }

                $departments->put(
                    $definition['group_key'],
                    $this->upsertDepartment($definition, $parent),
                );
            }

            $this->assertValidStructure();

            return TeamDepartment::query()
                ->whereIn('group_key', array_column($this->definitions(), 'group_key'))
                ->orderBy('sort_order')
                ->get()
                ->keyBy('group_key');
        });
    }

    /**
     * @return array<int, array{name: string, slug: string, group_key: string, status: string, sort_order: int, parent_group_key: string|null}>
     */
    public function definitions(): array
    {
        return [
            ['name' => 'Редакција', 'slug' => 'editorial', 'group_key' => 'editorial', 'status' => 'active', 'sort_order' => 10, 'parent_group_key' => null],
            ['name' => 'Вести', 'slug' => 'news', 'group_key' => 'news', 'status' => 'active', 'sort_order' => 20, 'parent_group_key' => 'editorial'],
            ['name' => 'Битола и регион', 'slug' => 'bitola-region', 'group_key' => 'bitola-region', 'status' => 'active', 'sort_order' => 30, 'parent_group_key' => 'editorial'],
            ['name' => 'Култура и настани', 'slug' => 'culture-events', 'group_key' => 'culture-events', 'status' => 'active', 'sort_order' => 40, 'parent_group_key' => 'editorial'],
            ['name' => 'Живот и стил', 'slug' => 'lifestyle', 'group_key' => 'lifestyle', 'status' => 'active', 'sort_order' => 50, 'parent_group_key' => 'editorial'],
            ['name' => 'Бизнис и економија', 'slug' => 'business', 'group_key' => 'business', 'status' => 'active', 'sort_order' => 60, 'parent_group_key' => 'editorial'],
            ['name' => 'Фото и видео продукција', 'slug' => 'media-production', 'group_key' => 'media-production', 'status' => 'active', 'sort_order' => 70, 'parent_group_key' => null],
            ['name' => 'Социјални медиуми и публика', 'slug' => 'audience-social', 'group_key' => 'audience-social', 'status' => 'active', 'sort_order' => 80, 'parent_group_key' => null],
            ['name' => 'Продажба и рекламирање', 'slug' => 'sales-advertising', 'group_key' => 'sales-advertising', 'status' => 'active', 'sort_order' => 90, 'parent_group_key' => null],
            ['name' => 'Технологија и развој', 'slug' => 'technology', 'group_key' => 'technology', 'status' => 'active', 'sort_order' => 100, 'parent_group_key' => null],
        ];
    }

    /**
     * @param  array{name: string, slug: string, group_key: string, status: string, sort_order: int, parent_group_key: string|null}  $definition
     */
    private function upsertDepartment(array $definition, ?TeamDepartment $parent = null): TeamDepartment
    {
        $department = TeamDepartment::query()
            ->where('group_key', $definition['group_key'])
            ->first()
            ?? TeamDepartment::query()
                ->where('slug', $definition['slug'])
                ->where(function ($query): void {
                    $query->whereNull('group_key')->orWhere('group_key', '');
                })
                ->first()
            ?? new TeamDepartment;

        $department->fill([
            'name' => $definition['name'],
            'slug' => $definition['slug'],
            'group_key' => $definition['group_key'],
            'status' => $definition['status'],
            'sort_order' => $definition['sort_order'],
            'parent_department_id' => $parent?->id,
        ]);

        $metadata = $department->metadata ?: [];
        $metadata['razbudise_department_seeded'] = true;
        $department->metadata = $metadata;

        $department->save();

        return $department->refresh();
    }

    private function assertValidStructure(): void
    {
        $groupKeys = array_column($this->definitions(), 'group_key');

        $duplicates = TeamDepartment::query()
            ->select('group_key')
            ->whereIn('group_key', $groupKeys)
            ->groupBy('group_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('group_key')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException('Duplicate team department group keys: '.implode(', ', $duplicates));
        }

        $departments = TeamDepartment::query()
            ->whereIn('group_key', $groupKeys)
            ->get()
            ->keyBy('group_key');

        if ($departments->count() !== count($groupKeys)) {
            throw new RuntimeException('Not all required RazbudiSe departments were created.');
        }

        $editorial = $departments->get('editorial');

        foreach ($this->definitions() as $definition) {
            $department = $departments->get($definition['group_key']);

            if ($department->status !== 'active') {
                throw new RuntimeException("Department {$definition['group_key']} is not active.");
            }

            if ((int) $department->sort_order !== $definition['sort_order']) {
                throw new RuntimeException("Department {$definition['group_key']} has an invalid sort order.");
            }

            if ($definition['parent_group_key'] === 'editorial' && $department->parent_department_id !== $editorial?->id) {
                throw new RuntimeException("Department {$definition['group_key']} is not under Редакција.");
            }

            if ($department->id === $department->parent_department_id) {
                throw new RuntimeException("Department {$definition['group_key']} cannot be its own parent.");
            }
        }
    }
}
