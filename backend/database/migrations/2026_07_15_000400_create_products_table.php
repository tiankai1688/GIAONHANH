<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name_vi');
            $table->string('name_zh');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('original_price', 12, 2)->nullable();
            $table->string('image')->nullable();
            $table->integer('stock')->default(999);

            // Flash sale
            $table->boolean('is_flash')->default(false);
            $table->decimal('flash_price', 12, 2)->nullable();
            $table->integer('flash_stock')->nullable();
            $table->timestamp('flash_start')->nullable();
            $table->timestamp('flash_end')->nullable();

            $table->integer('sales')->default(0);
            $table->string('status')->default('on'); // on | off
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
