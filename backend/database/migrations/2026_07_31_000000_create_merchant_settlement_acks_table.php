<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant-side acknowledgement of a T+1 settlement statement.
     * Created when a merchant confirms a daily reconciliation (the platform
     * then proceeds to payout). Kept separate from the computed settlement
     * figures in MerchantSettlementService so writes never touch orders.
     */
    public function up(): void
    {
        Schema::create('merchant_settlement_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->date('settle_date');
            $table->string('period')->nullable()->comment('e.g. T+1');
            $table->string('status')->default('acknowledged'); // acknowledged | paid
            $table->timestamp('ack_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'settle_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_acks');
    }
};
