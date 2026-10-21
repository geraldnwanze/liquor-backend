<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            // Set for uploaded images (the file's location on the images disk); null for images linked by URL.
            $table->string('path')->nullable()->after('url');
            $table->string('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
