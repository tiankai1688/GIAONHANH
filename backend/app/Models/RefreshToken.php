<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $fillable = [
        'user_id', 'token_hash', 'ability', 'expires_at', 'revoked', 'replaced_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
        'replaced_by' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /** A token is usable only when not revoked and not expired. */
    public function isValid(): bool
    {
        return ! $this->revoked && ! $this->isExpired();
    }
}
