<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSettlementAck extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'settle_date', 'period', 'status', 'ack_at', 'paid_at', 'note',
    ];

    protected $casts = [
        'ack_at'      => 'datetime',
        'paid_at'     => 'datetime',
        'settle_date' => 'date',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
