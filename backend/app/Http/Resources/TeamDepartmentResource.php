<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'group_key' => $this->group_key,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'parent_department_id' => $this->parent_department_id,
            'manager' => TeamMemberResource::make($this->whenLoaded('manager')),
            'members' => TeamMemberResource::collection($this->whenLoaded('members')),
            'child_departments' => TeamDepartmentResource::collection($this->whenLoaded('childDepartments')),
            'metadata' => $this->metadata ?? [],
            'url' => "/tim/organizacija#{$this->slug}",
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
