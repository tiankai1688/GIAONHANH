<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'method', 'amount', 'status',
        'gateway', 'gateway_order_id', 'trans_id', 'pay_url', 'raw', 'paid_at',
        'psp_fee',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'psp_fee' => 'decimal:2',
        'raw' => 'array',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
