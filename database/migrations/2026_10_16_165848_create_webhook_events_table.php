<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway', 20);
            // Hash of gateway + event + stable ids + status: the same delivery (or a retry) maps to the same row.
            $table->char('dedupe_key', 40)->unique();
            $table->string('event_type', 60);
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 40)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
