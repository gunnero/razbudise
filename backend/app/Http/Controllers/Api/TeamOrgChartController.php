<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamDepartmentResource;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TeamOrgChartController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $departments = TeamDepartment::query()
            ->active()
            ->with([
                'manager' => fn ($query) => $this->activeMemberQuery($query),
                'members' => fn ($query) => $this->activeMemberQuery($query),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $departmentsByParent = $departments->groupBy('parent_department_id');
        $topLevel = $departmentsByParent->get(null, collect())
            ->map(fn (TeamDepartment $department): TeamDepartment => $this->withChildren($department, $departmentsByParent))
            ->values();

        return response()->json([
            'data' => [
                'departments' => TeamDepartmentResource::collection($topLevel)->resolve($request),
            ],
        ]);
    }

    private function activeMemberQuery($query)
    {
        return $query
            ->active()
            ->with([
                'author',
                'profileImage',
                'roles' => fn ($rolesQuery) => $rolesQuery->active(),
                'primaryDepartment',
                'manager',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @param  Collection<int|string|null, Collection<int, TeamDepartment>>  $departmentsByParent
     */
    private function withChildren(TeamDepartment $department, Collection $departmentsByParent): TeamDepartment
    {
        $children = $departmentsByParent->get($department->id, collect())
            ->map(fn (TeamDepartment $child): TeamDepartment => $this->withChildren($child, $departmentsByParent))
            ->values();

        $department->setRelation('childDepartments', $children);
        $department->setRelation('members', $this->membersWithoutManager($department));

        return $department;
    }

    /**
     * @return Collection<int, TeamMember>
     */
    private function membersWithoutManager(TeamDepartment $department): Collection
    {
        return $department->members
            ->reject(fn (TeamMember $member): bool => (int) $member->id === (int) $department->manager_team_member_id)
            ->values();
    }
}
