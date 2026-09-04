<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('business_license')->nullable()->after('email');
            $table->string('bank_account')->nullable()->after('business_license');
            $table->string('kyc_status')->default('pending')->after('bank_account');
            $table->string('kyc_reject_reason')->nullable()->after('kyc_status');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['business_license', 'bank_account', 'kyc_status', 'kyc_reject_reason']);
        });
    }
};
