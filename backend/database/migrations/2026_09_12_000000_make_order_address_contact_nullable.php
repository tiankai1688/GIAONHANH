<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * address / contact_name / contact_phone are validated at the request layer
     * (CreateOrderRequest) but must NOT be hard NOT NULL constraints at the DB
     * layer: merged parent orders (type=merged) carry merchant_id=null and no
     * delivery address, and order rows created outside the API path (tests,
     * admin tools, reconciliation) legitimately omit them. Leaving them NOT NULL
     * makes every such insert throw SQLSTATE[23000], breaking 21 Pest tests.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('address')->nullable()->change();
            $table->string('contact_name')->nullable()->change();
            $table->string('contact_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('address')->nullable(false)->change();
            $table->string('contact_name')->nullable(false)->change();
            $table->string('contact_phone')->nullable(false)->change();
        });
    }
};
