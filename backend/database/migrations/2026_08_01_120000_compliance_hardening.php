<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance hardening (2026-08-01) — closes several P0 items from the
 * pre-launch compliance audit (docs/compliance-audit-2026-08-01.md):
 *   - widen id_card / bank_account so encrypted ciphertext is never truncated
 *   - soft-delete support for user accounts (data-subject deletion / retention)
 *   - record PSP (MoMo/ZaloPay) acquirer fee on each order so the 0-commission
 *     model no longer hides a real cost (unit-economics blind spot)
 *
 * Requires doctrine/dbal (in require) for the ->change() calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->string('id_card', 512)->nullable()->change();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('bank_account', 512)->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('psp_fee', 12, 2)->default(0);
            $table->string('psp_fee_bearer')->nullable(); // 'platform' | 'merchant'
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->string('id_card', 512)->nullable()->change();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('bank_account', 512)->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['psp_fee', 'psp_fee_bearer']);
        });
    }
};
