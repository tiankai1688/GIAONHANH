<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a merchant marks an order `picked` (ready for pickup). In the
 * grab model the order is now unassigned and broadcast on the public
 * `orders.grab` channel; each nearby rider filters by distance on the client
 * and calls rider/orders/{order}/accept to claim it.
 */
class OrderReadyForGrab implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('orders.grab')];
    }

    public function broadcastWith(): array
    {
        return [
            'order_no' => $this->order->order_no,
            'merchant_id' => $this->order->merchant_id,
            'pickup'   => ['lat' => $this->order->merchant?->lat, 'lng' => $this->order->merchant?->lng],
            'dropoff' => ['lat' => $this->order->lat, 'lng' => $this->order->lng],
        ];
    }
}
