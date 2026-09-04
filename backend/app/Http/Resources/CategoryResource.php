<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name_vi'  => $this->name_vi,
            'name_zh'  => $this->name_zh,
            'icon'     => $this->icon,
            'type'     => $this->type,
            'sort'     => $this->sort,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
