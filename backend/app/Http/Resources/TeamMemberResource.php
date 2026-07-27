<?php

namespace App\Http\Resources;

use App\Services\Authors\AuthorAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'display_title' => $this->display_title,
            'short_bio' => $this->short_bio,
            'full_bio' => $this->full_bio,
            'sex' => $this->sex,
            'profile_image' => app(AuthorAvatarResolver::class)->forTeamMember($this->resource, $request),
            'email' => $this->email,
            'member_type' => $this->member_type,
            'is_ai' => $this->member_type === 'ai',
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'joined_at' => $this->joined_at?->toDateString(),
            'social_links' => $this->social_links ?? [],
            'metadata' => $this->metadata ?? [],
            'roles' => TeamRoleResource::collection($this->whenLoaded('roles')),
            'primary_department' => $this->whenLoaded('primaryDepartment', fn () => $this->primaryDepartment ? [
                'id' => $this->primaryDepartment->id,
                'name' => $this->primaryDepartment->name,
                'slug' => $this->primaryDepartment->slug,
                'url' => "/tim#{$this->primaryDepartment->slug}",
            ] : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
                'slug' => $this->manager->slug,
                'url' => "/tim#{$this->manager->slug}",
            ] : null),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'slug' => $this->author->slug,
                'job_title' => $this->author->job_title,
                'url' => "/profil/{$this->author->slug}",
            ] : null),
            'url' => "/tim#{$this->slug}",
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
