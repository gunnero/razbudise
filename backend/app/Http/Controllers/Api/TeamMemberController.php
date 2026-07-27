<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Http\Resources\TeamRoleResource;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\TeamRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TeamMemberController extends Controller
{
    private const SECTIONS = [
        ['key' => 'founders', 'title' => 'Раководство', 'sort_order' => 10, 'role_slugs' => ['osnovac', 'co-owner']],
        ['key' => 'editor_in_chief', 'title' => 'Главен и одговорен уредник', 'sort_order' => 20, 'role_slugs' => ['glaven-urednik', 'editor-in-chief']],
        ['key' => 'editors', 'title' => 'Уредници', 'sort_order' => 30, 'role_slugs' => ['urednik', 'editor', 'managing-editor']],
        ['key' => 'authors', 'title' => 'Автори', 'sort_order' => 40, 'role_slugs' => ['avtor', 'author', 'novinar']],
        ['key' => 'contributors', 'title' => 'Соработници', 'sort_order' => 50, 'role_slugs' => ['contributor', 'research', 'fact-checker']],
        ['key' => 'photo_video', 'title' => 'Фото и видео', 'sort_order' => 60, 'role_slugs' => ['foto-video']],
        ['key' => 'operations', 'title' => 'Операции', 'sort_order' => 70, 'role_slugs' => ['socijalni-mediumi', 'prodazba-reklamiranje', 'tehnologija-razvoj', 'seo', 'marketing', 'social-media', 'creative-design']],
        ['key' => 'ai_crew', 'title' => 'AI Crew', 'sort_order' => 80, 'role_slugs' => ['ai-employee']],
    ];

    public function index(Request $request): JsonResponse
    {
        $members = TeamMember::query()
            ->active()
            ->with([
                'author.avatar',
                'manager',
                'profileImage',
                'primaryDepartment',
                'roles' => fn ($rolesQuery) => $rolesQuery->active(),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $departmentSections = $this->departmentSections($request);

        $sections = collect(self::SECTIONS)
            ->map(fn (array $section): array => $this->sectionPayload(
                $request,
                $section,
                $this->membersForSection($members, $section),
            ))
            ->values();

        return response()->json([
            'data' => [
                'sections' => $sections,
                'department_sections' => $departmentSections,
            ],
        ]);
    }

    private function departmentSections(Request $request): Collection
    {
        return TeamDepartment::query()
            ->active()
            ->with([
                'manager' => fn ($query) => $query->active(),
                'members' => fn ($query) => $query
                    ->active()
                    ->with([
                        'author.avatar',
                        'manager',
                        'profileImage',
                        'primaryDepartment',
                        'roles' => fn ($rolesQuery) => $rolesQuery->active(),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TeamDepartment $department): array => [
                'key' => $department->slug,
                'title' => $department->name,
                'sort_order' => $department->sort_order,
                'manager' => $department->manager
                    ? TeamMemberResource::make($department->manager)->resolve($request)
                    : null,
                'members' => TeamMemberResource::collection($department->members)->resolve($request),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, TeamMember>  $members
     */
    private function sectionPayload(Request $request, array $section, Collection $members): array
    {
        $roleSlugs = collect($section['role_slugs']);
        $roles = TeamRole::query()
            ->active()
            ->whereIn('slug', $roleSlugs)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->values();

        return [
            'key' => $section['key'],
            'title' => $section['title'],
            'sort_order' => $section['sort_order'],
            'roles' => TeamRoleResource::collection($roles->values())->resolve($request),
            'members' => TeamMemberResource::collection($members)->resolve($request),
        ];
    }

    /**
     * Assign each public member to one highest-priority section so authors are not mixed into leadership
     * just because they share the Editorial department.
     *
     * @param  Collection<int, TeamMember>  $members
     * @return Collection<int, TeamMember>
     */
    private function membersForSection(Collection $members, array $section): Collection
    {
        return $members
            ->filter(fn (TeamMember $member): bool => $this->sectionKeyFor($member) === $section['key'])
            ->sort(fn (TeamMember $first, TeamMember $second): int => [
                $first->sort_order,
                $first->name,
            ] <=> [
                $second->sort_order,
                $second->name,
            ])
            ->values();
    }

    private function sectionKeyFor(TeamMember $member): string
    {
        $slugs = $member->roles->pluck('slug')->all();

        foreach (self::SECTIONS as $section) {
            if (array_intersect($section['role_slugs'], $slugs) !== []) {
                return $section['key'];
            }
        }

        return 'operations';
    }
}
