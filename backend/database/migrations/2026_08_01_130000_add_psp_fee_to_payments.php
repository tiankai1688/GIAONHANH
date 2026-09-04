<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the PSP (MoMo/ZaloPay) acquirer fee on each Payment row, mirroring
 * orders.psp_fee (added in 2026_08_01_120000). Lets reconciliation / finance
 * report the true gateway cost per transaction instead of leaving it invisible
 * (red-team boss #1 — unit-economics blind spot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('psp_fee', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('psp_fee');
        });
    }
};
