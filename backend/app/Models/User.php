<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'role', 'lat', 'lng',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'email_verified_at' => 'datetime',
    ];

    public function isRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class);
    }

    public function rider()
    {
        return $this->hasOne(Rider::class);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
