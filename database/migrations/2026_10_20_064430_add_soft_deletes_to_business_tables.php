<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Business records that staff or customers can delete. Deleting hides a row; it never removes it. */
    private const TABLES = [
        'products', 'product_variants', 'product_images', 'brands', 'categories', 'delivery_zones', 'discounts',
        'pages', 'newsletter_campaigns', 'reviews', 'review_images', 'customer_notes', 'addresses', 'wishlist_items',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
