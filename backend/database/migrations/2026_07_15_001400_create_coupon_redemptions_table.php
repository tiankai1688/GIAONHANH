<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * V2 (anti 套补贴): persist coupon redemptions so the same user can never
     * reuse a platform coupon. The unique (user_id, coupon_code) index is the
     * hard guarantee that backs OrderController::resolveServerCoupon().
     */
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('coupon_code');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['user_id', 'coupon_code']); // one redemption per user per coupon
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
