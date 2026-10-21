<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('discount_id')->constrained()->restrictOnDelete();
            // One redemption per order.
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Lower-cased checkout email: how guests are recognised for the per-customer limit.
            $table->string('email', 190);
            // Item discount given, and delivery fee waived, both in kobo.
            $table->unsignedBigInteger('amount_kobo')->default(0);
            $table->unsignedBigInteger('delivery_waived_kobo')->default(0);
            // reserved (order awaiting payment) | redeemed (paid) | released (order cancelled or expired)
            $table->string('state', 10)->default('reserved');
            $table->timestamps();
            $table->index(['discount_id', 'state']);
            $table->index(['discount_id', 'email', 'state']);
            $table->index(['discount_id', 'user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
    }
};
