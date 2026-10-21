<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // unfulfilled | processing | out_for_delivery | delivered | delivery_failed. Only paid orders move.
            $table->string('fulfilment_status', 20)->default('unfulfilled')->after('payment_status');
            $table->index(['status', 'fulfilment_status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'fulfilment_status']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('fulfilment_status');
        });
    }
};
