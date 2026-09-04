<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('contact_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('address');
            $table->string('logo')->nullable();

            // Onboarding / review
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('reject_reason')->nullable();

            // Policy (0 commission + delivery subsidy)
            $table->decimal('commission_rate', 5, 4)->default(0); // always 0
            $table->boolean('delivery_subsidy')->default(true);   // platform pays delivery

            // Storefront
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('rating', 2, 1)->default(5.0);
            $table->integer('avg_delivery_min')->default(35);
            $table->decimal('min_order', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0); // 0 because subsidized
            $table->boolean('is_open')->default(true);
            $table->string('business_hours')->nullable(); // e.g. 08:00-22:00
            $table->integer('monthly_sales')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
