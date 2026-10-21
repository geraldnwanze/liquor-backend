<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The code a shopper has applied. Re-checked on every cart read and again at checkout.
        Schema::table('carts', function (Blueprint $table) {
            $table->string('discount_code', 32)->nullable()->after('user_id');
        });

        // A snapshot of the code used, so history survives the discount being edited later.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('discount_code', 32)->nullable()->after('discount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('discount_code'));
        Schema::table('carts', fn (Blueprint $table) => $table->dropColumn('discount_code'));
    }
};
