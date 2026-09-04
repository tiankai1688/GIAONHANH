<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add revenue-share % and managed-merchant count so the admin
     * "Quản lý đại lý" screen can render real agent records
     * (previously demo-only: AGENTS const in admin.html).
     */
    public function up(): void
    {
        Schema::table('agent_applications', function (Blueprint $table) {
            $table->decimal('share_rate', 5, 2)->default(8)->comment('revenue share %');
            $table->unsignedInteger('merchants_count')->default(0)->comment('managed merchants');
        });
    }

    public function down(): void
    {
        Schema::table('agent_applications', function (Blueprint $table) {
            $table->dropColumn(['share_rate', 'merchants_count']);
        });
    }
};
