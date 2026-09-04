<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('method');        // momo | zalopay | cod
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending'); // pending | success | failed
            $table->string('gateway')->nullable();        // momo | zalopay
            $table->string('gateway_order_id')->nullable();
            $table->string('trans_id')->nullable();
            $table->string('pay_url')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
