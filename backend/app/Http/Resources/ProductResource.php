<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name_vi'        => $this->name_vi,
            'name_zh'        => $this->name_zh,
            'description'    => $this->description,
            'price'          => (float) $this->price,
            'original_price' => $this->original_price ? (float) $this->original_price : null,
            'image'          => $this->image,
            'stock'          => $this->stock,
            'is_flash'       => (bool) $this->is_flash,
            'flash_price'    => $this->flash_price ? (float) $this->flash_price : null,
            'flash_stock'    => $this->flash_stock,
            'sales'          => $this->sales,
            'effective_price'=> $this->effectivePrice(),
        ];
    }
}
