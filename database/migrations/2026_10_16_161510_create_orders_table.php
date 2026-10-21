<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Public, unguessable identifier. The numeric id is never exposed.
            $table->string('reference', 16)->unique();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('pending_payment');
            $table->string('payment_status', 20)->default('pending');
            $table->char('currency', 3)->default('NGN');
            $table->unsignedBigInteger('subtotal_kobo');
            $table->unsignedBigInteger('discount_kobo')->default(0);
            $table->unsignedBigInteger('delivery_fee_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo');
            $table->string('customer_name', 120);
            $table->string('email', 190);
            $table->string('phone', 20);
            $table->foreignUuid('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_zone_name');
            $table->string('delivery_state', 80);
            $table->string('delivery_city', 80);
            $table->string('delivery_address', 255);
            $table->string('delivery_instructions', 500)->nullable();
            $table->timestamp('age_confirmed_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            // Keys are scoped to the cart, so one customer can never replay another's key.
            $table->unique(['cart_id', 'idempotency_key']);
            $table->index(['status', 'reservation_expires_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
