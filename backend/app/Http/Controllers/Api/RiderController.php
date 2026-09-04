<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Rider;
use App\Services\PaymentSplitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RiderController extends Controller
{
    /**
     * Orders awaiting pickup near the rider (assigned to me, or unassigned & packed).
     *
     * Security (red-team hacker #1): unassigned "picked" orders are filtered to
     * those whose MERCHANT lies within grab_radius_km of the rider's live
     * position (bounding-box filter — SQLite/MySQL portable, no trig needed in
     * SQL). Without a position, NO unassigned orders are returned (only the
     * rider's own), so the feed can never become a nationwide PII drag. The
     * list is paginated and serialized through GrabOrderResource, which withholds
     * the customer name and masks the phone until the rider claims the order.
     */
    public function nearby(Request $request)
    {
        $rider = $request->user()->rider;
        if (! $rider) {
            return GrabOrderResource::collection(collect());
        }

        // SECURITY (red-team S1): the geofence center is the rider's STORED
        // position (written by the trusted updateLocation endpoint from the
        // device GPS), NEVER the client-supplied ?lat=&lng=. Trusting the client
        // would let a malicious rider sweep the whole country by replaying grid
        // coordinates and enumerate every unassigned order's customer PII. The
        // client lat/lng is used ONLY for the cosmetic distance label below.
        $fenceLat = $rider->lat;
        $fenceLng = $rider->lng;
        $displayLat = $request->query('lat', $fenceLat);
        $displayLng = $request->query('lng', $fenceLng);
        $radius = (float) config('rider.grab_radius_km', 10);

        $query = Order::with('items', 'merchant')
            ->whereIn('status', ['paid', 'accepted', 'picked']);

        if ($fenceLat && $fenceLng) {
            // Bounding box (degrees). cos(lat) never 0 in Vietnam; floor it
            // defensively so a degenerate radius can't divide by zero.
            $dLat = $radius / 111.0;
            $dLng = $radius / (111.0 * max(cos(deg2rad((float) $fenceLat)), 0.01));
            $query->where(function ($q) use ($rider, $fenceLat, $fenceLng, $dLat, $dLng) {
                // The rider's own active orders are always visible (no fence).
                $q->where('rider_id', $rider->id);
                // Unassigned picked orders only if the merchant is within radius
                // of the rider's STORED position.
                $q->orWhere(function ($q2) use ($fenceLat, $fenceLng, $dLat, $dLng) {
                    $q2->whereNull('rider_id')->where('status', 'picked')
                        ->whereHas('merchant', fn ($m) => $m
                            ->whereBetween('lat', [$fenceLat - $dLat, $fenceLat + $dLat])
                            ->whereBetween('lng', [$fenceLng - $dLng, $fenceLng + $dLng]));
                });
            });
        } else {
            // No stored position → only the rider's own orders (no PII blast radius).
            $query->where('rider_id', $rider->id);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate((int) config('rider.grab_page_size', 30));

        if ($displayLat && $displayLng) {
            $orders->getCollection()->each(function (Order $o) use ($displayLat, $displayLng) {
                if ($o->merchant?->lat && $o->merchant?->lng) {
                    $o->distance_km = round(PaymentSplitService::distance(
                        (float)$displayLat, (float)$displayLng, (float)$o->merchant->lat, (float)$o->merchant->lng), 2);
                }
            });
        }

        return GrabOrderResource::collection($orders);
    }

    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);
        $rider = $request->user()->rider;
        $rider->update($data);
        return response()->json(['ok' => true, 'rider' => $rider->only('id', 'lat', 'lng', 'status')]);
    }

    /**
     * The rider's single in-progress delivery (status = delivering & assigned to me).
     * Used by the 配送中 (active) screen so the active order survives reloads.
     */
    public function current(Request $request)
    {
        $rider = $request->user()->rider;
        if (! $rider) {
            return response()->json(['order' => null]);
        }
        $order = Order::with('items', 'merchant', 'user')
            ->where('rider_id', $rider->id)
            ->where('status', 'delivering')
            ->latest()
            ->first();

        return $order ? new OrderResource($order) : response()->json(['order' => null]);
    }

    public function accept(Request $request, Order $order)
    {
        $rider = $request->user()->rider;
        if (! $rider) {
            return response()->json(['message' => 'Chưa có hồ sơ shipper.'], 404);
        }
        if ($rider->status === 'busy') {
            return response()->json(['message' => 'Bạn đang giao đơn khác.'], 409);
        }

        // Serialise the claim under a row lock so two riders cannot grab the
        // same order concurrently (the previous read-then-write had a TOCTOU
        // race that allowed one order to be assigned to two riders).
        return DB::transaction(function () use ($order, $rider) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($locked->rider_id && $locked->rider_id !== $rider->id) {
                return response()->json(['message' => 'Đơn đã được shipper khác nhận.'], 409);
            }
            if (! in_array($locked->status, ['paid', 'accepted', 'picked'])) {
                return response()->json(['message' => 'Đơn không sẵn sàng.'], 422);
            }
            $locked->update([
                'rider_id'      => $rider->id,
                'status'        => 'delivering',
                'delivering_at' => now(),
            ]);
            $rider->update(['status' => 'busy', 'current_order_id' => $locked->id]);

            return new OrderResource($locked->load('items', 'merchant', 'user'));
        });
    }

    public function deliver(Request $request, Order $order)
    {
        $rider = $request->user()->rider;
        if ($order->rider_id !== $rider->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->status !== 'delivering') {
            return response()->json(['message' => 'Đơn chưa trên đường giao.'], 422);
        }

        // #8 hardening: when the rider app reports its live GPS at hand-off,
        // reject completion if it is not near the delivery address (anti-fraud /
        // false-completion guard). Coordinates are optional so offline/demo and
        // legacy orders without a stored address still complete normally.
        $data = $request->validate([
            'lat' => ['sometimes', 'numeric'],
            'lng' => ['sometimes', 'numeric'],
        ]);
        if (isset($data['lat'], $data['lng']) && $order->lat && $order->lng) {
            $dist = PaymentSplitService::distance(
                (float) $data['lat'], (float) $data['lng'],
                (float) $order->lat, (float) $order->lng
            );
            if ($dist > 0.3) {
                return response()->json([
                    'message' => 'Bạn chưa đến địa chỉ giao hàng.',
                    'distance_km' => round($dist, 2),
                ], 422);
            }
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now()]);
        $rider->update(['status' => 'online', 'current_order_id' => null]);

        return new OrderResource($order->load('items', 'merchant', 'user'));
    }

    /**
     * Rider profile + today's stats (completed count & earnings).
     */
    public function profile(Request $request)
    {
        $rider = $request->user()->rider;
        if (! $rider) {
            return response()->json(['message' => 'Chưa có hồ sơ shipper.'], 404);
        }
        $today = now()->startOfDay();
        $doneToday = Order::where('rider_id', $rider->id)
            ->where('status', 'delivered')
            ->where('delivered_at', '>=', $today)
            ->get();

        return response()->json([
            'id'               => $rider->id,
            'name'             => $rider->name,
            'phone'            => $rider->phone,
            'vehicle'          => $rider->vehicle,
            'status'           => $rider->status,
            'lat'              => $rider->lat,
            'lng'              => $rider->lng,
            'rating'           => $rider->rating,
            'today_completed'  => $doneToday->count(),
            'today_earnings'   => (float) $doneToday->sum('delivery_fee'),
        ]);
    }

    /**
     * Toggle online / offline (and optionally update live position).
     */
    public function updateProfile(Request $request)
    {
        $rider = $request->user()->rider;
        if (! $rider) {
            return response()->json(['message' => 'Chưa có hồ sơ shipper.'], 404);
        }
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['online', 'offline', 'busy'])],
            'lat'    => ['sometimes', 'numeric'],
            'lng'    => ['sometimes', 'numeric'],
        ]);
        $rider->update($data);
        return response()->json(['ok' => true, 'rider' => $rider->only('id', 'status', 'lat', 'lng')]);
    }
}
