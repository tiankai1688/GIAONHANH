<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Rider;
use App\Services\PaymentSplitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Authorize an order action via the OrderPolicy (single source of truth for
     * the "customer owns / merchant owns shop" rule). The `$ability` maps to a
     * method on App\Policies\OrderPolicy (view / update / cancel), all of which
     * enforce the same ownership check. Used by Order & Payment controllers.
     */
    protected function authorizeOrder(Request $request, Order $order, string $ability = 'view'): void
    {
        $this->authorize($ability, $order);
    }

    /**
     * Dispatch the nearest available rider to an order (haversine, nearest first).
     */
    protected function dispatchRider(Order $order): void
    {
        if (! $order->lat || ! $order->lng) {
            return;
        }
        $rider = Rider::available()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get()
            ->sortBy(fn (Rider $r) => PaymentSplitService::distance(
                (float) $r->lat, (float) $r->lng, (float) $order->lat, (float) $order->lng
            ))
            ->first();

        if ($rider) {
            $rider->update(['status' => 'busy', 'current_order_id' => $order->id]);
            $order->update(['rider_id' => $rider->id]);
        }
    }
}
