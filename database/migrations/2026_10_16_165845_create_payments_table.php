<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            // Our own unguessable reference, sent to the gateway as the transaction reference (tx_ref on Flutterwave).
            $table->string('reference', 32)->unique();
            // pending | succeeded | failed | abandoned | review (money received but not safe to fulfil automatically)
            $table->string('state', 16)->default('pending');
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('gateway_transaction_id', 40)->nullable();
            $table->string('gateway_status', 40)->nullable();
            $table->string('channel', 40)->nullable();
            $table->text('authorization_url')->nullable();
            $table->string('failure_reason', 190)->nullable();
            $table->string('review_reason', 60)->nullable();
            $table->unsignedBigInteger('refunded_kobo')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'created_at']);
            $table->index(['order_id', 'state']);
            $table->index(['gateway', 'gateway_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
