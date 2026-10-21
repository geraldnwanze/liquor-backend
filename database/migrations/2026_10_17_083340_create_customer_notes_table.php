<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Internal support notes about a customer. Customers never see these.
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
