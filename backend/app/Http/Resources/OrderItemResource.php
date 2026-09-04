<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'name_zh'  => $this->name_zh,
            'price'    => (float) $this->price,
            'qty'      => $this->qty,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}
