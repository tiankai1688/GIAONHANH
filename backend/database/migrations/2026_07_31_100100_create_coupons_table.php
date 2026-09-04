<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant-issued coupons (cash / percent). Closes the M-Web
     * "Mã giảm giá cửa hàng" gap (previously demo-only MCOUPONS const).
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_zh')->nullable();
            $table->string('type')->default('cash'); // cash | percent
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('min_order', 12, 2)->default(0);
            $table->string('status')->default('active'); // active | paused
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
