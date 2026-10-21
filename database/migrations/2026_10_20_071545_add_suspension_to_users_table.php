<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why and when a customer account was suspended (`is_active` stays the switch). Cleared on reactivation.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->string('suspension_reason', 300)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'suspension_reason']));
    }
};
