<?php

use App\Models\Merchant;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| GIAONHANH real-time channels:
|  - merchant.{id} : private, the merchant who owns the shop
|  - orders.grab   : public, fired when an order is ready for a rider to grab
|                     (riders filter by distance client-side)
|
*/

Broadcast::channel('merchant.{id}', function ($user, $id) {
    return (bool) ($user->merchant && $user->merchant->id == $id);
});

Broadcast::channel('orders.grab', function () {
    return true; // public broadcast; client filters by distance
});
