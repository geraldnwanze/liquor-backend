<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when a customer's personal details have been erased. The account row stays (orders point at it).
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('anonymized_at')->nullable()->after('suspension_reason'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
    }
};
