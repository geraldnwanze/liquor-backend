<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            // Own rider, a courier company, a dispatch rider: whoever is carrying the order.
            $table->string('carrier', 80)->nullable();
            $table->string('tracking_reference', 80)->nullable();
            // The carrier's contact number. Staff-only: not shown to customers.
            $table->string('carrier_phone', 20)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
