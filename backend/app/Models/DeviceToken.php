<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Push-notification device tokens per user. A user may have several devices
 * (phone + tablet). Stored so the platform can fan out FCM / APNs pushes when
 * a new order arrives or an order becomes available for grab.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id', 'role', 'token', 'platform', 'device_name', 'locale',
    ];

    protected $hidden = [
        'token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
