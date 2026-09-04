<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'category_id', 'name_vi', 'name_zh', 'description', 'price',
        'original_price', 'image', 'stock', 'is_flash', 'flash_price', 'flash_stock',
        'flash_start', 'flash_end', 'sales', 'status', 'sort',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'flash_price' => 'decimal:2',
        'is_flash' => 'boolean',
        'flash_start' => 'datetime',
        'flash_end' => 'datetime',
        'status' => 'string',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeOnSale($query)
    {
        return $query->where('status', 'on')->where('stock', '>', 0);
    }

    public function scopeFlash($query)
    {
        $now = now();
        return $query->where('is_flash', true)
            ->where('flash_stock', '>', 0)
            ->where('flash_start', '<=', $now)
            ->where('flash_end', '>=', $now);
    }

    public function effectivePrice(): float
    {
        if ($this->is_flash && $this->flash_price !== null) {
            return (float) $this->flash_price;
        }
        return (float) $this->price;
    }
}
