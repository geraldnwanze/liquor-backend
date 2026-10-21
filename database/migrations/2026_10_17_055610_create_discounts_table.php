<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // What customers type. Stored upper-case; matching is case-insensitive.
            $table->string('code', 32)->unique();
            // Internal label for staff ("October welcome offer").
            $table->string('name', 120);
            // percentage | fixed | free_delivery
            $table->string('type', 16);
            // Percentage (1-100) for "percentage", kobo for "fixed", unused for "free_delivery".
            $table->unsignedBigInteger('value')->default(0);
            // Ceiling on a percentage discount, in kobo.
            $table->unsignedBigInteger('max_discount_kobo')->nullable();
            // Checked against the eligible items' subtotal.
            $table->unsignedBigInteger('min_order_kobo')->nullable();
            // order | products | categories
            $table->string('scope', 12)->default('order');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
