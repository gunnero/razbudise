<?php

namespace App\Services\Team;

use App\Models\Author;
use App\Models\MenuItem;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RazbudiseUserTeamProfileLinker
{
    /**
     * @return array{members: Collection<string, TeamMember>, menu_items_updated: int}
     */
    public function sync(): array
    {
        return DB::transaction(function (): array {
            $members = collect();

            foreach ($this->profiles() as $profile) {
                $user = User::query()->where('email', $profile['email'])->first();

                if (! $user) {
                    continue;
                }

                $author = $this->matchedAuthor($profile, $user);
                $department = TeamDepartment::query()->where('group_key', $profile['department_group_key'])->first();
                $member = $this->memberFor($profile, $user, $author);

                $member->fill($this->safeMemberData($member, $profile, $user, $author, $department));
                $member->save();

                $roleIds = TeamRole::query()
                    ->whereIn('slug', $profile['position_slugs'])
                    ->pluck('id')
                    ->all();

                if ($roleIds !== []) {
                    $member->roles()->syncWithoutDetaching($roleIds);
                }

                if ($author && blank($author->email)) {
                    $author->forceFill(['email' => $user->email])->save();
                }

                $members->put($profile['email'], $member->refresh());
            }

            return [
                'members' => $members,
                'menu_items_updated' => $this->renameAuthorDirectoryMenuItems(),
            ];
        });
    }

    /**
     * @return array<int, array{email: string, slug: string, author_slug: string|null, department_group_key: string, position_slugs: array<int, string>, fallback_title: string, sort_order: int}>
     */
    public function profiles(): array
    {
        return [
            ['email' => 'founder@example.test', 'slug' => 'founder', 'author_slug' => 'founder', 'department_group_key' => 'technology', 'position_slugs' => ['osnovac', 'glaven-urednik', 'tehnologija-razvoj'], 'fallback_title' => 'Основач, главен уредник и технологија', 'sort_order' => 10],
            ['email' => 'editor@example.test', 'slug' => 'editor', 'author_slug' => 'editor', 'department_group_key' => 'editorial', 'position_slugs' => ['urednik', 'avtor'], 'fallback_title' => 'Уредник и автор', 'sort_order' => 20],
            ['email' => 'author-one@example.test', 'slug' => 'author-one', 'author_slug' => 'author-one', 'department_group_key' => 'editorial', 'position_slugs' => ['avtor'], 'fallback_title' => 'Автор', 'sort_order' => 30],
            ['email' => 'author-two@example.test', 'slug' => 'author-two', 'author_slug' => 'author-two', 'department_group_key' => 'editorial', 'position_slugs' => ['avtor'], 'fallback_title' => 'Автор', 'sort_order' => 40],
        ];
    }

    /**
     * @param  array{email: string, slug: string, author_slug: string|null, department_group_key: string, position_slugs: array<int, string>, fallback_title: string, sort_order: int}  $profile
     */
    private function matchedAuthor(array $profile, User $user): ?Author
    {
        return Author::query()
            ->where('email', $user->email)
            ->when($profile['author_slug'], fn ($query, string $slug) => $query->orWhere('slug', $slug))
            ->first();
    }

    /**
     * @param  array{email: string, slug: string, author_slug: string|null, department_group_key: string, position_slugs: array<int, string>, fallback_title: string, sort_order: int}  $profile
     */
    private function memberFor(array $profile, User $user, ?Author $author): TeamMember
    {
        return TeamMember::query()
            ->where('user_id', $user->id)
            ->when($author, fn ($query) => $query->orWhere('author_id', $author->id))
            ->orWhere('email', $user->email)
            ->orWhere('slug', $profile['slug'])
            ->first()
            ?? new TeamMember(['slug' => $profile['slug']]);
    }

    /**
     * @param  array{email: string, slug: string, author_slug: string|null, department_group_key: string, position_slugs: array<int, string>, fallback_title: string, sort_order: int}  $profile
     * @return array<string, mixed>
     */
    private function safeMemberData(TeamMember $member, array $profile, User $user, ?Author $author, ?TeamDepartment $department): array
    {
        $socialLinks = $member->social_links ?: [];

        foreach ([
            'website' => $author?->website_url,
            'facebook' => $author?->facebook_url,
            'instagram' => $author?->instagram_url,
            'linkedin' => $author?->linkedin_url,
            'x' => $author?->x_url,
        ] as $key => $value) {
            if (blank(Arr::get($socialLinks, $key)) && filled($value)) {
                $socialLinks[$key] = $value;
            }
        }

        return [
            'name' => $member->exists && filled($member->name) ? $member->name : ($author?->name ?: $user->name),
            'slug' => $member->exists && filled($member->slug) ? $member->slug : $profile['slug'],
            'display_title' => filled($member->display_title) ? $member->display_title : ($author?->job_title ?: $profile['fallback_title']),
            'short_bio' => filled($member->short_bio) ? $member->short_bio : $author?->short_bio,
            'full_bio' => filled($member->full_bio) ? $member->full_bio : $author?->full_bio,
            'profile_image_id' => $member->profile_image_id ?: $author?->avatar_id,
            'sex' => $member->sex ?: $author?->sex,
            'email' => $member->email ?: $user->email,
            'member_type' => $member->member_type ?: 'human',
            'status' => $member->status ?: 'active',
            'sort_order' => $member->sort_order ?: $profile['sort_order'],
            'social_links' => $socialLinks,
            'user_id' => $member->user_id ?: $user->id,
            'author_id' => $member->author_id ?: $author?->id,
            'primary_department_id' => $member->primary_department_id ?: $department?->id,
        ];
    }

    private function renameAuthorDirectoryMenuItems(): int
    {
        return MenuItem::query()
            ->whereIn('url', ['/avtor', '/tim'])
            ->whereIn('title', ['Автори', 'Авторы', 'Authors'])
            ->update(['title' => 'Тим', 'url' => '/tim']);
    }
}
