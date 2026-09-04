<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'platform' => 'GIAONHANH',
        'tagline'  => 'Viet Nam on-demand delivery',
        'api'      => '/api',
        'status'   => 'ok',
    ]);
});
