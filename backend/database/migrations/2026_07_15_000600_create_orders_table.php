<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('riders')->nullOnDelete();

            // Money (VND, integer-safe decimals)
            $table->decimal('product_amount', 14, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('coupon_discount', 12, 2)->default(0);
            $table->decimal('platform_subsidy', 12, 2)->default(0); // what platform eats
            $table->decimal('commission', 12, 2)->default(0);        // always 0
            $table->decimal('amount', 14, 2)->default(0);            // customer pays
            $table->decimal('merchant_settlement', 14, 2)->default(0); // merchant receives

            $table->string('status')->default('pending_payment');
            // pending_payment | paid | accepted | picked | delivering | delivered | cancelled

            // Delivery
            $table->string('delivery_type')->default('instant'); // instant | appointment
            $table->timestamp('expect_time')->nullable();
            $table->string('pay_method')->default('cod');        // momo | zalopay | cod
            $table->text('address');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->text('note')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('delivering_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
