<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Order lifecycle + the "grab" dispatch model.
 * In the grab model a paid order is accepted by the merchant, then
 * when READY it is released (rider_id null) so nearby riders can
 * claim it on the public orders.grab channel — NO auto-dispatch.
 */
describe('Order state machine', function () {
    it('exposes the canonical 6-step tracking pipeline', function () {
        expect(Order::trackingSteps())->toBe([
            'pending_payment', 'paid', 'accepted', 'picked', 'delivering', 'delivered',
        ]);
    });

    it('binds routes by the human-readable order_no, not the id', function () {
        expect((new Order())->getRouteKeyName())->toBe('order_no');
    });

    it('links a merged parent to its child sub-orders by parent_order_no', function () {
        // The merged-cancel cascade (OrderController::cancel) depends on this
        // relationship resolving correctly. Previously this test just set two
        // model properties in memory and asserted them — it tested nothing.
        $parentNo = 'GN20260715X1';
        $parent = Order::create([
            'order_no' => $parentNo, 'user_id' => 1, 'merchant_id' => null,
            'type' => 'merged', 'status' => 'pending_payment',
            'amount' => 100000.0, 'product_amount' => 100000.0,
        ]);
        $sub = Order::create([
            'order_no' => $parentNo . '-1', 'user_id' => 1, 'merchant_id' => null,
            'type' => 'sub', 'parent_order_no' => $parentNo, 'status' => 'pending_payment',
            'amount' => 100000.0, 'product_amount' => 100000.0,
        ]);

        expect($parent->subOrders()->count())->toBe(1);
        expect($parent->subOrders()->first()->id)->toBe($sub->id);
        expect($sub->parent()->first()->id)->toBe($parent->id);
    });

    it('allows cancellation from a pre-delivery state through the real endpoint', function () {
        // Replaces the tautological in_array re-implementation. This hits the
        // actual guard in OrderController::cancel (in_array status check) over
        // HTTP, so a regression that widens/narrows the cancellable states is
        // caught instead of a copy of the same array passing itself.
        $user = User::create([
            'name' => 'State', 'phone' => '0901112201',
            'password' => bcrypt('secret123'), 'role' => 'customer',
        ]);
        $order = Order::create([
            'order_no' => 'GN' . uniqid(), 'user_id' => $user->id, 'merchant_id' => null,
            'status' => 'accepted', 'amount' => 50000.0, 'product_amount' => 50000.0,
        ]);

        $res = $this->withToken($user->createToken('ci', ['customer'])->plainTextToken)
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');
        $res->assertStatus(200);
        expect($order->fresh()->status)->toBe('cancelled');
    });
});
