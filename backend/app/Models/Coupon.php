<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'code', 'name', 'name_zh', 'type', 'value', 'funded_by',
        'min_order', 'status', 'used_count', 'usage_limit', 'start_at', 'end_at',
    ];

    protected $casts = [
        'value'        => 'decimal:2',
        'min_order'    => 'decimal:2',
        'used_count'   => 'integer',
        'usage_limit'  => 'integer',
        'start_at'     => 'datetime',
        'end_at'       => 'datetime',
        'status'       => 'string',
        'type'         => 'string',
        'funded_by'    => 'string',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Discount amount this coupon yields for a given subtotal.
     * cash: fixed value (capped to subtotal); percent: value% of subtotal.
     */
    public function applyTo(float $subtotal): float
    {
        $discount = $this->type === 'percent'
            ? round($subtotal * ((float) $this->value / 100), 2)
            : (float) $this->value;

        return min($discount, $subtotal);
    }

    /**
     * Validate this coupon for a merchant + subtotal.
     * Returns null if valid, or a Vietnamese error message string.
     */
    public function isValidFor(int $merchantId, float $subtotal): ?string
    {
        if ($this->merchant_id !== $merchantId) {
            return 'Mã không thuộc cửa hàng này.';
        }
        if ($this->status !== 'active') {
            return 'Mã đã bị tắt.';
        }
        $now = now();
        if ($this->start_at && $now->lt($this->start_at)) {
            return 'Mã chưa hiệu lực.';
        }
        if ($this->end_at && $now->gt($this->end_at)) {
            return 'Mã đã hết hạn.';
        }
        if ($subtotal < (float) $this->min_order) {
            return 'Chưa đạt mức tối thiểu.';
        }
        if ($this->used_count >= $this->usage_limit) {
            return 'Mã đã hết lượt sử dụng.';
        }
        return null;
    }

    /**
     * Generate a unique, human-friendly coupon code (GN + 8 alphanumerics).
     */
    public static function generateCode(): string
    {
        do {
            $code = 'GN' . strtoupper(\Illuminate\Support\Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
