<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant payout ledger.
 *
 * Settlements are still *computed* on the fly by MerchantSettlementService
 * (T+1 from orders.merchant_settlement). This table records the *actual*
 * disbursement of those computed amounts — the missing "real payout" step.
 * One payout row per (merchant, settle_date) keeps the ledger idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->date('settle_date');                 // business day settled (T+1)
            $table->decimal('amount', 14, 2);            // disbursed amount (VND)
            $table->string('method')->default('bank');   // bank | momo | zalopay | manual
            $table->string('reference')->nullable();     // bank txn id / PSP reference
            $table->string('status')->default('paid');   // paid | failed | reversed
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->unique(['merchant_id', 'settle_date']); // one payout per merchant per day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payouts');
    }
};
