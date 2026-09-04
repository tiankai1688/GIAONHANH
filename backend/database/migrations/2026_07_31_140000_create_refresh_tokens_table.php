<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh tokens (rotation) for access-token expiry + silent re-auth.
 * Access tokens are short-lived (2h, enforced by Sanctum via expires_at on
 * personal_access_tokens). This table holds the longer-lived (30d) refresh
 * tokens, stored hashed, supporting rotation + single-active session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 128)->unique();
            $table->string('ability', 32)->default('customer');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('revoked')->default(false);
            $table->unsignedBigInteger('replaced_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
