<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'logo'            => $this->logo,
            'rating'          => (float) $this->rating,
            'avg_delivery_min'=> $this->avg_delivery_min,
            'delivery_fee'    => (float) $this->delivery_fee,
            'min_order'       => (float) $this->min_order,
            'is_open'         => (bool) $this->is_open,
            'business_hours'  => $this->business_hours,
            'monthly_sales'   => $this->monthly_sales,
            'category'        => new CategoryResource($this->whenLoaded('category')),
            'address'         => $this->address,
            'lat'             => $this->lat,
            'lng'             => $this->lng,
            'distance_km'     => $this->whenNotNull($this->distance_km ?? null),
        ];
    }
}
