<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a redemption record to the actual Coupon row (the existing
     * (user_id, coupon_code) unique index already prevents reuse).
     */
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->foreignId('coupon_id')
                ->nullable()
                ->after('coupon_code')
                ->constrained('coupons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn('coupon_id');
        });
    }
};
