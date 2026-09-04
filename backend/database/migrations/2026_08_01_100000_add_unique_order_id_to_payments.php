<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defense-in-depth against double charges: one Payment row per Order.
     * PaymentController::pay() already reuses an existing row under a row lock,
     * but this unique index is the hard guarantee that a concurrent re-click
     * (or any other code path) can never insert a second chargeable payment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });
    }
};
