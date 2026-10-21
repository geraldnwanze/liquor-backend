<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            // Who did it; null for the system (payment confirmed by a gateway, reservation expired).
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // placed | paid | expired | cancelled | fulfilment | shipment | note | refund
            $table->string('type', 24);
            $table->string('from_value', 30)->nullable();
            $table->string('to_value', 30)->nullable();
            $table->string('note', 1000)->nullable();
            // Only these appear on the customer's tracking timeline. Notes and internal changes never do.
            $table->boolean('customer_visible')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
