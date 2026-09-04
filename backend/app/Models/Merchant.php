<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'category_id', 'name', 'contact_name', 'phone', 'email', 'address',
        'logo', 'status', 'reject_reason', 'commission_rate', 'delivery_subsidy',
        'lat', 'lng', 'rating', 'avg_delivery_min', 'min_order', 'delivery_fee',
        'is_open', 'business_hours', 'monthly_sales',
        'business_license', 'bank_account', 'kyc_status', 'kyc_reject_reason',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:4',
        'delivery_subsidy' => 'boolean',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'rating' => 'decimal:1',
        'min_order' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'is_open' => 'boolean',
        'bank_account' => 'encrypted', // PII: settlement bank account — encrypted at rest (P0#3)
    ];

    // SECURITY (2026-08-01): bank_account is PII (settlement bank details). It is
    // accepted as input (merchant onboarding / profile update) but must NEVER be
    // serialized in any API response — admin merchant list (raw paginate) and
    // public/merchant endpoints would otherwise leak it. The merchant views it
    // only via the web console; no read endpoint returns it.
    protected $hidden = [
        'bank_account',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(MerchantPayout::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')->where('is_open', true);
    }
}
