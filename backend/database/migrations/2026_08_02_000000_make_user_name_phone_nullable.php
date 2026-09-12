<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0#4 — right-to-erasure (PDPD Art.9 / GDPR Art.17).
 *
 * AuthController::destroyAccount() anonymizes the account by NULLing its direct
 * PII (name / phone). Those two columns were NOT NULL, so the wipe threw
 * `SQLSTATE[23000]: NOT NULL constraint failed: users.name` and the whole
 * account-deletion regression test crashed.
 *
 * Fix: allow NULL on name + phone. The user row is RETAINED (soft-deleted) for
 * the statutory audit/retention period, so wiping the identifying fields is the
 * correct behaviour. `phone` keeps its UNIQUE index — SQLite/MySQL both permit
 * multiple NULLs under a unique constraint, so two anonymized accounts never
 * collide.
 *
 * Uses ->change(), which requires the doctrine/dbal package (in require).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
        });
    }
};
