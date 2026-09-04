<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // V2: enforce password NOT NULL on existing rows (set a random throwaway
        // hash for any legacy NULL passwords so they can never be used for login).
        DB::table('users')
            ->whereNull('password')
            ->update(['password' => bcrypt(bin2hex(random_bytes(32)))]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }
};
