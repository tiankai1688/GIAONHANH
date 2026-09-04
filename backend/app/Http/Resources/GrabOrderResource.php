<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for the public rider GRAB feed (GET /rider/orders).
 *
 * Unlike OrderResource (used for an already-claimed order, where the rider
 * legitimately needs the customer's contact to deliver), this feed MUST NOT
 * leak the customer's full PII to every rider polling it. Before a rider
 * accepts an order they only need the drop-off location + merchant; the
 * customer's name is withheld and the phone is masked. Full PII is revealed
 * only via OrderResource after the rider claims the order.
 *
 * This closes red-team-review hacker #1 (nationwide PII drag via /rider/orders).
 */
class GrabOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_no'        => $this->order_no,
            'type'            => $this->type,
            'status'          => $this->status,
            'product_amount'  => (float) $this->product_amount,
            'delivery_fee'    => (float) $this->delivery_fee,
            'amount'          => (float) $this->amount,
            'address'         => $this->address,
            'lat'             => $this->lat,
            'lng'             => $this->lng,
            'note'            => $this->note,
            'distance_km'     => $this->distance_km ?? null,
            'merchant'        => $this->whenLoaded('merchant', fn () => [
                'id'      => $this->merchant->id,
                'name'    => $this->merchant->name,
                'address' => $this->merchant->address,
                'lat'     => $this->merchant->lat,
                'lng'     => $this->merchant->lng,
            ]),
            // Customer contact is WITHHELD until the rider accepts the order.
            'contact'         => [
                'name' => null,
                'phone' => $this->maskPhone($this->contact_phone),
            ],
            'items'           => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }

    /**
     * Mask all but the last 3 digits of a phone number, e.g. 0901234567 → 0*********567.
     * Reveals just enough for a rider to confirm they are calling the right
     * customer after accept, without exposing the full number in the feed.
     */
    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) <= 3) {
            return $phone;
        }
        $len = strlen($phone);
        return str_repeat('*', $len - 3) . substr($phone, -3);
    }
}
