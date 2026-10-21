<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            // initialize | verify | webhook | reconcile | refund
            $table->string('type', 16);
            // ok | error | mismatch | ignored
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('gateway_status', 40)->nullable();
            // A short, sanitised note. Never request bodies, headers, keys or card data.
            $table->string('detail', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
