<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a platform-coupon redemption. The unique (user_id, coupon_code)
 * constraint enforces "one redemption per user per coupon" (V2 anti 套补贴).
 */
class CouponRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'coupon_code', 'coupon_id', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
