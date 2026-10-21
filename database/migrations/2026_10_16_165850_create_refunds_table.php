<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // One admin action = one key, so a double click cannot refund twice.
            $table->string('idempotency_key', 64);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('reason', 255);
            // pending | processed | failed
            $table->string('state', 12)->default('pending');
            $table->string('gateway_refund_id', 60)->nullable();
            $table->string('failure_reason', 190)->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'idempotency_key']);
            $table->index(['payment_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
