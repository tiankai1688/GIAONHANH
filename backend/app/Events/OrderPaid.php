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
 * Fired when an order transitions to `paid`. The merchant who owns the order
 * receives it on their private channel so their app can chime immediately
 * instead of polling.
 */
class OrderPaid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('merchant.' . $this->order->merchant_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'order_no' => $this->order->order_no,
            'amount'   => (float) $this->order->amount,
            'status'   => $this->order->status,
        ];
    }
}
