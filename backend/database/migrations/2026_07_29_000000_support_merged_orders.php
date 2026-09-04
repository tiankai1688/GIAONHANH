<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 — 跨店合并下单 (cross-store merged order).
 *
 * A "merged" order is a parent Order (type='merged', merchant_id=null) that
 * groups several per-merchant sub-orders (type='sub', parent_order_no set).
 * The killer feature: ONE delivery, ONE delivery fee (platform-subsidized -> 0
 * to the customer, paid 100% to the rider out of marketing budget). Money is
 * split per-merchant (0 commission) while the customer pays a single total.
 *
 * NOTE: ->change() requires the doctrine/dbal package (composer require
 * doctrine/dbal) which is standard for Laravel 11 projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // single | merged (parent) | sub (child of a merged order)
            $table->string('type')->default('single')->after('order_no');
            // order_no of the parent merged order (set on child sub-orders)
            $table->string('parent_order_no')->nullable()->after('merchant_id');
            // the single flat delivery fee for the whole merged order
            $table->decimal('group_delivery_fee', 12, 2)->nullable()->after('delivery_fee');
            // merged parent has no single merchant
            $table->foreignId('merchant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['type', 'parent_order_no', 'group_delivery_fee']);
            $table->foreignId('merchant_id')->nullable(false)->change();
        });
    }
};
