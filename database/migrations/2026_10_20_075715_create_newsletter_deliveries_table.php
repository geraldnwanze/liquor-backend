<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person per campaign: queued when the send is requested, then sent or failed.
        Schema::create('newsletter_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('newsletter_campaigns')->cascadeOnDelete();
            $table->foreignUuid('subscriber_id')->nullable()->constrained('newsletter_subscribers')->nullOnDelete();
            $table->string('email', 190);
            $table->string('status', 12)->default('queued');
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'subscriber_id']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_deliveries');
    }
};
