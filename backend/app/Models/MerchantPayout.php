<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records an actual disbursement to a merchant for a T+1 settlement day.
 * Distinct from MerchantSettlementAck (merchant's confirmation of the
 * statement) — this is the platform's record that money left the platform.
 */
class MerchantPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'settle_date', 'amount', 'method',
        'reference', 'status', 'admin_id', 'note', 'paid_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'settle_date' => 'date',
        'paid_at'     => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
