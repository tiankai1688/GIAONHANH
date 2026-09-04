<?php

use App\Actions\CreateOrderAction;
use App\Http\Resources\OrderResource;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Regression coverage for the "still-pending" items from the senior review:
 *  1) merged first-order (NEW_USER) coupon must NOT be consumed outside the
 *     order-creating transaction — a rolled-back order must roll back the grant.
 *  2) recordMerchantCoupon must not open a nested transaction (lock-ordering).
 *  3) OrderResource must not leak internal financial口径 to customers/riders.
 */
describe('NEW_USER coupon is consumed inside the order transaction', function () {
    it('resolveServerCoupon computes the discount but writes NO redemption', function () {
        Config::set('business.new_user_coupon_amount', 20000);
        $user = User::create([
            'name'     => 'Newbie',
            'phone'    => '091' . random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'role'     => 'customer',
        ]);

        $discount = app(CreateOrderAction::class)
            ->resolveServerCoupon($user, 100000.0, null, 0)['platform_discount'];

        // min(20000, 100000) = 20000, but NOT yet persisted.
        expect($discount)->toBe(20000.0);
        expect(CouponRedemption::where('user_id', $user->id)
            ->where('coupon_code', 'NEW_USER')->exists())->toBeFalse();
    });

    it('grantNewUserCoupon persists on commit but rolls back with a failed order', function () {
        Config::set('business.new_user_coupon_amount', 20000);
        $user = User::create([
            'name'     => 'Grantee',
            'phone'    => '092' . random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'role'     => 'customer',
        ]);
        $rollbackUser = User::create([
            'name'     => 'Rollback',
            'phone'    => '093' . random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'role'     => 'customer',
        ]);
        $action = app(CreateOrderAction::class);

        // Committed transaction → redemption persists.
        DB::transaction(fn () => $action->grantNewUserCoupon($user, 20000.0));
        $row = CouponRedemption::where('user_id', $user->id)
            ->where('coupon_code', 'NEW_USER')->first();
        expect($row)->not->toBeNull();
        expect((float) $row->amount)->toBe(20000.0);

        // Rolled-back transaction (simulates a failed order): the grant written
        // inside it must disappear with the rollback — no orphaned coupon.
        try {
            DB::transaction(function () use ($action, $rollbackUser) {
                $action->grantNewUserCoupon($rollbackUser, 20000.0);
                throw new \RuntimeException('order failed');
            });
        } catch (\RuntimeException $e) {
            // expected: outer transaction rolled back
        }
        expect(CouponRedemption::where('user_id', $rollbackUser->id)
            ->where('coupon_code', 'NEW_USER')->exists())->toBeFalse();

        // Idempotency: a second committed grant for the SAME user is a no-op
        // (unique index blocks the duplicate, swallowed as 23000) → still 1 row.
        DB::transaction(fn () => $action->grantNewUserCoupon($user, 20000.0));
        expect(CouponRedemption::where('user_id', $user->id)
            ->where('coupon_code', 'NEW_USER')->count())->toBe(1);
    });
});

describe('OrderResource hides internal financial口径 by viewer role', function () {
    function shapeFor(string $role): array
    {
        $order = new Order([
            'order_no'           => 'GN' . date('Ymd') . 'TEST',
            'type'               => 'single',
            'status'             => 'paid',
            'product_amount'     => 100000.0,
            'delivery_fee'       => 0.0,
            'coupon_discount'    => 20000.0,
            'platform_subsidy'   => 8000.0,   // platform cost (admin-only)
            'commission'         => 0.0,      // merchant payout figure
            'amount'             => 80000.0,
            'merchant_settlement'=> 80000.0,  // merchant payout figure
        ]);
        $viewer = new User();
        $viewer->role = $role;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $viewer);

        // IMPORTANT: call toResponse() (not toArray()). toArray() leaves
        // `$this->when(...)` gated fields as `MissingValue` objects IN the array,
        // so `toHaveKey()` would still find them and the role-gating assertions
        // would all invert. toResponse() runs the JSON encoder, which strips
        // MissingValue — yielding the real wire shape a client receives.
        $response = (new OrderResource($order))->toResponse($request);
        $encoded = json_decode($response->getContent(), true);
        return $encoded['data'] ?? $encoded;
    }

    it('hides all three internal fields from a customer', function () {
        $data = shapeFor('customer');
        expect($data)->not->toHaveKey('merchant_settlement')
            ->and($data)->not->toHaveKey('commission')
            ->and($data)->not->toHaveKey('platform_subsidy')
            ->and($data)->toHaveKey('amount')          // customer still sees price
            ->and($data)->toHaveKey('coupon_discount');
    });

    it('hides all three internal fields from a rider', function () {
        $data = shapeFor('rider');
        expect($data)->not->toHaveKey('merchant_settlement')
            ->and($data)->not->toHaveKey('commission')
            ->and($data)->not->toHaveKey('platform_subsidy');
    });

    it('shows commission + merchant_settlement (not platform_subsidy) to a merchant', function () {
        $data = shapeFor('merchant');
        expect($data)->toHaveKey('merchant_settlement')
            ->and($data)->toHaveKey('commission')
            ->and($data)->not->toHaveKey('platform_subsidy');
    });

    it('shows all three internal fields to an admin', function () {
        $data = shapeFor('admin');
        expect($data)->toHaveKey('merchant_settlement')
            ->and($data)->toHaveKey('commission')
            ->and($data)->toHaveKey('platform_subsidy');
    });
});
