<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $steps = Order::trackingSteps();
        $current = $this->status === 'cancelled'
            ? -1
            : (array_search($this->status, $steps, true) ?: 0);

        return [
            'order_no'           => $this->order_no,
            'type'               => $this->type,
            'parent_order_no'    => $this->parent_order_no,
            'rider_id'           => $this->rider_id,
            'status'             => $this->status,
            'cancelled'          => $this->status === 'cancelled',
            'tracking'           => [
                'steps'        => $steps,
                'current_index'=> $current,
            ],
            'product_amount'     => (float) $this->product_amount,
            'delivery_fee'       => (float) $this->delivery_fee,
            'group_delivery_fee' => $this->group_delivery_fee !== null ? (float) $this->group_delivery_fee : null,
            'coupon_discount'    => (float) $this->coupon_discount,
            // INTERNAL financial口径 — never expose to customers/riders.
            // platform_subsidy is the platform's own cost (delivery + new-user
            // coupon it funds): admin-only. commission / merchant_settlement are
            // a merchant's payout figures: visible to merchant + admin only.
            'platform_subsidy'   => $this->when(
                $request->user()?->role === 'admin',
                (float) $this->platform_subsidy
            ),
            'commission'         => $this->when(
                in_array($request->user()?->role, ['merchant', 'admin'], true),
                (float) $this->commission
            ),
            'amount'             => (float) $this->amount,
            'merchant_settlement'=> $this->when(
                in_array($request->user()?->role, ['merchant', 'admin'], true),
                (float) $this->merchant_settlement
            ),
            'delivery_type'      => $this->delivery_type,
            'expect_time'        => $this->expect_time,
            'pay_method'         => $this->pay_method,
            'paid_at'            => $this->paid_at,
            'address'            => $this->address,
            'lat'                => $this->lat,
            'lng'                => $this->lng,
            'contact_name'       => $this->contact_name,
            'contact_phone'      => $this->contact_phone,
            'note'               => $this->note,
            'created_at'         => $this->created_at,
            'merchant'           => $this->whenLoaded('merchant', fn () =>
                $this->merchant ? new MerchantResource($this->merchant) : null),
            'rider'              => $this->whenLoaded('rider', fn () => [
                'id'     => $this->rider->id,
                'name'   => $this->rider->name,
                'phone'  => $this->rider->phone,
                'vehicle'=> $this->rider->vehicle,
                'lat'    => $this->rider->lat,
                'lng'    => $this->rider->lng,
                'rating' => (float) $this->rider->rating,
            ]),
            'items'              => OrderItemResource::collection($this->whenLoaded('items')),
            // P0: child sub-orders of a merged parent (not recursed for subs).
            'sub_orders'         => $this->when($this->type === 'merged', function () {
                return OrderResource::collection($this->subOrders()->with('items', 'merchant')->get());
            }),
        ];
    }
}
