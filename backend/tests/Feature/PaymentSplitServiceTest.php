<?php

use App\Services\PaymentSplitService;

/*
 * Money split is the single source of truth for GIAONHANH's
 * 0%-commission + platform-delivery-subsidy model.
 */
describe('PaymentSplitService', function () {
    it('keeps 100% of product value for the merchant at 0% commission', function () {
        $svc = new PaymentSplitService(commissionRate: 0.0, deliverySubsidyEnabled: true);
        $m = new \App\Models\Merchant(['delivery_fee' => 15000.0, 'delivery_subsidy' => true]);
        $order = new \App\Models\Order();

        $svc->apply($order, $m, [
            ['price' => 100000.0, 'qty' => 1],
            ['price' => 50000.0, 'qty' => 2],
            '_coupon_discount' => 0,
        ]);

        expect((float) $order->product_amount)->toBe(200000.0);
        expect((float) $order->commission)->toBe(0.0);
        expect((float) $order->merchant_settlement)->toBe(200000.0); // full value, no commission
    });

    it('charges the customer 0 delivery and books the subsidy when subsidized', function () {
        $svc = new PaymentSplitService(commissionRate: 0.0, deliverySubsidyEnabled: true);
        $m = new \App\Models\Merchant(['delivery_fee' => 15000.0, 'delivery_subsidy' => true]);
        $order = new \App\Models\Order();

        $svc->apply($order, $m, [['price' => 80000.0, 'qty' => 1], '_coupon_discount' => 0]);

        expect((float) $order->delivery_fee)->toBe(15000.0);
        expect((float) $order->amount)->toBe(80000.0);           // customer pays no delivery
        expect((float) $order->platform_subsidy)->toBe(15000.0);   // platform eats delivery
    });

    it('passes the delivery fee to the customer when subsidy is off', function () {
        $svc = new PaymentSplitService(commissionRate: 0.0, deliverySubsidyEnabled: true);
        $m = new \App\Models\Merchant(['delivery_fee' => 15000.0, 'delivery_subsidy' => false]);
        $order = new \App\Models\Order();

        $svc->apply($order, $m, [['price' => 80000.0, 'qty' => 1], '_coupon_discount' => 0]);

        expect((float) $order->amount)->toBe(95000.0);          // 80k + 15k delivery
        expect((float) $order->platform_subsidy)->toBe(0.0);
        expect((float) $order->merchant_settlement)->toBe(95000.0); // merchant gets the delivery too
    });

    it('funds the new-user coupon from the platform, not the merchant', function () {
        $svc = new PaymentSplitService(commissionRate: 0.0, deliverySubsidyEnabled: true);
        $m = new \App\Models\Merchant(['delivery_fee' => 0.0, 'delivery_subsidy' => true]);
        $order = new \App\Models\Order();

        $svc->apply($order, $m, [['price' => 100000.0, 'qty' => 1], '_coupon_discount' => 50000]);

        expect((float) $order->coupon_discount)->toBe(50000.0);
        expect((float) $order->amount)->toBe(50000.0);          // 100k - 50k coupon
        expect((float) $order->merchant_settlement)->toBe(100000.0); // merchant still keeps full value
        expect((float) $order->platform_subsidy)->toBe(50000.0);  // coupon is platform-funded
    });

    it('computes a sane haversine distance between two Hanoi points', function () {
        $d = PaymentSplitService::distance(21.0285, 105.8542, 21.0125, 105.8550);
        expect($d)->toBeGreaterThan(1.5);
        expect($d)->toBeLessThan(2.5);
    });
});
