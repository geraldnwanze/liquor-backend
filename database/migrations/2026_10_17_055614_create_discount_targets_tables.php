<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_product', function (Blueprint $table) {
            $table->foreignUuid('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['discount_id', 'product_id']);
        });

        Schema::create('discount_category', function (Blueprint $table) {
            $table->foreignUuid('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['discount_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_category');
        Schema::dropIfExists('discount_product');
    }
};
