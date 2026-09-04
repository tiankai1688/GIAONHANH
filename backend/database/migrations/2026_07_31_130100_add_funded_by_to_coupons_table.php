<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who bears the discount: 'merchant' (created by a merchant — reduces that
     * merchant's settlement) or 'platform' (new-user / marketing coupon —
     * platform-funded, does not reduce merchant settlement). Defaults to
     * 'merchant' since merchants create these coupons in the M console.
     */
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('funded_by')->default('merchant')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('funded_by');
        });
    }
};
