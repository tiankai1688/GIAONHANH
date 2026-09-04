<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'region', 'channel_desc', 'status', 'note',
        'share_rate', 'merchants_count',
    ];

    protected $casts = [
        'status' => 'string',
        'share_rate' => 'decimal:2',
        'merchants_count' => 'integer',
    ];
}
