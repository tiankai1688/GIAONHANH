<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no', 'user_id', 'merchant_id', 'rider_id', 'type', 'parent_order_no', 'group_delivery_fee',
        'product_amount', 'delivery_fee', 'coupon_id', 'coupon_discount', 'platform_subsidy', 'commission',
        'amount', 'merchant_settlement', 'status', 'delivery_type', 'expect_time',
        'psp_fee', 'psp_fee_bearer',
        'pay_method', 'address', 'lat', 'lng', 'contact_name', 'contact_phone', 'note',
        'paid_at', 'accepted_at', 'picked_at', 'delivering_at', 'delivered_at',
        'refunded_at', 'refund_error',
    ];

    /**
     * Route-model binding uses the natural order number (e.g. GN20260715A1)
     * instead of the auto-increment id, so all /orders/{order} endpoints
     * accept the human-readable order_no that the front-end already holds.
     */
    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    protected $casts = [
        'type' => 'string',
        'product_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'group_delivery_fee' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
        'platform_subsidy' => 'decimal:2',
        'commission' => 'decimal:2',
        'amount' => 'decimal:2',
        'merchant_settlement' => 'decimal:2',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'expect_time' => 'datetime',
        'paid_at' => 'datetime',
        'accepted_at' => 'datetime',
        'picked_at' => 'datetime',
        'delivering_at' => 'datetime',
        'delivered_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Child sub-orders of a merged parent (linked by order_no, not id).
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_no', 'order_no');
    }

    /**
     * The parent merged order of a child sub-order (null for top-level orders).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_no', 'order_no');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public static function trackingSteps(): array
    {
        return ['pending_payment', 'paid', 'accepted', 'picked', 'delivering', 'delivered'];
    }
}
