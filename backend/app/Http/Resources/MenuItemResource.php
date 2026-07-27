<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'link_type' => $this->link_type,
            'url' => $this->resolvedUrl(),
            'opens_in_new_tab' => $this->opens_in_new_tab,
            'sort_order' => $this->sort_order,
            'children' => MenuItemResource::collection($this->whenLoaded('children')),
        ];
    }
}
